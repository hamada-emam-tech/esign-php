# Webhooks

One webhook endpoint and one sync routine serve every provider. Your code is the same whether the envelope lives at SADQ, DocuSign or UAE PASS.

## The pieces

| Class | Job |
|---|---|
| `Webhook\WebhookHandler` | Picks the driver (named in your route, or detected), verifies the call with it, and answers the way that provider expects. Never throws for a bad call. |
| `Sync\EnvelopeSynchronizer` | Asks the provider for the real status, tells your listener when it changed, and downloads the signed PDF once. Use it from a queue job. |
| `Contracts\EnvelopeListener` (or `Sync\CallbackListener`) | Your code: `statusChanged()` and `signed()`. |
| `Webhook\Psr7Bridge` | Converts PSR-7 requests and responses, for PSR-7 frameworks. |

A webhook body is never trusted for the status. It only says which envelope to look at, and the provider's API is asked for the status every time.

## Set it up once

```php
use HamadaEmamTech\Esign\Envelope\{EnvelopeRef, EnvelopeStatus};
use HamadaEmamTech\Esign\Sync\{CallbackListener, EnvelopeSynchronizer};
use HamadaEmamTech\Esign\Webhook\WebhookHandler;

$listener = new CallbackListener(
    statusChanged: function (EnvelopeRef $ref, EnvelopeStatus $status) {
        Contract::where('esign_ref', (string) $ref)->update(['esign_status' => $status->value]);
    },
    signed: function (EnvelopeRef $ref, string $pdf) {
        Storage::put("contracts/signed/{$ref->provider}-{$ref->envelopeId}.pdf", $pdf);
    },
);
$sync = new EnvelopeSynchronizer($esign, $listener);

// Recommended: answer fast, sync in a queue.
$handler = new WebhookHandler($esign, defer: fn (EnvelopeRef $ref) => dispatch(new SyncEnvelope((string) $ref)));
```

The queued job:

```php
final class SyncEnvelope implements ShouldQueue
{
    public function __construct(public string $ref) {}

    public function handle(EnvelopeSynchronizer $sync): void
    {
        $contract = Contract::where('esign_ref', $this->ref)->firstOrFail();
        $sync->sync($this->ref, EnvelopeStatus::tryFrom((string) $contract->esign_status));
    }
}
```

Passing the stored status as the second argument makes the sync idempotent: a retried webhook, or a second run, never notifies twice or downloads twice. If the download fails, the sync throws *before* calling your listener, so a queue retry starts clean.

If you'd rather not use a queue, `new WebhookHandler($esign, sync: $sync)` syncs during the request. `$result->syncError` tells you if that failed, and the provider still gets its answer.

## The route

### One route per provider (recommended)

```
POST /esign/webhooks/{provider}        e.g. /esign/webhooks/sadq, /esign/webhooks/docusign
GET  /esign/webhooks/uaepass           UAE PASS returns the browser here (finish_callback_url)
```

The provider name comes from the URL, so it is treated as untrusted. A name that isn't configured gets a 404 and never reaches a driver.

### One route for everything

Leave the provider out (`$handler->handle($headers, $body)`), and each configured driver says whether the request looks like one of its own webhooks:

| Provider | Recognized by |
|---|---|
| SADQ | a JSON body with `RequestId` and `Status` |
| DocuSign | the `X-DocuSign-Signature-1` header |
| Zoho Sign | the `X-ZS-WEBHOOK-SIGNATURE` header |
| Dropbox Sign | `event.event_hash` in the `json` field |
| BoldSign | the `X-BoldSign-Signature` or `X-BoldSign-Event` header |
| Adobe Sign | the `X-AdobeSign-ClientId` header |
| Signit | an encrypted body: `data` plus `is_encrypted` |
| UAE PASS | `signer_process_id=sp_…` in the redirect query string |

Detection only chooses which driver verifies the call. If no driver, or more than one, claims a request, it is answered with a plain 200 and not processed. The monorepo's `WebhookRoutingTest` proves that every provider's documented webhook is claimed by exactly its own driver.

## How each provider is verified

| Provider | Verification |
|---|---|
| SADQ | None by default: SADQ doesn't sign webhooks. Optional `HeaderToken` check (`webhook_verification: token`). |
| DocuSign | HMAC-SHA256 of the raw body (`X-DocuSign-Signature-N`), with key rotation |
| Zoho Sign | HMAC-SHA256 of the raw body, base64 |
| Dropbox Sign | `event_hash` = HMAC-SHA256(event_time + event_type) with the API key |
| BoldSign | HMAC-SHA256 of `timestamp.body`, with a timestamp tolerance |
| Adobe Sign | The client ID header must match yours, and is echoed back as Adobe requires |
| Signit | AES-256-CBC decryption with your hex key: only the key holder can produce a valid call |
| UAE PASS | None possible: it's an unsigned browser redirect. Treated as a hint only. |

Where verification is weak or impossible, the design still holds, because the status always comes from the provider's API. A forged call can at most trigger one status lookup. Rate-limit the route.

## Framework wiring

**Laravel**

```php
Route::match(['GET', 'POST'], '/esign/webhooks/{provider}', function (Request $request, string $provider, WebhookHandler $handler) {
    $body = $request->getContent() !== '' ? $request->getContent() : (string) $request->getQueryString();
    $result = $handler->handle($request->headers->all(), $body, $provider);
    if ($result->error) {
        Log::warning('esign webhook refused', ['provider' => $provider, 'reason' => $result->error->getMessage()]);
    }

    return response($result->response->body, $result->response->status, $result->response->headers);
})->withoutMiddleware(VerifyCsrfToken::class);
```

**Symfony**

```php
#[Route('/esign/webhooks/{provider}', methods: ['GET', 'POST'])]
public function __invoke(Request $request, string $provider, WebhookHandler $handler): Response
{
    $body = $request->getContent() !== '' ? $request->getContent() : (string) $request->getQueryString();
    $answer = $handler->handle($request->headers->all(), $body, $provider)->response;

    return new Response($answer->body, $answer->status, $answer->headers);
}
```

**PSR-7 (Slim, Mezzio, …)**

```php
$app->map(['GET', 'POST'], '/esign/webhooks/{provider}', fn ($request, $response, $args) =>
    Psr7Bridge::handle($handler, $request, $responseFactory, $streamFactory, $args['provider']));
```

**Plain PHP**

```php
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = file_get_contents('php://input') ?: ($_SERVER['QUERY_STRING'] ?? '');
$answer = $handler->handle($headers, $body, $_GET['provider'] ?? null)->response;

http_response_code($answer->status);
foreach ($answer->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $answer->body;
```

## Providers without webhooks you can rely on

UAE PASS has no server-to-server webhook, and any provider can drop a call. Run the same sync on a schedule for envelopes that aren't final yet:

```php
foreach (Contract::whereNotIn('esign_status', ['signed', 'declined', 'expired', 'cancelled', 'failed'])->cursor() as $contract) {
    dispatch(new SyncEnvelope($contract->esign_ref));
}
```

Mind each provider's polling limits; DocuSign, for example, allows one status request per envelope every 15 minutes.
