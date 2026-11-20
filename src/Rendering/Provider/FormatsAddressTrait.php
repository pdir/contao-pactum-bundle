<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering\Provider;

/**
 * @internal
 */
trait FormatsAddressTrait
{
    /**
     * Multi-line plain-text address (line breaks become <br> when escaped).
     */
    private function formatAddress(string $street, string $postal, string $city, string $country): string
    {
        $lines = [
            trim($street),
            trim($postal.' '.$city),
            trim($country),
        ];

        return implode("\n", array_filter($lines, static fn (string $line): bool => '' !== $line));
    }
}
