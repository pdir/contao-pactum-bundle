<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering;

/**
 * Implement this interface (services are autoconfigured and tagged with
 * "pactum.placeholder_provider") to provide additional placeholders.
 *
 * Keys must have the form "namespace.key" (lowercase, a-z, 0-9, _).
 */
interface PlaceholderProviderInterface
{
    public function getStage(): PlaceholderStage;

    /**
     * @return list<string>
     */
    public function getKeys(): array;

    /**
     * Plain strings are escaped, HtmlValue instances are sanitized. Values for keys
     * not returned by getKeys() are ignored.
     *
     * Values for the publish stage are frozen in the published version, values for
     * the agreement stage must be deterministic for the same context, otherwise
     * "pactum:verify" reports a re-rendering mismatch.
     *
     * @return array<string, string|HtmlValue>
     */
    public function provide(PlaceholderContext $context): array;
}
