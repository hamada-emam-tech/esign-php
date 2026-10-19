# hamada-emam-tech/esign-adobe

[Adobe Acrobat Sign](https://www.adobe.com/acrobat/business/sign.html) (REST API v6) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php).

> **Status: built from Adobe Acrobat Sign's official API documentation, not yet verified against a live account.** Every endpoint, field and status below comes from Adobe's published OpenAPI description and developer guides (see [Sources](#sources)). The package's tests run against a fake built from those documented shapes. If you run it against a real account, please report what you find.

```bash
vendor/bin/esign add adobe          # or: composer require hamada-emam-tech/esign-adobe
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically.

## Configure

With an Integration Key, or an OAuth access token you refresh yourself:

```php
$esign = new Esign([
    'default'   => 'adobe',
    'providers' => [
        'adobe' => [
            'access_token'      => getenv('ADOBE_SIGN_INTEGRATION_KEY'),
            'webhook_client_id' => getenv('ADOBE_SIGN_CLIENT_ID'),
        ],
    ],
]);
```

With OAuth refresh, where the driver gets its own access tokens:

```php
'adobe' => [
    'client_id'        => getenv('ADOBE_SIGN_CLIENT_ID'),
    'client_secret'    => getenv('ADOBE_SIGN_CLIENT_SECRET'),
    'refresh_token'    => getenv('ADOBE_SIGN_REFRESH_TOKEN'),
    'api_access_point' => getenv('ADOBE_SIGN_API_ACCESS_POINT'),   // returned with the refresh token
],
```

| Option | Default | Notes |
|---|---|---|
| `access_token` (alias `integration_key`) | – | Sent as `Authorization: Bearer …`. Required unless OAuth refresh is configured. A fixed token is never retried on 401. |
| `client_id`, `client_secret`, `refresh_token` | – | OAuth refresh. All three, or none. The driver calls `POST {api_access_point}/oauth/v2/refresh`, caches the access token, and on a 401 refreshes once and retries. Refresh tokens expire after 60 days unused. |
| `api_access_point` | discovered | The account's shard, e.g. `https://api.na1.adobesign.com/`. Without it, the driver calls `GET /api/rest/v6/baseUris` once and caches the answer. It looks it up again once if a call answers `INVALID_API_ACCESS_POINT`. **Required with OAuth refresh**, because the refresh endpoint lives on the shard and discovering the shard needs a token. |
| `environment` | `production` | Where `baseUris` is called: `production` → `https://api.adobesign.com`, `sandbox` → `https://api.adobesignsandbox.com`. |
| `webhook_client_id` | `client_id` | The client ID of the API application that registered your webhook. **Without it every webhook is refused.** |
| `expires_in_days` | account setting | Sets the agreement's `expirationTime`. |
| `page_height` | read from the PDF | Page height in points, used to place fields when the driver can't read a single height from the PDF (see [Signature fields](#signature-fields)). |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |
| `cache` | none | A PSR-16 cache, to share the access point and access token across requests and workers. |

## Capabilities

| | Acrobat Sign |
|---|---|
| Identity methods | `email` (`authenticationMethod: NONE`), `sms_otp` (`PHONE`, with the mobile written as `"+966 500000000"`) |
| Documents per envelope | no limit known (Acrobat Sign combines them into one PDF) |
| `Feature::MultipleDocuments` | yes |
| `Feature::SigningOrder` | yes |
| `Feature::FieldPlacement` | yes, on the first document, for every signer or none |
| `Feature::SigningUrls` | no: Acrobat Sign emails the links |
| `Feature::Reminders` | yes: `POST /agreements/{id}/reminders` |
| `Feature::ExpiryExtension` | no (see below) |
| `Feature::WebhookManagement` | yes: `POST`, `GET`, `DELETE /webhooks` |
| `Feature::SignerDetails` | yes: from `GET /agreements/{id}/members` |
| Webhook response | customised: echoes `X-AdobeSign-ClientId` |
| Async cancel | not documented as asynchronous |

`nafath`, `absher`, `wathq` and `uae_pass` are refused before any HTTP call. Expiry extension isn't offered because the documented call, `PUT /agreements/{id}`, needs the whole `AgreementInfo` sent back (`fileInfos`, `participantSetsInfo`, …). Doing that on an agreement that's already out for signature is too risky to do blind.

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer, SignatureField, IdentityMethod};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf')],
    signers: [
        Signer::email('Ahmed Ali', 'ahmed@example.com', order: 1, fields: [new SignatureField(page: 1, x: 60, y: 700)]),
        new Signer('Sara', 'sara@example.com', IdentityMethod::SmsOtp, mobile: '+966 500000000', order: 2,
            fields: [new SignatureField(page: 2, x: 300, y: 100)]),
    ],
    subject: 'Please sign the agreement',          // the agreement name
    message: 'The agreement we discussed.',
    metadata: ['reference' => 'contract-42'],      // sent as externalId: visible to participants, never a secret
));

$db->save((string) $sent->ref());                  // "adobe:CBJCHBCAABAA…"
```

What the driver does:

1. Uploads each document with `POST /transientDocuments` (multipart `File-Name`, `Mime-Type`, `File`).
2. Creates the agreement with `POST /agreements`: one participant set per signer (`role: SIGNER`), `signatureType: ESIGN`. Each signer gets its own set because a set with several members is complete when *any one* of them acts.
3. Renumbers signing orders to `1, 2, 3…`, which Acrobat Sign requires. Signers who share an order still sign in parallel.
4. Without fields, creates the agreement in `IN_PROCESS`, and Acrobat Sign places the signature block itself.
5. With fields, creates it in `AUTHORING`, writes the fields with `PUT /agreements/{id}/formFields` (each field assigned to its signer's participant set), then moves it to `IN_PROCESS` with `PUT /agreements/{id}/state`. If placing the fields fails, nobody has been invited: the agreement stays in `AUTHORING`, and the `ProviderError` says so.

### Signature fields

`SignatureField` measures `y` (the field's top edge) from the **top** of the page. Acrobat Sign's `FormFieldLocation.top` is measured from the **bottom** ("default user space units from bottom of the page"). The driver converts with `top = pageHeight − y`, keeping `left = x`, `width` and `height`.

The page height comes from the PDF's `/MediaBox` entries, and only when they all agree. In these cases the driver uses the `page_height` option, or refuses before uploading anything:

- mixed page sizes
- rotated pages
- page objects inside compressed object streams
- a non-PDF document

Fields are accepted on the first document only, because Acrobat Sign numbers the pages of the combined PDF. Either every signer gets a field or none does.

## Webhook, then status, then download

```php
// GET and POST /webhooks/adobe
$result = $esign->handleWebhook($request->headers->all(), $request->getContent(), 'adobe');
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));      // do the rest in a queue
}
$r = $result->response;
return response($r->body, $r->status, $r->headers);          // always send it: it carries the echo

// In the job:
$status = $esign->status($ref);                // "adobe:…": asks Acrobat Sign, never the webhook body
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);
}
$esign->details($ref)->pending();              // who still has to sign
```

How the webhook works:

- **Verification.** Acrobat Sign doesn't sign webhook bodies. Each call carries `X-AdobeSign-ClientId`, the client ID of the application that registered the webhook. The driver compares it with `webhook_client_id` in constant time. With no `webhook_client_id`, it refuses everything.
- **What it returns.** `parseWebhook()` returns only the agreement ID (`agreement.id`), with `webhookNotificationId` as the event ID.
- **The echo.** Acrobat Sign counts a delivery as successful only if the response is a 2XX **and** echoes the client ID. `$result->response` does both: header `X-AdobeSign-ClientId`, and body `{"xAdobeSignClientId": "…"}`. This also answers the GET "verification of intent" that Acrobat Sign sends when a webhook is registered. That call has no body, so `isValid()` is false, but the response is still the correct one.
- **A client ID that isn't yours** gets a `403` with no echo. Adobe: "If the webhook URL does not recognize that client id, it MUST NOT respond with the success response code." This is the one provider where the answer to a refused call isn't a 200.

Register the endpoint once it's deployed. Acrobat Sign calls it before it creates the webhook:

```php
$id = $esign->registerWebhook('https://app.example.com/webhooks/adobe', null, 'adobe');   // scope USER
$esign->webhooks('adobe');            // first page of GET /webhooks
$esign->deleteWebhook($id, 'adobe');
```

Acrobat Sign has no shared webhook secret, so passing one throws. The subscribed events are `AGREEMENT_ACTION_COMPLETED`, `AGREEMENT_WORKFLOW_COMPLETED`, `AGREEMENT_RECALLED`, `AGREEMENT_REJECTED`, `AGREEMENT_EXPIRED` and `AGREEMENT_AUTO_CANCELLED_CONVERSION_PROBLEM`.

## Status mapping

`GET /agreements/{id}` → `status`:

| Acrobat Sign | `EnvelopeStatus` |
|---|---|
| `OUT_FOR_SIGNATURE` and the other open statuses (`OUT_FOR_*`, `AUTHORING`, `DRAFT`, `PREFILL`, `DOCUMENTS_NOT_YET_PROCESSED`, `WAITING_FOR_*`, `WIDGET_WAITING_FOR_VERIFICATION`, `PAUSED`), no signer set `COMPLETED` | `Sent` |
| the same, at least one SIGNER participant set `COMPLETED` (`GET /agreements/{id}/members`) | `PartiallySigned` |
| `SIGNED` (also `APPROVED`, `ACCEPTED`, `DELIVERED`, `FORM_FILLED`) | `Signed` |
| `CANCELLED` with a `REJECTED` event (`GET /agreements/{id}/events`) | `Declined` |
| `CANCELLED` with an `AUTO_CANCELLED_CONVERSION_PROBLEM` event | `Failed` |
| `CANCELLED`, otherwise | `Cancelled` |
| `EXPIRED` | `Expired` |
| `ARCHIVED`, or anything else | throws `ProviderError`. Please report it. |

Signer states (`details()`): a participant set with status `COMPLETED` → `Signed`, the participant named in a `REJECTED` event → `Declined`, anything else → `Pending`. Signing URLs are `null`, because Acrobat Sign emails them.

## What this driver handles for you

1. **Shards.** It discovers the account's API access point once and caches it, and looks it up again on `INVALID_API_ACCESS_POINT`.
2. **Errors.** It reads both documented error shapes: `{"code", "message"}` from the guides, and `{"reason": "CODE: message", "status"}` from the OpenAPI examples. Every failure is a `ProviderError` with the HTTP status and Acrobat Sign's code. Any token or secret is scrubbed from the message. A `THROTTLING_TOO_MANY_REQUESTS` error mentions `Retry-After`.
3. **Download only when signed.** `GET /combinedDocument` also serves the unsigned PDF while the agreement is open, so `download()` checks `status()` first and returns a PDF only for `Signed`.
4. **Cancel** is `PUT /agreements/{id}/state` with `CANCELLED` and `notifyOthers: true`.
5. **Reminders** go to the signer's participant ID (`recipientParticipantIds`), so nobody is added twice.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
```

The package's own tests run against `tests/Support/FakeAdobe.php`, a PSR-18 fake that reproduces the documented behaviour above.

## Sources

- REST API v6 reference and OpenAPI description: <https://secure.na1.adobesign.com/public/docs/restapi/v6>, <https://secure.na1.adobesign.com/restapijson/v6/restapi.json>. Endpoints, schemas, enums, `FormFieldLocation` ("from bottom of the page"), state transitions and error examples.
- OAuth (refresh flow, token lifetime): <https://secure.na1.adobesign.com/public/static/oauthDoc.jsp>
- Developer guide, API usage (transient documents, agreements, combined document): <https://developer.adobe.com/acrobat-sign/docs/overview/developer_guide/apiusage>
- Webhooks (verification of intent, client ID echo): <https://developer.adobe.com/acrobat-sign/docs/overview/developer_guide/webhookapis>
- Webhook payloads (`agreement.id`, `webhookNotificationId`, events): <https://developer.adobe.com/acrobat-sign/docs/overview/acrobat_sign_events/webhookpayloadoverview>
- Best practices (cache `apiAccessPoint`, `INVALID_API_ACCESS_POINT`, throttling error, AUTHORING-only form fields): <https://developer.adobe.com/acrobat-sign/docs/overview/developer_guide/bestpractices>
- Base API hosts (production and sandbox `baseUris`): <https://developer.adobe.com/acrobat-sign/docs/overview/embedpartner/embedapi2>
- Integration key as Bearer, sample `baseUris` response: <https://experienceleague.adobe.com/en/docs/acrobat-services-learn/tutorials/acrobatsign/signapi>
