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
use Pdir\PactumBundle\Domain\Announcement;
use Pdir\PactumBundle\Domain\AnnouncementRecipient;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;

/**
 * Announcements are evidence: apart from the insert, only the mail status, the
 * objection (once, conditionally) and the counters are ever updated.
 */
class AnnouncementRepository
{
    public const TABLE = 'tl_pactum_announcement';

    public const RECIPIENT_TABLE = 'tl_pactum_announcement_recipient';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): Announcement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * The most recent announcement made for a (draft) version.
     */
    public function findLatestForVersion(int $versionId): Announcement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE version = ? ORDER BY sent_at DESC, id DESC LIMIT 1', [$versionId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function insert(string $uuid, int $templateId, int $versionId, string $language, SubprocessorChanges $changes, int $noticeDays, int $sentAt, int $effectiveAt, int $createdBy): int
    {
        $this->connection->insert(self::TABLE, [
            'tstamp' => $sentAt,
            'uuid' => $uuid,
            'template' => $templateId,
            'version' => $versionId,
            'language' => $language,
            'changes' => json_encode($changes->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'notice_days' => $noticeDays,
            'sent_at' => $sentAt,
            'effective_at' => $effectiveAt,
            'created_by' => $createdBy,
            'recipient_count' => 0,
            'objection_count' => 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function insertRecipient(int $announcementId, int $agreementId, string $company, string $signatoryName, string $email, string $language, string $tokenHash, int $timestamp): int
    {
        $this->connection->insert(self::RECIPIENT_TABLE, [
            'pid' => $announcementId,
            'tstamp' => $timestamp,
            'agreement' => $agreementId,
            'company' => $company,
            'signatory_name' => $signatoryName,
            'email' => $email,
            'language' => $language,
            'token_hash' => $tokenHash,
            'mail_status' => MailStatus::Pending->value,
        ]);

        // Read the ID before the counter update, which resets the last insert ID
        $id = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement('UPDATE '.self::TABLE.' SET recipient_count = recipient_count + 1 WHERE id = ?', [$announcementId]);

        return $id;
    }

    public function findRecipient(int $id): AnnouncementRecipient|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::RECIPIENT_TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrateRecipient($row);
    }

    public function findRecipientByTokenHash(string $hash): AnnouncementRecipient|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::RECIPIENT_TABLE.' WHERE token_hash = ?', [$hash]);

        return false === $row ? null : $this->hydrateRecipient($row);
    }

    /**
     * @return list<AnnouncementRecipient>
     */
    public function findRecipients(int $announcementId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM '.self::RECIPIENT_TABLE.' WHERE pid = ? ORDER BY id', [$announcementId]);

        return array_map($this->hydrateRecipient(...), $rows);
    }

    public function setRecipientMailStatus(int $id, MailStatus $status, int $timestamp): void
    {
        $this->connection->update(self::RECIPIENT_TABLE, ['mail_status' => $status->value, 'tstamp' => $timestamp], ['id' => $id]);
    }

    /**
     * Records the objection only if none has been recorded yet and the objection
     * period is still open. Returns false otherwise (e.g. double submit).
     *
     * @param list<string> $subprocessors
     */
    public function recordObjection(int $recipientId, string $name, array $subprocessors, string $reason, int $now): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE '.self::RECIPIENT_TABLE.' r INNER JOIN '.self::TABLE.' a ON a.id = r.pid'
            .' SET r.objected_at = ?, r.objection_name = ?, r.objection_subprocessors = ?, r.objection_reason = ?, r.tstamp = ?'
            .' WHERE r.id = ? AND r.objected_at = 0 AND a.effective_at >= ?',
            [$now, $name, json_encode(array_values($subprocessors), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $reason, $now, $recipientId, $now],
        );

        if (1 !== (int) $affected) {
            return false;
        }

        $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET objection_count = objection_count + 1 WHERE id = (SELECT pid FROM '.self::RECIPIENT_TABLE.' WHERE id = ?)',
            [$recipientId],
        );

        return true;
    }

    /**
     * Only for the retention clean-up: removes the recipient rows (personal data) of
     * a deleted agreement.
     */
    public function deleteRecipientsForAgreement(int $agreementId): void
    {
        $this->connection->delete(self::RECIPIENT_TABLE, ['agreement' => $agreementId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Announcement
    {
        $changes = json_decode((string) ($row['changes'] ?? ''), true);

        return new Announcement(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['template'],
            (int) $row['version'],
            (string) $row['language'],
            SubprocessorChanges::fromArray(\is_array($changes) ? $changes : []),
            (int) $row['notice_days'],
            (int) $row['sent_at'],
            (int) $row['effective_at'],
            (int) $row['created_by'],
            (int) $row['recipient_count'],
            (int) $row['objection_count'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRecipient(array $row): AnnouncementRecipient
    {
        $subprocessors = json_decode((string) ($row['objection_subprocessors'] ?? ''), true);

        return new AnnouncementRecipient(
            (int) $row['id'],
            (int) $row['pid'],
            (int) $row['agreement'],
            (string) $row['company'],
            (string) $row['signatory_name'],
            (string) $row['email'],
            (string) $row['language'],
            MailStatus::tryFrom((string) $row['mail_status']) ?? MailStatus::Pending,
            (int) $row['objected_at'],
            (string) $row['objection_name'],
            \is_array($subprocessors) ? array_values(array_map('strval', $subprocessors)) : [],
            (string) ($row['objection_reason'] ?? ''),
        );
    }
}
