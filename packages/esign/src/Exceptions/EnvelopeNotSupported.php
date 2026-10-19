<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Exceptions;

/** The envelope asks for something the chosen provider can't do. Nothing was sent. */
final class EnvelopeNotSupported extends EsignException
{
    /** @param list<string> $problems */
    public function __construct(public readonly string $provider, public readonly array $problems)
    {
        parent::__construct(sprintf("Provider \"%s\" can't send this envelope:\n- %s", $provider, implode("\n- ", $problems)));
    }
}
