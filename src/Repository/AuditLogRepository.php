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

/**
 * Append-only. The only deleting method is used by the retention clean-up when
 * the agreement itself is deleted.
 */
class AuditLogRepository
{
    public const TABLE = 'tl_pactum_audit_log';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function append(string $event, int $timestamp, string $actor, int $invitationId = 0, int $agreementId = 0, array $context = []): void
    {
        $this->connection->insert(self::TABLE, [
            'tstamp' => $timestamp,
            'created_at' => $timestamp,
            'invitation' => $invitationId,
            'agreement' => $agreementId,
            'event' => $event,
            'actor' => $actor,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array{id: int, created_at: int, invitation: int, agreement: int, event: string, actor: string, context: array<string, mixed>}>
     */
    public function findForInvitationOrAgreement(int $invitationId, int $agreementId = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM '.self::TABLE.' WHERE (invitation = ? AND invitation > 0) OR (agreement = ? AND agreement > 0) ORDER BY created_at, id',
            [$invitationId, $agreementId],
        );

        return array_map(
            static function (array $row): array {
                $context = json_decode((string) ($row['context'] ?? ''), true);

                return [
                    'id' => (int) $row['id'],
                    'created_at' => (int) $row['created_at'],
                    'invitation' => (int) $row['invitation'],
                    'agreement' => (int) $row['agreement'],
                    'event' => (string) $row['event'],
                    'actor' => (string) $row['actor'],
                    'context' => \is_array($context) ? $context : [],
                ];
            },
            $rows,
        );
    }

    /**
     * Only for the retention clean-up (F9).
     */
    public function deleteForAgreement(int $agreementId, int $invitationId): void
    {
        $this->connection->executeStatement('DELETE FROM '.self::TABLE.' WHERE agreement = ? OR (invitation = ? AND invitation > 0)', [$agreementId, $invitationId]);
    }
}
