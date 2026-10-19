<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Capabilities;

use HamadaEmamTech\Esign\Envelope\IdentityMethod;

/**
 * What one provider can do. Every driver declares it, so the core can check an envelope
 * the same way for every provider, before anything is sent.
 */
final readonly class Capabilities
{
    /** Features that come with a method, and the interface a provider implements for it. */
    public const INTERFACES = [
        'reminders' => \HamadaEmamTech\Esign\Contracts\Features\SendsReminders::class,
        'expiry_extension' => \HamadaEmamTech\Esign\Contracts\Features\ExtendsExpiry::class,
        'webhook_management' => \HamadaEmamTech\Esign\Contracts\Features\ManagesWebhooks::class,
        'signer_details' => \HamadaEmamTech\Esign\Contracts\Features\ReportsSigners::class,
    ];

    /**
     * @param list<IdentityMethod> $identityMethods how signers may prove who they are
     * @param list<Feature>        $features
     * @param int|null             $maxDocuments    null means no limit the driver knows of
     * @param int|null             $maxSigners      null means no limit the driver knows of
     */
    public function __construct(
        public array $identityMethods,
        public array $features = [],
        public ?int $maxDocuments = null,
        public bool $asyncCancel = false,
        public ?int $maxSigners = null,
    ) {
    }

    /** For a driver that declares nothing: the core then checks nothing and leaves it to the driver. */
    public static function undeclared(): self
    {
        return new self(IdentityMethod::cases(), Feature::cases());
    }

    public function supports(Feature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function allowsIdentity(IdentityMethod $method): bool
    {
        return in_array($method, $this->identityMethods, true);
    }
}
