<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Capabilities;

/** Optional things a provider may or may not do. Ask with Esign::supports() before relying on one. */
enum Feature: string
{
    /** More than one document in an envelope. */
    case MultipleDocuments = 'multiple_documents';
    /** Signers sign one after another, in Signer::$order. */
    case SigningOrder = 'signing_order';
    /** Signature boxes at exact positions (SignatureField). */
    case FieldPlacement = 'field_placement';
    /** Signing links come back from send(), for your own UI. */
    case SigningUrls = 'signing_urls';
    /** Email a signer the link again: Esign::remind(). */
    case Reminders = 'reminders';
    /** Move the deadline of open invitations: Esign::extend(). */
    case ExpiryExtension = 'expiry_extension';
    /** Register and remove webhook URLs through the API: Esign::registerWebhook(). */
    case WebhookManagement = 'webhook_management';
    /** Per-signer status: Esign::details(). */
    case SignerDetails = 'signer_details';
}
