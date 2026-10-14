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
        "hamada-emam-tech/esign": "^1.0",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0"
    },
    "extra": {
        "esign": { "providers": { "<name>": "HamadaEmamTech\\Esign\\<Name>\\<Name>Factory" } }
    }
}
```

## 2. Rules every provider follows

1. Use PSR-18 for HTTP. Never hard-code Guzzle. The consumer brings their own client.
2. Map the provider's statuses onto `EnvelopeStatus`. Put the full mapping in a table in the README.
3. `parseWebhook()` verifies the raw body and returns which envelope changed. It never decides the status.
4. `status()` always asks the provider. This is the source of truth.
5. Throw `ProviderError` with the HTTP status and the provider's code. Never put credentials in a message.
6. Reject unsupported identity methods early, with a clear message.
7. Keep the core stateless. Do not ship storage. Document persistence in the provider's README instead.
8. Ship a test suite against a fake PSR-18 client that reproduces the provider's real traps.

## 3. Register it in the monorepo

1. Add the folder under `packages/`.
2. Add its autoload paths to the root `composer.json`.
3. Add a line to the matrix in `.github/workflows/split.yml`.
4. Create the empty target repo `hamada-emam-tech/esign-<name>` and submit it to Packagist once.
