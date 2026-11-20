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
use Pdir\PactumBundle\Domain\ContractTemplate;

class TemplateRepository
{
    public const TABLE = 'tl_pactum_template';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): ContractTemplate|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        if (false === $row) {
            return null;
        }

        return new ContractTemplate(
            (int) $row['id'],
            (string) $row['title'],
            (int) $row['contractor'],
            (int) $row['target_page'],
            (int) $row['invitation_ttl_days'],
        );
    }

    /**
     * Locks the template row until the end of the current transaction, used to
     * serialize publishing of versions of the same template.
     */
    public function lockForUpdate(int $id): void
    {
        $this->connection->executeQuery('SELECT id FROM '.self::TABLE.' WHERE id = ? FOR UPDATE', [$id]);
    }
}
