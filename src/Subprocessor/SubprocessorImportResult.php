<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Subprocessor;

final readonly class SubprocessorImportResult
{
    /**
     * @param list<string> $added     names of new subprocessors
     * @param list<string> $removed   names of subprocessors no longer listed
     * @param list<string> $changed   names of subprocessors with changed data
     * @param int          $unchanged number of unchanged subprocessors
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
        public int $unchanged,
        public bool $dryRun,
    ) {
    }

    public function hasChanges(): bool
    {
        return [] !== $this->added || [] !== $this->removed || [] !== $this->changed;
    }
}
