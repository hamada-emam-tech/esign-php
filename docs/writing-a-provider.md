# Writing a provider package

Every provider (SADQ, Signit, emdha, DocuSign…) is its own Composer package that requires the core.

## 1. The package

```
packages/esign-<name>/
├── composer.json
├── src/<Name>Factory.php      implements ProviderFactory
├── src/<Name>Provider.php     implements SignatureProvider
├── tests/
└── README.md
```

`composer.json` declares the factory. This is how the core discovers the provider:

```json
{
    "name": "hamada-emam-tech/esign-<name>",
    "require": {
        "php": "^8.2",
        "hamada-emam-tech/esign": "^0.3",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0"
    },
    "extra": {
        "esign": { "providers": { "<name>": "HamadaEmamTech\\Esign\\<Name>\\<Name>Factory" } }
    }
}
```

## 2. What every provider implements

| Interface | Required? | For |
|---|---|---|
| `SignatureProvider` | **yes** | `name`, `send`, `status`, `download`, `cancel`, `parseWebhook` |
| `HasCapabilities` | **yes** | identity methods, max documents and signers, features, async cancel |
| `Features\RecognizesWebhooks` | **yes** | `recognizesWebhook()`: a cheap check on headers and body shape, so one endpoint can serve every provider. Never a verification. |
| `Features\ReportsSigners` | if the provider exposes per-signer status | `details()` |
| `Features\SendsReminders` | if the provider can re-send a signing link without duplicating the signer | `remind()` |
| `Features\ExtendsExpiry` | if deadlines can be moved | `extend()` |
| `Features\ManagesWebhooks` | if webhooks are registered through the API | `registerWebhook()`, `deleteWebhook()`, `webhooks()` |
| `Features\CustomizesWebhookResponse` | if the provider expects a specific answer to a webhook | `webhookResponse()` |

Declare a feature in `capabilities()` **only** when you implement its interface. The conformance test enforces this.

## 3. Rules every provider follows

1. Use PSR-18 for HTTP. Never hard-code Guzzle. The consumer brings their own client.
2. Map every provider status onto `EnvelopeStatus`, and signer states onto `SignerStatus`. Put the full mapping in a table in the README. An unknown status throws `ProviderError`; never guess.
3. `parseWebhook()` verifies what can be verified and returns *which* envelope changed. It never decides the status.
4. `status()` always asks the provider. This is the source of truth.
5. `download()` returns the signed PDF only. Never return an unsigned original.
6. Throw `ProviderError` with the HTTP status and the provider's code. Never put credentials in a message.
7. Reject what the provider can't do before any HTTP call. The core already checks capabilities, but the driver must be safe on its own too.
8. Keep the driver stateless. Don't ship storage. Document persistence in the README instead.
9. Ship tests against a fake PSR-18 server that reproduces the provider's real behaviour, and call `assertProviderConformance()` from the core's `Testing\AssertsProviderConformance`.
10. Say in the README whether the driver was verified against a live account or built from the provider's documentation only.

## 4. Register it in the monorepo

1. Add the folder under `packages/`.
2. Add its autoload paths to the root `composer.json`.
3. Add a line to the matrix in `.github/workflows/split.yml`.
4. Set the provider to `Available` in `packages/esign/src/Catalog/ProviderCatalog.php`.
5. Create the empty target repo `hamada-emam-tech/esign-<name>` and submit it to Packagist once.
