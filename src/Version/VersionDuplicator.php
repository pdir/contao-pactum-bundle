<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Version;

use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;

/**
 * "Create new version from existing": the only way to change the text of a
 * published version is to create a new draft from it.
 */
class VersionDuplicator
{
    public function __construct(
        private readonly VersionRepository $versions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraftFrom(int $versionId): int
    {
        $source = $this->versions->find($versionId) ?? throw new InvalidVersionException(\sprintf('Version %d does not exist.', $versionId), translationKey: 'error.version_not_found', translationParameters: ['%id%' => $versionId]);

        return $this->versions->insertDraftFrom(
            $source,
            $this->versions->nextVersionNumber($source->templateId),
            $this->clock->now()->getTimestamp(),
        );
    }
}
