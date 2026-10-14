<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Envelope;

use InvalidArgumentException;

final readonly class Document
{
    public function __construct(
        public string $name,
        public string $contents,
        public string $mimeType = 'application/pdf',
    ) {
        if ($contents === '') {
            throw new InvalidArgumentException("Document \"{$name}\" is empty.");
        }
    }

    public static function fromPath(string $path, ?string $name = null): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read document at {$path}.");
        }

        return new self($name ?? basename($path), $contents);
    }
}
