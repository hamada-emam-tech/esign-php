# Providers

Each provider is its own package (`hamada-emam-tech/esign-<name>`). Install only the ones you use: `vendor/bin/esign add <name>`.

## Available now

| | SADQ | Signit | DocuSign | Zoho Sign | Dropbox Sign | BoldSign | Adobe Sign | UAE PASS |
|---|---|---|---|---|---|---|---|---|
| Package | `esign-sadq` | `esign-signit` | `esign-docusign` | `esign-zoho` | `esign-dropbox` | `esign-boldsign` | `esign-adobe` | `esign-uaepass` |
| Region | KSA | KSA | global | global (incl. KSA data centre) | global | global | global | UAE |
| Identity methods | email, nafath | email, nafath | email, sms_otp¹ | email, sms_otp | email, sms_otp | email, sms_otp | email, sms_otp | uae_pass |
| Documents per envelope | 1 | 1 | no limit | 10 | no limit | 25 | no limit | 1 |
| Signers per envelope | no limit | no limit | no limit | no limit | no limit | no limit | no limit | 1 |
| Signing order | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Field placement | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Signing links from `send()` | ✓ | ✓ | — | — | — | — | — | ✓ |
| `remind()` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| `extendDeadline()` | ✓ | — | ✓ | ✓ | — | ✓ | — | — |
| `details()` (per-signer) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Webhook management via API | ✓ | — | — | — | — | — | ✓ | — |
| Webhook check | optional token | AES-256 encrypted | HMAC-SHA256 | HMAC-SHA256 | `event_hash` HMAC | HMAC + timestamp | client-ID echo | browser redirect (unsigned) |
| Cancel | async (minutes) | immediate (void) | immediate | immediate | async | immediate | immediate | deletes the process |
| Verified against a live account | **✓ sandbox** | production integration's behaviour | docs only | docs only | docs only | docs only | docs only | docs only |

¹ DocuSign SMS needs `phone_auth_workflow_id` in the config.

Every value in this table is declared in code by the driver's `capabilities()`. The core checks envelopes against it before sending, and `$esign->supports(Feature::…)` reads it, so portable code never has to hard-code this table. "Docs only" drivers were built from the provider's official API reference, with tests against a fake server shaped like the documented responses. Each README lists its sources and the points to confirm on a live account.

## Two kinds of provider

| Kind | What it does | Examples | Fits the current `SignatureProvider` contract? |
|---|---|---|---|
| **Envelope platform** | You send documents and signers. People sign on the provider's site. A webhook tells you when it is done. | SADQ, Signit, DocuSign, Zoho Sign | Yes: `send` → webhook → `status` → `download` |
| **Remote signing service** | A licensed trust provider signs a PDF or a hash with the user's own certificate, after the user authorizes it (OTP, Nafath, UAE PASS). There are no envelopes and no workflow. | emdha eSign Authenticator, UAE PASS, CSC-based providers | Partly. One signer through a redirect fits as a one-signer envelope. Signing a raw hash needs a second contract (see [Contract work](#contract-work-before-wave-3)). |

## Wave 1: Saudi envelope platforms (Nafath)

| Package | Provider | Identity | API docs | Status |
|---|---|---|---|---|
| `esign-sadq` | SADQ | Email, Nafath | SADQ's Postman collection; behaviour verified on the sandbox | **available** |
| `esign-signit` | [Signit](https://signit.sa/en) (DGA-licensed) | Nafath, email | Signit's API collection, plus the request and response handling of a production integration. | **available** |

## Wave 2: global envelope platforms

All of these have public API references and free developer or test accounts, so they can be built and tested right away. They also prove the core contract against real APIs while Wave 1 waits for sandbox access.

| Package | Provider | Why | Webhook verification | Status |
|---|---|---|---|---|
| `esign-docusign` | DocuSign | The global default and the most-requested integration | Connect, HMAC-SHA256 | **available** |
| `esign-zoho` | Zoho Sign | Has a Saudi data centre. Advanced signatures in KSA go through emdha, with Nafath verification. | HMAC-SHA256 | **available** |
| `esign-dropbox` | Dropbox Sign (HelloSign) | Simple API, free test mode | `event_hash` HMAC | **available** |
| `esign-adobe` | Adobe Acrobat Sign | Enterprise accounts | Client-ID echo header | **available** |
| `esign-boldsign` | BoldSign | Low-cost API, free sandbox | HMAC | **available** |

## Wave 3: remote signing with a certificate

| Package | Provider | Region | Notes | Status |
|---|---|---|---|---|
| `esign-emdha` | [emdha](https://www.emdha.sa/) | KSA | CST-licensed, DGA-regulated trust provider. No public API reference. Reachable today through Signit, which accepts emdha as the signature provider per signer. If emdha confirms the CSC API, it becomes a configuration of `esign-csc`. | planned |
| `esign-uaepass` | [UAE PASS](https://docs.uaepass.ae) | UAE | Government signing API with public docs. A fully verified account gives a qualified signature. The user must log in with UAE PASS first. Onboarding requires an entity. One signer per process, no webhook. | **available** |
| `esign-csc` | Any [CSC API](https://cloudsignatureconsortium.org) provider | EU and more | One driver for every provider that speaks the Cloud Signature Consortium standard (most EU qualified trust providers). Needs the `RemoteSigner` contract and PAdES signing in PHP (or providers that support `signDoc`). It shares a CSC client with `einvoice-keysource-csc`. | planned |

## Egypt: research only

Egypt's licensed certification providers (Egypt Trust, MCDR, Fixed Misr and El-Delta, per [ITIDA](https://itida.gov.eg/English/Pages/E-Signature.aspx)) mostly issue **USB tokens**. None of them publishes a remote signing API. Token signing goes through the Signing Agent in `hamada-emam-tech/einvoice`. Revisit this if one of them launches a remote or CSC API.

## Contract work

Done in core 0.3: capabilities checked by the core, optional feature interfaces, `EnvelopeRef` routing, `details()`, `handleWebhook()`, the `Failed` status, the `absher`, `wathq` and `uae_pass` identity methods, and browser-redirect completion (UAE PASS). See [architecture](architecture.md).

Still to do for CSC providers: a separate `RemoteSigner` contract for signing a hash or PDF with a user's certificate, plus PAdES signing in PHP. Two different lifecycles should not share one interface.

## Every provider ships with

The definition of done in [writing a provider](writing-a-provider.md): PSR-18 only, a full status-mapping table, raw-body webhook verification tests (valid, tampered, empty secret), a fake HTTP client that reproduces the provider's real traps, and a `getting-started-with-<provider>.md`.
