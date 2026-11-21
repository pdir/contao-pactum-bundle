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

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Psr\Clock\ClockInterface;

/**
 * Replaces the subprocessors of a subprocessor annex with the given list.
 *
 * Published versions are not affected: they contain a frozen snapshot of their
 * annexes. The new list only takes effect once a new version is published.
 */
class SubprocessorImporter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AnnexRepository $annexes,
        private readonly ClockInterface $clock,
        private readonly SubprocessorDiff $diff = new SubprocessorDiff(),
    ) {
    }

    /**
     * @param list<Subprocessor> $subprocessors
     *
     * @throws PactumException
     */
    public function import(int $annexId, array $subprocessors, bool $dryRun = false): SubprocessorImportResult
    {
        if ([] === $subprocessors) {
            throw new PactumException('The list of subprocessors must not be empty.');
        }

        $annex = $this->annexes->findByIds([$annexId])[0] ?? null;

        if (null === $annex) {
            throw new PactumException(\sprintf('Annex %d does not exist.', $annexId));
        }

        if (AnnexType::Subprocessor !== $annex->type) {
            throw new PactumException(\sprintf('Annex %d is not a subprocessor annex (type "%s").', $annexId, $annex->type->value));
        }

        $result = $this->buildResult($annex->subprocessors, $subprocessors, $dryRun);

        if (!$dryRun && $result->hasChanges()) {
            $now = $this->clock->now()->getTimestamp();

            $this->connection->transactional(
                fn () => $this->annexes->replaceSubprocessors($annexId, $subprocessors, $now),
            );
        }

        return $result;
    }

    /**
     * @param list<Subprocessor> $current
     * @param list<Subprocessor> $new
     */
    private function buildResult(array $current, array $new, bool $dryRun): SubprocessorImportResult
    {
        $changes = $this->diff->compare($current, $new);

        $added = SubprocessorChanges::names($changes->added);
        $changed = SubprocessorChanges::names($changes->changed);
        $removed = SubprocessorChanges::names($changes->removed);
        $unchanged = $changes->unchanged;

        // A pure re-ordering is a change too, as the order is shown in the contract.
        if (!$changes->hasContentChanges() && $changes->reordered) {
            $changed = SubprocessorChanges::names($new);
            $unchanged = 0;
        }

        return new SubprocessorImportResult($added, $removed, $changed, $unchanged, $dryRun);
    }
}
