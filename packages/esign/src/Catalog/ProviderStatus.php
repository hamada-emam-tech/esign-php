<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Catalog;

enum ProviderStatus: string
{
    /** Ships inside the core. */
    case BuiltIn = 'built-in';
    /** Released on Packagist: `vendor/bin/esign add <name>` installs it. */
    case Available = 'available';
    /** On the roadmap, not released yet. */
    case Planned = 'planned';
}
