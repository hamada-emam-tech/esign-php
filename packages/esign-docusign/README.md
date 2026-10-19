# hamada-emam-tech/esign-docusign

[DocuSign](https://www.docusign.com) driver for [hamada-emam-tech/esign](https://github.com/hamada-emam-tech/esign-php). It uses the eSignature REST API v2.1, JWT Grant authentication and Connect webhooks signed with HMAC.

> **Status:** built from DocuSign's official API documentation. It has **not yet been verified against a live DocuSign account**. The tests run against `tests/Support/FakeDocuSign.php`, a fake built from the documented request and response shapes. Please report anything that behaves differently on a real account.

```bash
vendor/bin/esign add docusign          # or: composer require hamada-emam-tech/esign-docusign
```

The core discovers the driver on install. You also need a PSR-18 HTTP client: Guzzle and Symfony HttpClient are picked up automatically. The driver itself needs only `ext-openssl` (to sign the JWT), `ext-json` and the PSR interfaces.

## Before you start (one-time, in DocuSign)

1. Create an integration key in **Apps and Keys**, add an RSA key pair, and keep the private key.
2. Grant consent for JWT Grant once: open `https://account-d.docusign.com/oauth/auth?response_type=code&scope=signature%20impersonation&client_id=<integration key>&redirect_uri=<a registered redirect URI>` (use `account.docusign.com` in production) and accept. Until you do, every call fails with `ProviderError` code `consent_required`.
3. For webhooks: enable Connect HMAC in the account's Connect settings and copy the HMAC key(s).
4. For SMS authentication only: get the workflow ID named "Phone Authentication" from `GET /restapi/v2.1/accounts/{accountId}/identity_verification`.

## Configure

```php
$esign = new Esign([
    'default'   => 'docusign',
    'providers' => [
        'docusign' => [
            'environment'     => 'demo',                         // or 'production'
            'integration_key' => getenv('DOCUSIGN_INTEGRATION_KEY'),
            'user_id'         => getenv('DOCUSIGN_USER_ID'),     // the impersonated user's GUID
            'private_key_path'=> '/run/secrets/docusign.pem',    // or 'private_key' => the PEM string
            'webhook_secret'  => getenv('DOCUSIGN_HMAC_KEY'),
        ],
    ],
]);
```

| Option | Default | Notes |
|---|---|---|
| `environment` | `demo` | `demo` (alias `sandbox`) → `account-d.docusign.com`, `production` → `account.docusign.com`. This is also the JWT `aud`. |
| `integration_key` | required | The JWT `iss`. |
| `user_id` | required | The JWT `sub`: the GUID of the user to impersonate, not an email. |
| `private_key` / `private_key_path` | one required | PEM RSA private key. Checked when the provider is built, and never put in an error message. |
| `account_id` | the user's default account | The API account to use, from the `/oauth/userinfo` account list. |
| `base_uri` | from `/oauth/userinfo` | E.g. `https://demo.docusign.net`. Skips the userinfo call. Needs `account_id`. |
| `webhook_secret` | none | The Connect HMAC key, or a list of keys during rotation. **Without it every webhook is refused.** |
| `phone_auth_workflow_id` | none | Enables `IdentityMethod::SmsOtp` (see [Identity](#identity)). |
| `void_reason` | `Cancelled by the sender.` | The `voidedReason` that `cancel()` sends. |
| `http_client`, `request_factory`, `stream_factory` | auto-detected | PSR-18 / PSR-17 objects. |
| `cache` | none | A PSR-16 cache, to share the access token and the account's base URI across requests and workers. |

## Capabilities

| | DocuSign |
|---|---|
| Identity methods | `email`; `sms_otp` when `phone_auth_workflow_id` is set. `nafath`, `absher`, `wathq` and `uae_pass` are refused before any HTTP call. |
| Max documents | no limit set by the driver |
| `MultipleDocuments`, `SigningOrder`, `FieldPlacement` | yes |
| `SignerDetails` (`details()`) | yes |
| `Reminders` (`remind()`) | yes: resends the envelope to that recipient |
| `ExpiryExtension` (`extendDeadline()`) | yes: sets the envelope's expiration in whole days |
| `SigningUrls` | no. Signers are remote and DocuSign emails them the link. |
| `WebhookManagement` | no (see [Webhooks](#webhooks)) |
| Async cancel | no |

## Send

```php
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer, SignatureField};

$sent = $esign->send(new Envelope(
    documents: [Document::fromPath('contract.pdf'), Document::fromPath('annex.pdf')],
    signers: [
        Signer::email('Ahmed Ali', 'ahmed@example.com', order: 1, fields: [
            new SignatureField(page: 1, x: 60, y: 700),
            new SignatureField(page: 2, x: 100, y: 120, documentIndex: 1),
        ]),
        Signer::email('Sara', 'sara@example.com', order: 2, fields: [new SignatureField(page: 1, x: 300, y: 700)]),
    ],
    subject: 'Please sign the agreement',                         // DocuSign allows 100 characters
    callbackUrl: 'https://app.example.com/webhooks/docusign',     // subscribes this envelope to Connect
    metadata: ['reference' => 'contract-42'],                     // envelope custom fields, 100 characters each
));

$db->save((string) $sent->ref());   // "docusign:<envelopeId>": store this
```

How it maps to `Envelopes:create` (sent with `status: "sent"`):

- each document gets `documentId` `"1"`, `"2"`, … in order, with `documentBase64`, `name` and `fileExtension` (`pdf`, or the name's extension for other types, which DocuSign converts);
- each signer gets `recipientId` `"1"`, `"2"`, …, and `routingOrder` from `Signer::$order` (equal orders sign in parallel; 0 becomes 1);
- each `SignatureField` becomes a `signHereTab` with `documentId`, `pageNumber`, `xPosition` and `yPosition`. Both use points from the top-left of the page (DocuSign positions tabs at 72 DPI). Sign Here tabs have no width or height, so `SignatureField::$width` and `$height` are ignored;
- give each signer at least one field. A signer without fields gets no tabs, and what DocuSign shows them then depends on the account's settings;
- `callbackUrl` adds an `eventNotification` with `deliveryMode: "SIM"`, `eventData.version: "restv2.1"` (JSON) and `includeHMAC: "true"`. You can use an account-level Connect configuration instead and leave `callbackUrl` out.

## Webhook, then status, then download

```php
// POST /webhooks/docusign
$result = $esign->handleWebhook($request->headers->all(), $request->getContent(), 'docusign');   // never throws
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));       // do the rest in a queue
} else {
    logger()->warning('Bad DocuSign webhook', ['error' => $result->error?->getMessage()]);
}
return response($result->response->body, $result->response->status, $result->response->headers);

// In the job:
$status = $esign->status($ref);                              // "docusign:<envelopeId>"
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);                           // documents/combined: one PDF
}
```

DocuSign allows **one status request per envelope per 15 minutes** and suggests polling every 20 minutes at most. Use Connect rather than polling. `download()` reuses a `completed` that `status()` saw in the same process instead of asking again.

```php
$details = $esign->details($ref);           // per-signer status, with routing order
$esign->remind($ref, 'sara@example.com');   // resends the envelope to Sara only
$esign->extendDeadline($ref, new DateTimeImmutable('+30 days'));
$esign->cancel($ref);                        // voids it, with void_reason
```

## Status mapping

From [Envelope status codes](https://developers.docusign.com/docs/esign-rest-api/esign101/concepts/envelopes/status-codes/). Partial signing is read from `recipients.signers[].status` (`Envelopes:get` with `include=recipients`).

| DocuSign envelope status | `EnvelopeStatus` |
|---|---|
| `created`, `sent`, `delivered`, `correct`, and no signer has signed | `Sent` |
| `created`, `sent`, `delivered`, `correct`, and at least one signer is `signed`, `completed` or `faxpending` | `PartiallySigned` |
| `signed` (everyone signed, DocuSign is still processing; it moves to `completed` on its own) | `PartiallySigned` |
| `completed` | `Signed` |
| `declined` | `Declined` |
| `voided`, and `voidedReason` mentions expiry | `Expired` |
| `voided`, any other reason | `Cancelled` |
| `timedout` (legacy, no longer used) | `Expired` |
| `deleted` (legacy, no longer used) | `Cancelled` |
| `template`, or anything else | throws `ProviderError`. Please report it. |

| DocuSign recipient status | `SignerStatus` |
|---|---|
| `signed`, `completed`, `faxpending` | `Signed` |
| `declined` | `Declined` |
| `created`, `sent`, `delivered`, `autoresponded`, anything else | `Pending` |

## Webhooks

Connect sends one `X-DocuSign-Signature-N` header per HMAC key on the account. Each holds the base64 HMAC-SHA256 of the raw body. `parseWebhook()`:

- **fails closed**: with no `webhook_secret` configured, every webhook is refused;
- checks every signature header against every configured key with a constant-time compare (one match is enough, which allows key rotation);
- needs the exact raw body. A re-encoded body fails verification;
- reads only `data.envelopeId` from the JSON SIM message, and never the status. Connect needs only a 2xx answer, so the core's plain 200 is right.

Webhook management through the API is left out. Connect configurations need an account admin, and DocuSign generates the HMAC keys in its admin UI, so a caller-chosen secret can't be registered. Set Connect up in the DocuSign admin, or per envelope with `callbackUrl`.

## Identity

- `email` (the default): DocuSign emails the signing link.
- `sms_otp`: DocuSign's phone authentication, as in its "require phone authentication" how-to. It uses the account's Phone Authentication workflow (`phone_auth_workflow_id`) and `identityVerification.inputOptions[phone_number_list]`. DocuSign lets the recipient choose a text message or a call. Write the number as country code, a space, then the number: `+966 501234567`. DocuSign needs the two separately, and splitting a bare `+966501234567` would mean guessing.
- `nafath`, `absher`, `wathq`, `uae_pass`: not offered by DocuSign. Refused with `EsignException` (`EnvelopeNotSupported` from the core) before any HTTP call.

## What the driver handles for you

1. **JWT Grant with ext-openssl only.** An RS256 JWT with `iss`, `sub`, `aud`, `iat`, `exp` (one hour) and `scope: "signature impersonation"`. The access token lasts an hour and has no refresh token. It is cached in memory and in the PSR-16 cache, and renewed 15 minutes early, as DocuSign advises. A 401 gets one fresh token and one retry.
2. **The account's base URI** comes from `/oauth/userinfo`, which is rate limited. It is cached the same way, or skipped with `base_uri`.
3. **Errors** become `ProviderError` with the HTTP status and DocuSign's `errorCode` (e.g. `ENVELOPE_DOES_NOT_EXIST`, `ENVELOPE_CANNOT_VOID_INVALID_STATE`), or the OAuth `error` (`consent_required`, `invalid_grant`). A 200 that carries `errorDetails` also throws. Messages never contain the key, the JWT, the token or the userinfo body.
4. **Download only when completed.** `documents/combined` also returns the unsigned documents of an open envelope, so `download()` checks for `completed` first and checks that the bytes are a PDF.
5. **Reminders** send only the `recipientId` with `resend_envelope=true`. Sending the email again would count as an address correction. Signers who already acted are refused. Per-recipient errors that come back inside a 200 are raised.
6. **Extending the deadline** reads the current reminder and warning settings and sends them back, so they aren't reset. `expireAfter` counts days from the first send, rounded up, so the envelope stays open at least until the date you give.

## Assumptions (where the docs leave room)

- **Expired vs voided.** DocuSign reports an expired envelope as `voided` and says the `voidedReason` tells them apart, but doesn't publish the text. Only a reason containing "expired", "expiry" or "expiration" maps to `Expired`. Anything else is `Cancelled`.
- **`signed` maps to `PartiallySigned`**, not `Signed`. It is a documented transient state before `completed`, and the finished PDF is only certain after that.
- **Extending replaces the notification settings.** The current reminders and expireWarn are sent back with the new expiry, in case omitted settings are reset.
- **`includeHMAC: "true"`** is always sent with a `callbackUrl`. This driver refuses unsigned webhooks anyway.

## Testing without real signers

Use the core's `fake` provider in your app's tests:

```php
$esign = new Esign(['default' => 'fake']);
$sent = $esign->send($envelope);
$esign->provider()->sign($sent->envelopeId);
```

The package's own tests run against `tests/Support/FakeDocuSign.php`. It verifies each JWT's RS256 signature with a key pair generated at runtime (no key material is committed), and models the documented envelope lifecycle and error shapes.

## Sources

- JWT Grant: https://developers.docusign.com/platform/auth/jwt-get-token/
- Obtain access token endpoint: https://developers.docusign.com/platform/auth/reference/obtain-access-token/
- User info endpoint: https://developers.docusign.com/platform/auth/reference/user-info/
- Envelopes:create: https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopes/create/
- Envelopes:get: https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopes/get/
- Envelopes:update (void): https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopes/update/
- EnvelopeDocuments:get (combined): https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopedocuments/get/
- EnvelopeRecipients:list: https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/enveloperecipients/list/
- EnvelopeRecipients:update (resend_envelope): https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/enveloperecipients/update/
- Envelopes:updateNotificationSettings: https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopes/updatenotificationsettings/
- Envelopes:getNotificationSettings: https://developers.docusign.com/docs/esign-rest-api/reference/envelopes/envelopes/getnotificationsettings/
- Envelope status codes: https://developers.docusign.com/docs/esign-rest-api/esign101/concepts/envelopes/status-codes/
- Recipient status codes: https://developers.docusign.com/docs/esign-rest-api/esign101/concepts/recipients/status-codes/
- Recipient authentication: https://developers.docusign.com/docs/esign-rest-api/esign101/concepts/recipients/auth/
- Require phone authentication: https://developers.docusign.com/docs/esign-rest-api/how-to/phone-auth/ (request body from the linked example, https://github.com/docusign/code-examples-bash/blob/master/examples/eSignature/eg020SigningViaEmailWithPhoneAuthentication.sh)
- Connect HMAC: https://developers.docusign.com/platform/webhooks/connect/hmac/
- Validate an HMAC signature: https://developers.docusign.com/platform/webhooks/connect/validate/
- JSON SIM event model: https://developers.docusign.com/platform/webhooks/connect/json-sim-event-model/
- Polling and API limits: https://developers.docusign.com/docs/esign-rest-api/esign101/rules-and-limits/
- Error codes: https://developers.docusign.com/docs/esign-rest-api/esign101/error-codes/troubleshooting-common-errors/
