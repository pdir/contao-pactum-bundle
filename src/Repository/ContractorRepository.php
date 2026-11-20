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

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\Contractor;

class ContractorRepository
{
    public const TABLE = 'tl_pactum_contractor';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): Contractor|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Contractor
    {
        $logo = $row['logo'] ?? null;

        return new Contractor(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['street'],
            (string) $row['postal'],
            (string) $row['city'],
            (string) $row['country'],
            (string) $row['representative'],
            (string) $row['dpo_name'],
            (string) ($row['dpo_contact'] ?? ''),
            (string) $row['contact_email'],
            (string) $row['sender_email'],
            (string) $row['sender_name'],
            (string) $row['bcc_email'],
            \is_string($logo) && 16 === \strlen($logo) ? $logo : null,
        );
    }
}
