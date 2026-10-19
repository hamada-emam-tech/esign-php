# hamada-emam-tech/esign

One interface for e-signature providers. Install the core, then only the providers you use.

```bash
composer require hamada-emam-tech/esign          # the core: contracts + a fake provider for tests
vendor/bin/esign add sadq                         # add a provider (runs composer require hamada-emam-tech/esign-sadq)
vendor/bin/esign add docusign                     # add as many as you need
```

Installing a provider package is enough. The core finds it automatically.

> **Status:** v0.3 ships the core with the unified workflow, the `fake` provider, the `vendor/bin/esign` command, and eight providers. You can already write your own with `extend()` (see [writing a provider](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/writing-a-provider.md)).

## Use it

```php
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Envelope\{Envelope, Document, Signer};

$esign = new Esign([
    'default'   => 'sadq',
    'providers' => [
        'sadq'   => ['client_id' => '…', 'client_secret' => '…'],
        'signit' => ['api_key' => '…'],
    ],
]);

$envelope = new Envelope(
    documents: [Document::fromPath('contract.pdf')],
    signers:   [Signer::nafath('1012345678', 'Ahmed Ali', 'ahmed@example.sa')],
    callbackUrl: 'https://app.example.sa/webhooks/esign',
);

$sent = $esign->send($envelope);                        // default provider
$sent = $esign->provider('signit')->send($envelope);    // any installed provider
```

## Add and remove providers

```bash
vendor/bin/esign providers        # every provider, and which are installed
vendor/bin/esign add sadq         # runs composer require hamada-emam-tech/esign-sadq
vendor/bin/esign remove sadq      # runs composer remove
```

It only runs Composer, so you can also manage the packages with Composer directly.

## Store the reference, not the bare ID

```php
$sent = $esign->send($envelope);
$contract->esign_ref = (string) $sent->ref();     // "sadq:4f1c…"
```

Every later call takes that reference and goes to the provider that created the envelope. Switching the `default` provider later never breaks envelopes that are already out for signing.

## Webhooks

```php
$result = $esign->handleWebhook($headers, $rawBody, 'sadq');   // never throws for a bad call
if ($result->isValid()) {
    dispatch(new SyncEnvelope((string) $result->ref()));       // do the rest in a queue
}
// Always send this answer (usually a plain 200; some providers need a specific body or header)
return response($result->response->body, $result->response->status, $result->response->headers);

// In the job: the provider's API is the source of truth, never the webhook body
$status = $esign->status($ref);
if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->download($ref);
}
```

## One webhook endpoint for every provider

```php
$handler = new WebhookHandler($esign, defer: fn (EnvelopeRef $ref) => dispatch(new SyncEnvelope((string) $ref)));
$result  = $handler->handle($headers, $rawBody, $provider);   // or null: each driver recognizes its own webhooks
return response($result->response->body, $result->response->status, $result->response->headers);

// In the job: the same sync for every provider
(new EnvelopeSynchronizer($esign, $listener))->sync($ref, $storedStatus);   // tells your listener, downloads the PDF once
```

See [webhooks](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/webhooks.md) for framework wiring, verification per provider and scheduled syncs.

## Optional features, the same way for every provider

```php
use HamadaEmamTech\Esign\Capabilities\Feature;

if ($esign->supports(Feature::Reminders)) {
    $esign->remind($ref, 'sara@example.sa');
}
$esign->extendDeadline($ref, new DateTimeImmutable('+14 days'));   // Feature::ExpiryExtension
$esign->details($ref)->pending();                                  // Feature::SignerDetails: who hasn't signed
$esign->registerWebhook($url, $secret);                            // Feature::WebhookManagement
$esign->capabilities()->maxDocuments;                              // e.g. 1 for SADQ
```

An unsupported feature always throws `UnsupportedFeature`. An envelope the provider can't handle (too many documents, an identity method it lacks, fields, signing order) throws `EnvelopeNotSupported`, listing every problem, before anything is sent. See [architecture](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/architecture.md).

## Test without a real provider

```php
$esign = new Esign(['default' => 'fake']);
$fake = $esign->provider();          // HamadaEmamTech\Esign\Testing\FakeProvider
$sent = $esign->send($envelope);
$fake->sign($sent->envelopeId);
$fake->assertSent(fn ($e) => $e->signers[0]->nationalId === '1012345678');
```

## Providers

| Install | Provider | Region | Status |
|---|---|---|---|
| `vendor/bin/esign add sadq` | SADQ | KSA | available, verified on SADQ's sandbox |
| `vendor/bin/esign add signit` | Signit | KSA | available, built from Signit's API and a production integration |
| `vendor/bin/esign add docusign` | DocuSign | global | available, built from DocuSign's API docs |
| `vendor/bin/esign add zoho` | Zoho Sign | global | available, built from Zoho's API docs |
| `vendor/bin/esign add dropbox` | Dropbox Sign | global | available, built from Dropbox Sign's API docs |
| `vendor/bin/esign add boldsign` | BoldSign | global | available, built from BoldSign's API docs |
| `vendor/bin/esign add adobe` | Adobe Acrobat Sign | global | available, built from Adobe's API docs |
| `vendor/bin/esign add uaepass` | UAE PASS | UAE | available, built from UAE PASS's docs |
| | emdha, CSC providers | | planned |

What each one supports, side by side: [providers](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/providers.md).

Want to add your own? See [writing a provider](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/writing-a-provider.md).

Framework-agnostic: PHP 8.2+, no framework dependency.
