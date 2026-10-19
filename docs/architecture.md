# Architecture

One workflow for every e-signature provider. Your code talks to the core only. Providers are separate Composer packages you can add, remove or switch without touching that code.

## Layers

```
 Your app (any framework, or plain PHP)
        │   uses only: Esign, Envelope, Signer, Document, SignatureField,
        │              EnvelopeRef, EnvelopeStatus, EnvelopeDetails, WebhookResult, Feature
        ▼
 ┌──────────────────────────── hamada-emam-tech/esign (core) ────────────────────────────┐
 │ Esign                 the one workflow: send, status, details, download, cancel,       │
 │                       remind, extendDeadline, handleWebhook, webhook management        │
 │ EnvelopeValidator     checks every envelope against the provider's Capabilities         │
 │ ProviderRegistry      finds installed drivers in Composer's metadata                    │
 │ ProviderCatalog + CLI vendor/bin/esign providers | add | remove                         │
 │ Contracts             SignatureProvider (required), HasCapabilities, Features\*          │
 │ Webhook / Sync        WebhookHandler (detect, verify, answer), EnvelopeSynchronizer,    │
 │                       EnvelopeListener, Psr7Bridge — see webhooks.md                    │
 │ Testing               FakeProvider, AssertsProviderConformance                          │
 └──────────────────────────────────────────────────────────────────────────────────────────┘
        ▲ implements the contracts, discovered on install
 ┌───────────────┐ ┌────────────────┐ ┌───────────────┐ ┌──────────────┐ ┌──────────────┐
 │ esign-sadq    │ │ esign-signit   │ │ esign-docusign│ │ esign-zoho   │ │ esign-…      │
 └───────────────┘ └────────────────┘ └───────────────┘ └──────────────┘ └──────────────┘
```

The core has **no dependencies** and no framework code. Drivers need only PSR-18/PSR-17 (any HTTP client) and, optionally, PSR-16 (any cache).

## The workflow (the same for every provider)

```php
$sent = $esign->send($envelope);                    // 1. checked against capabilities, then sent
$db->save((string) $sent->ref());                   // 2. store "provider:envelopeId"

$result = $esign->handleWebhook($headers, $body, 'sadq');   // 3. never throws
if ($result->isValid()) {
    queue(fn () => sync((string) $result->ref()));
}
return respond($result->response);                  //    always send this (usually a 200)

function sync(string $ref) {                        // 4. in a queued job
    $status = $esign->status($ref);                 //    the provider's API is the source of truth
    if ($status === EnvelopeStatus::Signed) {
        store($esign->download($ref));              // 5. the signed PDF
    }
}
```

A webhook body is never trusted. It only says *which* envelope to look at, and `status()` asks the provider.

`WebhookHandler` and `EnvelopeSynchronizer` package these steps so you don't write them yourself. One endpoint can even serve every provider, because each driver recognizes its own webhooks. See [webhooks](webhooks.md).

## Why switching providers can't break your code

| Mechanism | What it guarantees |
|---|---|
| **One contract** (`SignatureProvider`) | Every driver has the same six methods with the same types. |
| **One vocabulary** (`EnvelopeStatus`, `SignerStatus`, `IdentityMethod`) | Each driver maps its provider's words onto these and documents the table in its README. |
| **`EnvelopeRef`** (`"sadq:4f1c…"`) | Every call goes to the provider that created the envelope. Changing `default` only affects *new* envelopes. You can run several providers at once. |
| **Capabilities**, checked by the core | `send()` refuses an envelope the provider can't handle (too many documents, an identity method it lacks, fields, signing order), lists every problem at once, and sends nothing. The same check runs for every provider. |
| **Optional features as interfaces** (`SendsReminders`, `ExtendsExpiry`, `ManagesWebhooks`, `ReportsSigners`, `CustomizesWebhookResponse`) | `$esign->supports(Feature::Reminders)` tells you in advance. Calling an unsupported feature always throws `UnsupportedFeature`, whichever provider it is. `supports()` is true only if the driver both declares the feature and implements it. |
| **`handleWebhook()`** | One call for every provider. It returns the exact response to send, including providers that want a special body or header, and never throws for a forged or broken call. |
| **One exception hierarchy** (`EsignException`) | `ProviderError` (with HTTP status and provider code, never credentials), `EnvelopeNotSupported`, `UnsupportedFeature`, `InvalidWebhook`, `ProviderNotInstalled`, `ProviderNotConfigured`. |
| **Conformance test** (`AssertsProviderConformance`) | Every driver's own tests must pass it: the name matches, capabilities are declared, declared features match implemented interfaces, and Composer discovery is set up. |

Write portable code by asking first:

```php
if ($esign->supports(Feature::Reminders, 'sadq')) {
    $esign->remind($ref, $email);
}
$esign->capabilities('sadq')->maxDocuments;   // 1
```

## Install, remove, switch

```bash
vendor/bin/esign providers        # the catalog, and what's installed
vendor/bin/esign add docusign     # = composer require hamada-emam-tech/esign-docusign
vendor/bin/esign remove sadq      # = composer remove hamada-emam-tech/esign-sadq
```

Installing a driver is enough: the core reads `extra.esign.providers` from Composer's installed packages. To switch, change `default` in the config. Envelopes created before the switch keep working, because their references name their provider. Your own driver plugs in with `$esign->extend('inhouse', MyFactory::class)`, and `extend()` always wins over discovery.

## Using it from a framework

The core is plain PHP objects, so a framework only has to build one `Esign` and hand it out:

- **Laravel**: bind `Esign` as a singleton in a service provider, with the config from `config/esign.php`. The webhook route returns `response($r->response->body, $r->response->status, $r->response->headers)`.
- **Symfony**: register `Esign` as a service with its config array. In the webhook controller, return `new Response($r->response->body, $r->response->status, $r->response->headers)`.
- **Plain PHP**: `new Esign([...])`, then `http_response_code($r->response->status)` and `echo $r->response->body`.

Persistence (where you keep refs, statuses and signed PDFs) is yours. The core stores nothing.
