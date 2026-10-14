# hamada-emam-tech/esign

One interface for e-signature providers. Install the core, then only the providers you use.

```bash
composer require hamada-emam-tech/esign          # the core: contracts + a fake provider for tests
composer require hamada-emam-tech/esign-sadq     # add SADQ (coming soon)
composer require hamada-emam-tech/esign-signit   # add Signit, and so on (planned)
```

Installing a provider package is enough. The core finds it automatically.

> **Status:** v0.1 ships the core and the `fake` provider. Provider packages are being built one at a time, SADQ first. You can already write your own with `extend()` (see [writing a provider](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/writing-a-provider.md)).

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

## Webhooks

```php
$event = $esign->parseWebhook($headers, $rawBody, 'sadq');   // throws InvalidWebhook if verification fails
$status = $esign->provider('sadq')->status($event->envelopeId); // never trust the webhook body for the status

if ($status === EnvelopeStatus::Signed) {
    $pdf = $esign->provider('sadq')->download($event->envelopeId);
}
```

Answer the webhook with 200 even when verification fails, and log it. A non-200 makes most providers retry.

## Test without a real provider

```php
$esign = new Esign(['default' => 'fake']);
$fake = $esign->provider();          // HamadaEmamTech\Esign\Testing\FakeProvider
$sent = $esign->send($envelope);
$fake->sign($sent->envelopeId);
$fake->assertSent(fn ($e) => $e->signers[0]->nationalId === '1012345678');
```

## Providers

| Package | Provider | Status |
|---|---|---|
| `hamada-emam-tech/esign-sadq` | SADQ (Saudi) | in progress |
| `hamada-emam-tech/esign-signit` | Signit (Saudi, DGA-licensed TSP) | planned |
| `hamada-emam-tech/esign-emdha` | emdha (Saudi, DGA-licensed TSP) | planned |
| `hamada-emam-tech/esign-docusign` | DocuSign (global) | planned |

Want to add your own? See [writing a provider](../../docs/writing-a-provider.md).

Framework-agnostic: PHP 8.2+, no framework dependency.
