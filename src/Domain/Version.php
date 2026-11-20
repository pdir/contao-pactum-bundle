<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Domain;

final readonly class Version
{
    /**
     * @param list<int> $annexIds
     */
    public function __construct(
        public int $id,
        public int $templateId,
        public int $versionNumber,
        public string $language,
        public string $content,
        public int|null $validFrom,
        public VersionStatus $status,
        public string $changeNote,
        public array $annexIds,
        public string|null $renderedContent,
        public string|null $annexSnapshot,
        public string|null $contentHash,
        public int|null $publishedAt,
    ) {
    }

    public function isPublished(): bool
    {
        return VersionStatus::Published === $this->status;
    }
}
