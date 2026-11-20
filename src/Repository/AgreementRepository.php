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
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\MailStatus;

/**
 * Agreements are evidence. Apart from the insert, only the mail status and the
 * removal of IP/user agent (retention) are ever updated.
 */
class AgreementRepository
{
    public const TABLE = 'tl_pactum_agreement';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): Agreement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findByUuid(string $uuid): Agreement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE uuid = ?', [$uuid]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findByInvitation(int $invitationId): Agreement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE invitation = ?', [$invitationId]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, string> $consentTexts
     */
    public function insert(string $uuid, int $invitationId, int $templateId, int $versionId, string $language, string $versionHash, string $documentHash, string $documentHtml, string $pdfPath, string $pdfHash, CustomerData $customer, int $optInAt, int $consentedAt, array $consentTexts, string $ip, string $userAgent, int $ipDeleteAfter): int
    {
        $this->connection->insert(self::TABLE, [
            'tstamp' => $consentedAt,
            'uuid' => $uuid,
            'invitation' => $invitationId,
            'template' => $templateId,
            'version' => $versionId,
            'language' => $language,
            'version_hash' => $versionHash,
            'document_hash' => $documentHash,
            'document_html' => $documentHtml,
            'pdf_path' => $pdfPath,
            'pdf_hash' => $pdfHash,
            ...$customer->toRow(),
            'optin_at' => $optInAt,
            'consented_at' => $consentedAt,
            'consent_texts' => json_encode($consentTexts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'ip_delete_after' => $ipDeleteAfter,
            'mail_status' => MailStatus::Pending->value,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function setMailStatus(int $id, MailStatus $status, int $timestamp): void
    {
        $this->connection->update(self::TABLE, ['mail_status' => $status->value, 'tstamp' => $timestamp], ['id' => $id]);
    }

    /**
     * Removes IP address and user agent after the retention period.
     *
     * @return list<int> affected agreement IDs
     */
    public function purgeClientData(int $now): array
    {
        $ids = array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id FROM '.self::TABLE." WHERE ip_delete_after > 0 AND ip_delete_after <= ? AND (ip != '' OR user_agent != '')",
                [$now],
            ),
        );

        foreach ($ids as $id) {
            $this->connection->update(self::TABLE, ['ip' => '', 'user_agent' => '', 'ip_delete_after' => 0], ['id' => $id]);
        }

        return $ids;
    }

    /**
     * @return list<Agreement>
     */
    public function findConcludedBefore(int $timestamp): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM '.self::TABLE.' WHERE consented_at < ?', [$timestamp]);

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Only for the retention clean-up (F9).
     */
    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Agreement
    {
        $consentTexts = json_decode((string) ($row['consent_texts'] ?? ''), true);

        return new Agreement(
            (int) $row['id'],
            (string) $row['uuid'],
            (int) $row['invitation'],
            (int) $row['template'],
            (int) $row['version'],
            (string) $row['language'],
            (string) $row['version_hash'],
            (string) $row['document_hash'],
            (string) ($row['document_html'] ?? ''),
            (string) $row['pdf_path'],
            (string) $row['pdf_hash'],
            CustomerData::fromRow($row),
            (int) $row['optin_at'],
            (int) $row['consented_at'],
            \is_array($consentTexts) ? array_map('strval', $consentTexts) : [],
            (string) $row['ip'],
            (string) $row['user_agent'],
            MailStatus::tryFrom((string) $row['mail_status']) ?? MailStatus::Pending,
        );
    }
}
