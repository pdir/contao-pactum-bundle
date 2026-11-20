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

        $result = $this->diff($annex->subprocessors, $subprocessors, $dryRun);

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
    private function diff(array $current, array $new, bool $dryRun): SubprocessorImportResult
    {
        $currentByName = [];

        foreach ($current as $sub) {
            $currentByName[mb_strtolower($sub->name)] = $sub;
        }

        $added = [];
        $changed = [];
        $unchanged = 0;

        foreach ($new as $sub) {
            $key = mb_strtolower($sub->name);

            if (!isset($currentByName[$key])) {
                $added[] = $sub->name;
            } elseif ($currentByName[$key]->toArray() !== $sub->toArray()) {
                $changed[] = $sub->name;
            } else {
                ++$unchanged;
            }

            unset($currentByName[$key]);
        }

        $removed = array_values(array_map(static fn (Subprocessor $s): string => $s->name, $currentByName));

        // A pure re-ordering is a change too, as the order is shown in the contract.
        if ([] === $added && [] === $removed && [] === $changed && array_map(static fn (Subprocessor $s): string => mb_strtolower($s->name), $current) !== array_map(static fn (Subprocessor $s): string => mb_strtolower($s->name), $new)) {
            $changed = array_map(static fn (Subprocessor $s): string => $s->name, $new);
            $unchanged = 0;
        }

        return new SubprocessorImportResult($added, $removed, $changed, $unchanged, $dryRun);
    }
}
