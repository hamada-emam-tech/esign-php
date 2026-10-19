# hamada-emam-tech/esign-boldsign

[BoldSign](https://boldsign.com) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php): e-signature documents over BoldSign API v1, with signed webhooks.

> **Status: built from BoldSign's official API documentation, not yet verified against a live account.**
> Every request and response shape here comes from developers.boldsign.com (see [Sources](#sources)). The [open questions](#open-questions) are the points the documentation leaves unclear. Please report anything that behaves differently on a real account.

```bash
vendor/bin/esign add boldsign          # or: composer require hamada-emam-tech/esign-boldsign
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically.

## Configure

```php
$esign = new Esign([
    'default'   => 'boldsign',
    'providers' => [
        'boldsign' => [
            'api_key'        => getenv('BOLDSIGN_API_KEY'),
            'region'         => 'us',                          // where your BoldSign account lives
            'webhook_secret' => getenv('BOLDSIGN_WEBHOOK_SECRET'),
        ],
    ],
]);
```

| Option | Default | Notes |
|---|---|---|
| `api_key` | required | BoldSign app → API → API Key. Sent in the `X-API-KEY` header. Sandbox and live keys are separate. |
| `region` | `us` | `us` → `https://api.boldsign.com`, `eu` → `https://api-eu.boldsign.com`, `ca` → `https://api-ca.boldsign.com`, `au` → `https://api-au.boldsign.com`. The wrong region answers 401. |
| `base_url` | from `region` | Overrides `region`. Must be `https://`. |
| `webhook_secret` | none | The signing secret of your webhook (webhook page → **Reveal**). **Without it every webhook is refused.** |
| `webhook_tolerance` | `300` | Seconds the signature timestamp may differ from now (BoldSign's own samples use 300). |
| `email_otp` | `false` | `true` gives `email` signers BoldSign's `EmailOTP` authentication instead of the link alone. |
| `expiry_days` | BoldSign's (60) | BoldSign's `ExpiryDays`. |
| `reminder_message` | `A reminder to sign this document.` | The `Message` that `remind()` sends. |
| `revoke_message` | `Cancelled by the sender.` | BoldSign requires a reason to revoke; `cancel()` sends this one. |
| `coordinate_scale` | `1.0` | Multiplies every field coordinate. See [Field positions](#field-positions). |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |

The API key never appears in an exception message or a `var_dump()`.

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, EnvelopeRef, Document, Signer, SignatureField, IdentityMethod};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf')],
    signers: [
        Signer::email('Ahmed Ali', 'ahmed@example.com', order: 1, fields: [new SignatureField(page: 1, x: 60, y: 700)]),
        new Signer('Sara', 'sara@example.com', IdentityMethod::SmsOtp, mobile: '+966 500000000', order: 2),
    ],
    subject: 'Please sign the agreement',          // BoldSign's Title
    message: 'Section 4 changed since last year.',  // BoldSign's Message
    metadata: ['contract_id' => '42'],              // BoldSign's MetaData (keys ≤ 50 chars, values ≤ 500)
));

$ref = (string) $sent->ref();    // "boldsign:<documentId>": store this one string
```

Every later call takes that string, so it always goes to the provider that created the document.

One `POST /v1/document/send` with a JSON body. How the envelope maps:

- **Files**: every document goes as `{"base64": "data:<mime>;base64,…", "fileName": "…"}`. BoldSign accepts PDF, PNG, JPG, DOCX, XLSX and PPTX; anything else is refused before the call.
- **Signers**: `Name`, `EmailAddress`, `SignerType: "Signer"`. When signers have different `order` values, `EnableSigningOrder` is `true` and each gets `SignerOrder`. When they all share one order they sign in parallel.
- **Identity**: `email` → no extra authentication (or `EmailOTP` with `email_otp`). `sms_otp` → `SMSOTP` with `PhoneNumber: {"CountryCode": "+966", "Number": "500000000"}`. Write the mobile with a space after the country code (`+966 500000000`): BoldSign wants the two apart, and they can't be split reliably otherwise. `nafath` is refused. All of this is checked before any HTTP call.
- **Fields**: each `SignatureField` becomes a `Signature` form field with `PageNumber` and `Bounds {X, Y, Width, Height}`, required. Fields can only go on the first document (see [open questions](#open-questions)). A signer without fields is sent without `FormFields`.
- `callbackUrl` is not used: BoldSign webhooks are configured per account or app, not per document.

**Sending is asynchronous.** BoldSign answers with a `documentId` straight away and processes the file afterwards. A `SendFailed` webhook reports a send that failed later, and `status()` can answer 404 for a moment right after `send()`.

`signingUrls` is empty: BoldSign emails the links. To let someone sign inside your app:

```php
/** @var \HamadaEmamTech\Esign\BoldSign\BoldSignProvider $boldsign */
$boldsign = $esign->provider('boldsign');
$url = $boldsign->signingLink(EnvelopeRef::parse($ref)->envelopeId, 'ahmed@example.com', redirectUrl: 'https://app.example.com/signed');
```

BoldSign's documentation states that you must verify the signer's identity yourself before you show an embedded link.

## Webhook, then status, then download

In BoldSign: API → Webhooks → add your URL, pick the document events (at least `Completed`, `Declined`, `Revoked`, `Expired`, `SendFailed`), then reveal the signing secret and put it in `webhook_secret`.

```php
// POST /webhooks/boldsign
$result = $esign->handleWebhook($request->headers->all(), $request->getContent(), 'boldsign');
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));    // do the rest in a queue
} else {
    logger()->warning('Bad BoldSign webhook', ['error' => $result->error?->getMessage()]);
}
return response($result->response->body, $result->response->status);   // always sent: also answers BoldSign's "Verification" ping

// In the job, with the stored $ref:
if ($esign->status($ref) === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);
}
```

`handleWebhook()` never throws. It calls the driver's `parseWebhook()`, which checks the `X-BoldSign-Signature` header (`t=<unix time>, s0=<hex>[, s1=<hex>]`):

1. No `webhook_secret` configured → refused (fails closed).
2. It computes HMAC-SHA256 of `"<t>.<raw body>"` with the secret and compares it in constant time to `s0` and `s1` (`s1` is signed with your previous secret while a rollover is in progress). No match → refused.
3. `t` more than `webhook_tolerance` seconds from now, in either direction → refused as a possible replay.
4. It returns only `data.documentId` (and `event.id` as the event ID). Events that aren't about a document (sender identity, templates) are refused. A request with `X-BoldSign-Event: Verification` (BoldSign's ping when you add the URL) comes back as not valid, and you answer it with the result's response like any other.

Pass the raw body exactly as received. Re-encoded JSON breaks the signature.

## Status mapping

`status()` reads `GET /v1/document/properties?documentId=…`:

| BoldSign document `status` | Signers (`signerDetails[].status`) | `EnvelopeStatus` |
|---|---|---|
| `InProgress` | none `Completed` | `Sent` |
| `InProgress` | some `Completed` | `PartiallySigned` |
| `Completed` | all `Completed` | `Signed` |
| `Completed` | some still `NotCompleted` (should not happen; handled defensively) | `PartiallySigned` / `Sent` |
| `Declined` | | `Declined` |
| `Revoked` | | `Cancelled` |
| `Expired` | | `Expired` |
| `WaitingForMe`, `WaitingForOthers`, `NeedAttention`, `Scheduled` | as for `InProgress` | `Sent` / `PartiallySigned` |
| anything else (for example `Draft`) | | throws `ProviderError`. Please report it. |

`InProgress` and `Completed` appear in BoldSign's sample responses. `Declined`, `Revoked` and `Expired` are the names of BoldSign's document events and list filters. `WaitingForMe`, `WaitingForOthers`, `NeedAttention` and `Scheduled` are list filters, accepted in case a document ever reports one. Signer statuses in the samples are `NotCompleted` and `Completed`.

`details($ref)` reads the same response and maps each `signerDetails[]` entry to a `SignerState` (`signerEmail`, `signerName`, `order`; `signingUrl` is always null because the links come by email):

| BoldSign signer `status` | `SignerStatus` |
|---|---|
| `Completed` | `Signed` |
| `Declined` (inferred; not shown in BoldSign's samples) | `Declined` |
| `NotCompleted`, or anything else | `Pending`, which never claims a signature |

BoldSign's `SendFailed` event has no documented document status to go with it, so this driver doesn't use `EnvelopeStatus::Failed`. An undocumented status throws `ProviderError` instead.

## Capabilities and optional features

| | BoldSign |
|---|---|
| Identity methods | `email` (optionally with `EmailOTP`), `sms_otp` |
| Documents per envelope | up to 25 (fields on the first one only) |
| Multiple documents, signing order, field placement | yes |
| Signing links from `send()` | no: BoldSign emails them. `signingLink()` fetches an embedded link on demand |
| `remind()`, `extendDeadline()`, `details()` | yes |
| Webhook management | no: webhooks are set up in BoldSign's dashboard |
| Cancel | synchronous (revoke answers 204) |

The core checks every envelope against this before sending, and the optional features go through the same calls as for every provider:

```php
$esign->remind($ref, 'sara@example.com');                       // POST /v1/document/remind
$esign->extendDeadline($ref, new DateTimeImmutable('+14 days')); // PATCH /v1/document/extendExpiry
$esign->details($ref)->pending();                               // who hasn't signed yet
$esign->cancel($ref);                                           // POST /v1/document/revoke
```

- `remind()` sends `POST /v1/document/remind?documentId=…&receiverEmails=<signer>` with `{"Message": reminder_message}`, answered with 204.
- `extendDeadline()` sends `PATCH /v1/document/extendExpiry?documentId=…` with `{"NewExpiryValue": "yyyy-MM-dd"}`. That date format is the one for documents whose expiry is set in days, which is how this driver sends them. BoldSign refuses a date earlier than the current expiry, a date more than 180 days after creation, and a signed document.

BoldSign only: `$esign->provider('boldsign')->signingLink($envelopeId, $email, $redirectUrl)`.

## Download and cancel

- `download()` checks `status()` first and calls `GET /v1/document/download?documentId=…` only when the document is `Signed`. It returns the PDF bytes and refuses a body that isn't a PDF.
- `cancel()` calls `POST /v1/document/revoke?documentId=…` with `{"Message": revoke_message}` (204 No Content). BoldSign can't revoke a completed document, and it answers that with an error status, which surfaces as a `ProviderError`.

## Errors

Every HTTP status from 400 up throws `ProviderError` with `httpStatus` set. BoldSign documents 400, 401 (also the wrong region), 402, 403, 404, 417, 422, 429 and 500, but not the shape of the error body. The driver reads `error`, `message`, `title`, `detail` and an `errors` map when they are present. Otherwise it uses a short message for the status, and it never echoes a non-JSON body. The rate limit is 2000 requests per hour per account live and 50 in sandbox, so poll `status()` sparingly and rely on webhooks.

## Field positions

`SignatureField` is in PDF points from the top-left of the page. BoldSign's documentation doesn't name the unit or origin of `Bounds`. BoldSign's own example response shows fractional values such as `x: 257.74, y: 109.87, width: 124, height: 32`, which fit points from the top-left, so the driver sends the values unchanged (`coordinate_scale: 1.0`). If fields land in the wrong place on a live account, set `coordinate_scale` (for example `1.333` for 96-dpi pixels) and please report it.

## Open questions

The documentation leaves these unclear. The driver takes the safest reading of each:

1. **Bounds unit and origin** aren't documented. The driver assumes points from the top-left and offers `coordinate_scale`.
2. **Fields across several files.** `FormFields` have a `PageNumber` but no file reference, so the driver only places fields on the first document.
3. **The `Files` shape.** The field reference documents `[{"base64": "data:…", "fileName": "…"}]`, and one example uses bare data-URI strings. The driver uses the documented objects, which keep file names.
4. **`FieldType` vs `Type`.** The field reference names the property `Type`. Every example and SDK uses `FieldType`, so the driver uses `FieldType`.
5. **The error body** isn't documented. See [Errors](#errors).
6. **Whether `t` changes on retries.** BoldSign retries webhooks for up to 8 hours. If `t` is the original event time rather than the delivery time, late retries fall outside the tolerance and are refused. That is harmless, because `status()` stays the source of truth, but you can raise `webhook_tolerance` if it happens.
7. **Reminder `Message` type.** The table says `array`, but the example sends a string. The driver sends a string.
8. **Signers without fields.** BoldSign may require at least one field per signer. The driver sends the signer as given and lets BoldSign decide.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
$fake = $esign->provider();
$sent = $esign->send($envelope);
$fake->sign($sent->envelopeId);
```

The package's own tests run against `tests/Support/FakeBoldSign.php`, a PSR-18 fake built from the request and response shapes in BoldSign's documentation.

## Sources

BoldSign developer documentation, read October 2026:

- Send document: https://developers.boldsign.com/documents/send-document/
- Signing order (`SignerOrder`): https://developers.boldsign.com/how-to-guides/how-to-allow-the-signer-to-sign-in-a-particular-order/
- Phone number format (`CountryCode`, `Number`): https://developers.boldsign.com/documents/add-authentication-to-the-document/
- Document details and status: https://developers.boldsign.com/documents/document-details-and-status/
- List documents (status filters): https://developers.boldsign.com/documents/list-documents/
- Revoke document: https://developers.boldsign.com/documents/revoke-document/
- Send reminders: https://developers.boldsign.com/documents/send-reminder/
- Extend document expiry: https://developers.boldsign.com/documents/extend-document-expiry/
- Download document: https://developers.boldsign.com/documents/download-document/
- Embedded signing link: https://developers.boldsign.com/embedded-signing/get-embedded-signing-link/
- API key: https://developers.boldsign.com/authentication/api-key/
- Regional base URLs: https://developers.boldsign.com/sdks/php-sdk/
- Rate limit: https://developers.boldsign.com/api-overview/rate-limit/
- Errors: https://developers.boldsign.com/troubleshooting/possible-errors-and-solution/
- Verify webhook events: https://developers.boldsign.com/webhooks/verify-webhook-events/
- Webhook events: https://developers.boldsign.com/webhooks/available-events/
- Webhook payloads: https://developers.boldsign.com/webhooks/sample-event-data/ and https://developers.boldsign.com/webhooks/event-metadata/
- Webhook retries (8 hours): https://developers.boldsign.com/api-overview/getting-started/
