# hamada-emam-tech/esign

One interface for e-signature providers. Install the core, then only the providers you use.

```bash
composer require hamada-emam-tech/esign          # the core: contracts + a fake provider for tests
vendor/bin/esign add sadq                         # add SADQ (runs composer require hamada-emam-tech/esign-sadq)
composer require hamada-emam-tech/esign-signit   # add Signit, and so on (planned)
```

Installing a provider package is enough. The core finds it automatically.

> **Status:** v0.2 ships the core, the `fake` provider, the `vendor/bin/esign` command, and the SADQ driver. More providers follow one at a time. You can already write your own with `extend()` (see [writing a provider](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/writing-a-provider.md)).

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
| `hamada-emam-tech/esign-sadq` | SADQ (Saudi) | available: `vendor/bin/esign add sadq` |
| `hamada-emam-tech/esign-signit` | Signit (Saudi, DGA-licensed TSP) | planned |
| `hamada-emam-tech/esign-emdha` | emdha (Saudi, DGA-licensed TSP) | planned |
| `hamada-emam-tech/esign-docusign` | DocuSign (global) | planned |

Full roadmap, with Zoho Sign, Dropbox Sign, UAE PASS and CSC providers: [providers](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/providers.md).

Want to add your own? See [writing a provider](https://github.com/hamada-emam-tech/esign-php/blob/main/docs/writing-a-provider.md).

Framework-agnostic: PHP 8.2+, no framework dependency.
