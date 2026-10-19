<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Support;

/** Header lookups that ignore case and accept both string and list values, as frameworks differ. */
final class Headers
{
    /** @param array<string, string|string[]> $headers */
    public static function get(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $first = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($first) ? $first : null;
            }
        }

        return null;
    }

    /** @param array<string, string|string[]> $headers */
    public static function has(array $headers, string $name): bool
    {
        return self::get($headers, $name) !== null;
    }
}
