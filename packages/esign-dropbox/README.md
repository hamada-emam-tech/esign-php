# hamada-emam-tech/esign-dropbox

[Dropbox Sign](https://sign.dropbox.com) (formerly HelloSign) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php): e-signature requests over the Dropbox Sign API v3.

> **Status: built from Dropbox Sign's official API documentation, not yet verified against a live account.** Request and response shapes come from the API reference and the official OpenAPI spec (see [Sources](#sources)). If something behaves differently on your account, please open an issue.

```bash
vendor/bin/esign add dropbox          # or: composer require hamada-emam-tech/esign-dropbox
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically.

## Configure

```php
$esign = new Esign([
    'default'   => 'dropbox',
    'providers' => [
        'dropbox' => [
            'api_key'   => getenv('DROPBOX_SIGN_API_KEY'),
            'test_mode' => true,                         // the default. false sends legally binding requests
            'client_id' => getenv('DROPBOX_SIGN_CLIENT_ID'),   // optional: your API app
        ],
    ],
]);
```

| Option | Default | Notes |
|---|---|---|
| `api_key` | required | From Settings → API in Dropbox Sign. Sent as the HTTP Basic username with an empty password, and used to verify events. |
| `test_mode` | `true` | `true` sends `test_mode=1`: free, watermarked, **not legally binding**. Set `false` explicitly to go live. Must be a real boolean. |
| `client_id` | none | An API app. Dropbox Sign applies its branding and sends events to **its** callback URL. |
| `base_url` | `https://api.hellosign.com/v3` | Must be `https://`. |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer, SignatureField, IdentityMethod};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf'), Document::fromPath('annex.pdf')],
    signers: [
        Signer::email('Ahmed Ali', 'ahmed@example.com', order: 1, fields: [new SignatureField(page: 1, x: 60, y: 700)]),
        Signer::email('Sara', 'sara@example.com', order: 2, fields: [new SignatureField(page: 2, x: 60, y: 100, documentIndex: 1)]),
    ],
    subject: 'Please sign the agreement',
    message: 'Thanks!',
    metadata: ['reference' => 'contract-42'],
));

$ref = (string) $sent->ref();   // "dropbox:<signature_request_id>": store this one string
$sent->signingUrls;             // always []: signers sign from Dropbox Sign's email
```

How the envelope is sent (`POST /signature_request/send`, multipart/form-data):

| Envelope | Dropbox Sign field |
|---|---|
| documents | `files[0]`, `files[1]`, … (several documents per request are fine) |
| signer name / email | `signers[n][name]`, `signers[n][email_address]`, in the envelope's order |
| signer order | `signers[n][order]`, 0-based. All signers with the same order: none sent (parallel signing). All different: 0, 1, 2… in order. A mix of both is refused. |
| `IdentityMethod::SmsOtp` | `signers[n][sms_phone_number]` (E.164, e.g. `+9665…`) and `signers[n][sms_phone_number_type]=authentication`. Not available in test mode, so it is refused there. |
| `IdentityMethod::Nafath` | refused: Dropbox Sign has no Nafath. |
| subject | `title` and `subject` |
| message | `message` |
| metadata | `metadata[key]`. At most 10 keys, keys up to 40 characters, values up to 1000. Checked before sending. |
| fields | `form_fields_per_document`, a JSON array, one `signature` field each (see below) |
| `callbackUrl` | **not sent**: Dropbox Sign has no per-request callback. Set the account callback URL, or the API app's and pass `client_id`. |

Everything that can be checked locally (identity, order, fields, metadata) is checked before any HTTP call.

### Signature fields

`SignatureField` is in PDF points from the top-left of the page. With `page` set, Dropbox Sign uses its 72 DPI coordinate system, which is the same thing, so `x` and `y` are sent as they are (rounded). Width and height are always measured at 80 DPI, so the driver scales them by 80/72 and adds 2 to the width, as Dropbox Sign's FAQ says. A 150 × 50 pt field becomes `width: 169, height: 56`.

- Give every signer at least one field, or none at all. With none, Dropbox Sign adds a signature page for everyone. A mix is refused, because the signers without a field would have nothing to sign.
- Fields need PDF documents. Dropbox Sign's FAQ says the page-based system is designed for US Letter, and other page sizes may be placed slightly differently. Check placement in test mode first.

## Capabilities

| | Dropbox Sign |
|---|---|
| Identity methods | `email`, `sms_otp` (E.164 number; not in test mode, Standard plan or higher) |
| Documents per envelope | no limit known to the driver (`maxDocuments: null`) |
| `Feature::MultipleDocuments` | yes |
| `Feature::SigningOrder` | yes: all signers in parallel, or a strict sequence |
| `Feature::FieldPlacement` | yes, on PDFs |
| `Feature::SigningUrls` | no: signers sign from Dropbox Sign's email (embedded signing is not part of this driver) |
| `Feature::Reminders` | yes: `$esign->remind($ref, $email)` |
| `Feature::ExpiryExtension` | no (see below) |
| `Feature::WebhookManagement` | no: callback URLs are set on the account or the API app |
| `Feature::SignerDetails` | yes: `$esign->details($ref)` |
| Cancel | asynchronous (`asyncCancel: true`) |

The core checks every envelope against this before sending, and lists every problem at once (`EnvelopeNotSupported`). The driver repeats its own checks, so it is safe on its own too.

**Why no expiry extension:** `/signature_request/update` accepts `expires_at`, but per `signature_id`, together with a name or email change, and not on requests with an appended signature page. The docs don't say whether it moves the whole request. Rather than guess, the driver leaves it out.

## Webhook, then status, then download

Dropbox Sign posts events as multipart/form-data with the event JSON in a field named `json`. **Your endpoint must answer HTTP 200 with a body containing `Hello API Event Received`**, for every event, even an invalid one. Otherwise Dropbox Sign retries (up to 6 times, over about 30 hours) and clears your callback URL after 10 consecutive failures. The driver implements `CustomizesWebhookResponse`, so `$esign->handleWebhook()` returns that response for you:

```php
// POST /webhooks/dropbox
$result = $esign->handleWebhook($request->headers->all(), $request->getContent(), 'dropbox');   // never throws
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));      // "dropbox:<signature_request_id>"
} else {
    logger()->warning('Bad Dropbox Sign event', ['error' => $result->error?->getMessage()]);
}
return response($result->response->body, $result->response->status, $result->response->headers);
// always 200 "Hello API Event Received"

// In the job:
$status = $esign->status($ref);
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);
}

$details = $esign->details($ref);       // each signer: email, name, Pending | Signed | Declined, order
foreach ($details->pending() as $signer) {
    $esign->remind($ref, $signer->email);  // Dropbox Sign allows one reminder an hour
}
```

The body can be the raw multipart body (pass the `Content-Type` header so the boundary is known; otherwise it is read from the body), a url-encoded `json=…` body, or the bare event JSON. Frameworks that parse multipart for you leave `getContent()` empty: pass `$request->input('json')` as the body instead.

Verification: `event.event_hash` must equal `HMAC-SHA256(event_time . event_type)` keyed with your API key, in hex. It is compared in constant time, and with no API key every event is refused. A verified event that isn't about a signature request (`callback_test`, account or template events) is refused too. `handleWebhook()` still answers it with the required body.

The driver only takes `signature_request.signature_request_id` from the event. It never decides the status from it: `status()` asks the API.

## Status mapping

`status()` calls `GET /signature_request/{id}` and decides in this order:

| Dropbox Sign | `EnvelopeStatus` |
|---|---|
| HTTP 410, `error_name: deleted` (cancelled or deleted) | `Cancelled` |
| `error_name: signature_request_expired` | `Expired` |
| `has_error: true` ("an error occurred" creating the request or during signing) | `Failed` |
| `is_declined: true`, or any signature `declined` | `Declined` |
| `is_complete: true` and every signature `signed` | `Signed` |
| any signature `expired`, or `expires_at` in the past | `Expired` |
| `awaiting_signature` / `on_hold`, with some `signed` | `PartiallySigned` |
| `awaiting_signature` / `on_hold`, nobody signed | `Sent` |
| any other `status_code` | throws `ProviderError`. Please report it. |

The reference only lists `awaiting_signature`, `signed` and `declined` as examples of `status_code`. `on_hold` comes from the "release on-hold signature request" docs, and `expired` from the `expires_at` description ("unsigned signatures will be moved to the expired status").

Signer states in `details()`: `signed` → `Signed`, `declined` → `Declined`, anything else (`awaiting_signature`, `on_hold`, `expired`) → `Pending`. `order` is Dropbox Sign's own 0-based order (null for parallel signing), and `signingUrl` is always null. A cancelled or expired request answers with an error and no signer list, so its details have `signers: []`.

## Cancel, remind and download

- `cancel()` calls `POST /signature_request/cancel/{id}`. Dropbox Sign only **queues** the cancel: confirmation is the `signature_request_canceled` event, and `status()` says `Cancelled` once it has gone through. A request that everyone has signed can't be cancelled (`signature_request_cancel_failed`).
- `remind()` calls `POST /signature_request/remind/{id}` with `{"email_address": …}` (JSON, as documented). Dropbox Sign refuses a reminder within an hour of the previous one, manual or automatic, and for embedded requests; that comes back as a `ProviderError`.
- `download()` runs only when `status()` is `Signed`, then calls `GET /signature_request/files/{id}?file_type=pdf`, which returns the merged PDF itself (not a URL). While the PDF is still being prepared Dropbox Sign answers 409. That becomes a `ProviderError` with `httpStatus` 409 and "still being prepared": retry shortly, or wait for `signature_request_downloadable`.

## Errors

Every 4xx/5xx becomes a `ProviderError` carrying the HTTP status and Dropbox Sign's `error_name` as `providerCode` (`bad_request`, `unauthorized`, `not_found`, `conflict`, `exceeded_rate`, …), with `error_msg` in the message. The API key never appears in a message: it is scrubbed even if something echoes it back.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
$fake = $esign->provider();
$sent = $esign->send($envelope);
$fake->sign($sent->envelopeId);
```

The package's own tests run against `tests/Support/FakeDropbox.php`, a PSR-18 fake built from the documented request and response shapes.

## Sources

- API reference, send: https://developers.hellosign.com/api/reference/operation/signatureRequestSend/
- API reference, get: https://developers.hellosign.com/api/reference/operation/signatureRequestGet/
- API reference, cancel: https://developers.hellosign.com/api/reference/operation/signatureRequestCancel/
- API reference, files: https://developers.hellosign.com/api/reference/operation/signatureRequestFiles/
- API reference, remind: https://developers.hellosign.com/api/reference/operation/signatureRequestRemind/
- API reference, update (why there is no expiry extension): https://developers.hellosign.com/api/reference/operation/signatureRequestUpdate/
- Expiration: https://developers.hellosign.com/docs/signature-request/expiration/
- Signature request walkthrough (cURL: `-u 'YOUR_API_KEY:'`, `files[0]`, `signers[0][email_address]`, `test_mode=1`): https://developers.hellosign.com/docs/signature-request/walkthrough/
- Form fields per document: https://developers.hellosign.com/docs/openapi/form-fields-per-document/
- Coordinate systems (72 vs 80 DPI, width/height scaling): https://help.dropbox.com/integrations/how-to-use-the-form-fields-per-document-parameter
- Events overview: https://developers.hellosign.com/docs/events/overview/
- Events and callbacks walkthrough (the `json` field, `Hello API Event Received`, retries, `event_hash`): https://developers.hellosign.com/docs/guides/events-and-callbacks/walkthrough
- OpenAPI spec, including the `x-error-codes` catalog (`deleted` = 410, `signature_request_expired`, `conflict` = 409): https://github.com/hellosign/hellosign-openapi/blob/main/openapi.yaml
