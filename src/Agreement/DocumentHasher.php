<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Agreement;

/**
 * SHA-256 hashing of version texts, final documents and PDF files.
 */
class DocumentHasher
{
    public const ALGORITHM = 'sha256';

    public function hash(string $content): string
    {
        return hash(self::ALGORITHM, $content);
    }

    public function hashFile(string $path): string|null
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $hash = hash_file(self::ALGORITHM, $path);

        return false === $hash ? null : $hash;
    }

    public function matches(string $expectedHash, string $content): bool
    {
        return '' !== $expectedHash && hash_equals($expectedHash, $this->hash($content));
    }
}
