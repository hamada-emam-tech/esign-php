# Getting started with SADQ

What you need from SADQ before the driver can send anything, and how to go from sandbox to production.

## 1. Credentials

SADQ issues an **integration account**. You get six values per environment:

| Value | Config key | Notes |
|---|---|---|
| Account ID | `account_id` | A UUID |
| Account secret | `account_secret` | |
| Integration username | `username` | An email. It also appears in every envelope as the "owner" signatory. |
| Integration password | `password` | May contain `&`, `#` or `+`. The driver encodes it correctly. |
| Client ID | `client_id` | Usually `Integrationclient` (the default) |
| Client secret | `client_secret` | |

Keep them in environment variables or a secrets manager, never in the repository.

| Environment | Base URL | Signing pages |
|---|---|---|
| Sandbox | `https://api-sandbox.sadq-sa.com` | `https://sandbox.sadq-sa.com/sign/…` |
| Production | `https://apigw.sadq.sa` | `https://sadq-sa.com/sign/…` |

Production credentials come from the SADQ team once the sandbox integration is done.

## 2. Register your webhook (once per environment)

SADQ calls your webhook when an envelope changes. Register it with the driver:

```php
$token = bin2hex(random_bytes(32));     // optional HeaderToken; see "Webhook verification" in the README
$id = $esign->provider('sadq')->registerWebhook('https://app.example.sa/webhooks/sadq', $token);
// Save SADQ_WEBHOOK_ID=$id (and the token, if you'll use webhook_verification: token)
```

Then set `webhook_id` in the config. SADQ doesn't sign webhooks, so the driver accepts them by default and always checks the real status with SADQ's API.

- **Don't make it the default** (`isDefault` stays false) if the SADQ account is shared with another app. Envelopes sent without a `webhook_id` go to the default webhook, so the driver always sends yours.
- **Always answer 200**, even when verification fails. Log the failure instead. SADQ retries on anything else.
- The webhook body only says *which* envelope changed (`{"RequestId": "…", "Status": 1}`). Treat the `Status` number as a hint and call `status()`.
- To replay a missed call: `$sadq->resendWebhook($envelopeId)`.
- SADQ keeps a delivery log per envelope (`GET /api/v1/webhooks/logs`) showing each attempt's result and HTTP status.

## 3. Sandbox test run

1. Send an envelope to your own email (see the README).
2. Open the signing link from `$sent->signingUrls`, or from the email, and sign.
3. Your webhook is called. `status()` returns `PartiallySigned`, then `Signed`.
4. `download()` returns the sealed PDF.

The package's live suite does steps 1 to 3 automatically, apart from the signing itself:

```bash
cp .env.example .env.sandbox      # fill in the sandbox values (the file is gitignored)
vendor/bin/phpunit --group live
```

## 4. Going to production

- [ ] Production credentials from SADQ
- [ ] `environment` set to `production`
- [ ] `registerWebhook()` run against production; `webhook_id` saved
- [ ] The webhook route answers 200 and queues the status check
- [ ] A PSR-16 `cache` configured if you run several workers, so they share one access token
- [ ] One real envelope signed end to end

## 5. Storing envelopes

The driver stores nothing. Typically you keep, per document you send:

| Column | Why |
|---|---|
| `provider` (`sadq`) | So you can switch providers later |
| `envelope_id` | The only ID the driver needs. Status, download and cancel all take it. |
| `status` | The last `EnvelopeStatus` you saw |
| `cancelled_at` | Set when *you* cancel. SADQ shows the cancellation only after a few minutes. |
| `signed_pdf_path` | Where you stored the `download()` result |
