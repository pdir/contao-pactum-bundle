<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Subprocessor;

class AnnexRepository
{
    public const TABLE = 'tl_pactum_annex';

    public const SUBPROCESSOR_TABLE = 'tl_pactum_subprocessor';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(AnnexType $type, string $title, string $content, int $timestamp): int
    {
        $this->connection->insert(self::TABLE, [
            'tstamp' => $timestamp,
            'type' => $type->value,
            'title' => $title,
            'content' => $content,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Replaces all subprocessors of an annex. Call inside a transaction.
     *
     * @param list<Subprocessor> $subprocessors
     *
     * @return int the number of removed rows
     */
    public function replaceSubprocessors(int $annexId, array $subprocessors, int $timestamp): int
    {
        $removed = (int) $this->connection->delete(self::SUBPROCESSOR_TABLE, ['pid' => $annexId]);

        foreach ($subprocessors as $index => $subprocessor) {
            $this->connection->insert(self::SUBPROCESSOR_TABLE, [
                'pid' => $annexId,
                'sorting' => ($index + 1) * 128,
                'tstamp' => $timestamp,
                'name' => $subprocessor->name,
                'address' => $subprocessor->address,
                'service' => $subprocessor->service,
                'location' => $subprocessor->location,
                'transfer_basis' => $subprocessor->transferBasis,
            ]);
        }

        $this->connection->update(self::TABLE, ['tstamp' => $timestamp], ['id' => $annexId]);

        return $removed;
    }

    /**
     * Returns the annexes in the given order, including their subprocessors.
     *
     * @param list<int> $ids
     *
     * @return list<Annex>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE.' WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $subRows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::SUBPROCESSOR_TABLE.' WHERE pid IN (?) ORDER BY sorting, id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $subprocessors = [];

        foreach ($subRows as $row) {
            $subprocessors[(int) $row['pid']][] = new Subprocessor(
                (string) $row['name'],
                (string) ($row['address'] ?? ''),
                (string) ($row['service'] ?? ''),
                (string) $row['location'],
                (string) $row['transfer_basis'],
            );
        }

        $byId = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $type = AnnexType::tryFrom((string) $row['type']) ?? AnnexType::Custom;

            $byId[$id] = new Annex(
                $id,
                $type,
                (string) $row['title'],
                (string) ($row['content'] ?? ''),
                AnnexType::Subprocessor === $type ? ($subprocessors[$id] ?? []) : [],
            );
        }

        $result = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $result[] = $byId[$id];
            }
        }

        return $result;
    }
}
