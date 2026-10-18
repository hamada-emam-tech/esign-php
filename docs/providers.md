# Provider roadmap

Each provider is its own package (`hamada-emam-tech/esign-<name>`). Install only the ones you use.

The order below follows three questions: does the Saudi market need it, can it be built from public API docs, and can it be tested without a paid contract.

## Two kinds of provider

| Kind | What it does | Examples | Fits the current `SignatureProvider` contract? |
|---|---|---|---|
| **Envelope platform** | You send documents and signers. People sign on the provider's site. A webhook tells you when it is done. | SADQ, Signit, DocuSign, Zoho Sign | Yes: `send` → webhook → `status` → `download` |
| **Remote signing service** | A licensed trust provider signs a PDF or a hash with the user's own certificate, after the user authorizes it (OTP, Nafath, UAE PASS). There are no envelopes and no workflow. | emdha eSign Authenticator, UAE PASS, CSC-based providers | Partly. One signer through a redirect fits as a one-signer envelope. Signing a raw hash needs a second contract (see [Contract work](#contract-work-before-wave-3)). |

## Wave 1: Saudi envelope platforms (Nafath)

| Package | Provider | Identity | API docs | Status |
|---|---|---|---|---|
| `esign-sadq` | SADQ | Email, Nafath | Postman collection from SADQ; behaviour verified on the sandbox | **built** (v0.2.0) |
| `esign-signit` | [Signit](https://signit.sa/en) (DGA-licensed) | Nafath, Absher, Wathq (authorized signatory) | API exists ([help center](https://help.signit.sa/en/migrated/integrations-and-api)). The endpoint reference is on request. | planned |

## Wave 2: global envelope platforms

All of these have public API references and free developer or test accounts, so they can be built and tested right away. They also prove the core contract against real APIs while Wave 1 waits for sandbox access.

| Package | Provider | Why | Webhook verification | Status |
|---|---|---|---|---|
| `esign-docusign` | DocuSign | The global default and the most-requested integration | Connect, HMAC-SHA256 | planned: build first in this wave |
| `esign-zoho` | Zoho Sign | Has a Saudi data centre. Advanced signatures in KSA go through emdha, with Nafath verification. | HMAC-SHA256 | planned |
| `esign-dropbox` | Dropbox Sign (HelloSign) | Simple API, free test mode | `event_hash` HMAC | planned |
| `esign-adobe` | Adobe Acrobat Sign | Enterprise accounts | Client-ID echo header | on demand |
| `esign-boldsign` | BoldSign | Low-cost API, free sandbox | HMAC | on demand |

## Wave 3: remote signing with a certificate

| Package | Provider | Region | Notes | Status |
|---|---|---|---|---|
| `esign-emdha` | [emdha](https://www.emdha.sa/) | KSA | CST-licensed, DGA-regulated trust provider. Its eSign Authenticator REST API signs with the user's certificate after Nafath or Iqama authentication. It is a CSC member, so check whether it offers the CSC API. API docs come through sales. | planned |
| `esign-uaepass` | [UAE PASS](https://docs.uaepass.ae) | UAE | Government signing API with public docs. A fully verified account gives a qualified signature. The user must log in with UAE PASS first. Onboarding requires an entity. | planned |
| `esign-csc` | Any [CSC API](https://cloudsignatureconsortium.org) provider | EU and more | One driver for every provider that speaks the Cloud Signature Consortium standard (most EU qualified trust providers). It shares a CSC client with `einvoice-keysource-csc`. | planned |

## Egypt: research only

Egypt's licensed certification providers (Egypt Trust, MCDR, Fixed Misr and El-Delta, per [ITIDA](https://itida.gov.eg/English/Pages/E-Signature.aspx)) mostly issue **USB tokens**. None of them publishes a remote signing API. Token signing goes through the Signing Agent in `hamada-emam-tech/einvoice`. Revisit this if one of them launches a remote or CSC API.

## Contract work before Wave 3

The core needs these changes before 1.0. All of them are additive:

1. **Capabilities.** Add `supports(Feature)` for signing order, field placement, cancel, resend and each identity method, so an app can check before it sends. Today a provider can only reject at `send()`.
2. **More identity methods.** `Absher`, `Wathq` (Signit) and `UaePass`, next to `Email`, `SmsOtp` and `Nafath`.
3. **Redirect completion.** UAE PASS and remote signers return the user to a redirect URL instead of calling a webhook. Add a `parseCallback()` path, or document that the redirect handler calls `status()`.
4. **A `RemoteSigner` contract** for signing a hash or PDF with a user's certificate (emdha, CSC), separate from envelopes. Two different lifecycles should not share one interface.

## Every provider ships with

The definition of done in [writing a provider](writing-a-provider.md): PSR-18 only, a full status-mapping table, raw-body webhook verification tests (valid, tampered, empty secret), a fake HTTP client that reproduces the provider's real traps, and a `getting-started-with-<provider>.md`.
