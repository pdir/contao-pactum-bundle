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
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\MailStatus;

class InvitationRepository
{
    public const TABLE = 'tl_pactum_invitation';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): Invitation|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findByTokenHash(string $hash): Invitation|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE token_hash = ?', [$hash]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function insert(int $templateId, string $language, int $expiresAt, int $createdBy, int $timestamp, string $prefillCompany = '', string $prefillEmail = ''): int
    {
        $this->connection->insert(self::TABLE, [
            'tstamp' => $timestamp,
            'created_at' => $timestamp,
            'created_by' => $createdBy,
            'template' => $templateId,
            'language' => $language,
            'expires_at' => $expiresAt,
            'status' => InvitationStatus::Open->value,
            'prefill_company' => $prefillCompany,
            'prefill_email' => $prefillEmail,
            ...CustomerData::emptyRow(),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Stores a new token hash; the previous token becomes invalid.
     */
    public function replaceToken(int $id, string $tokenHash, int $timestamp): void
    {
        $this->connection->update(self::TABLE, ['token_hash' => $tokenHash, 'tstamp' => $timestamp], ['id' => $id]);
    }

    public function invalidateToken(int $id, int $timestamp): void
    {
        $this->connection->update(self::TABLE, ['token_hash' => null, 'tstamp' => $timestamp], ['id' => $id]);
    }

    /**
     * Changes the status only if the current status matches $from (optimistic
     * locking). Returns false if another request changed the status first.
     *
     * @param array<string, scalar|null> $fields
     */
    public function transition(int $id, InvitationStatus $from, InvitationStatus $to, int $timestamp, array $fields = []): bool
    {
        $affected = $this->connection->update(
            self::TABLE,
            [...$fields, 'status' => $to->value, 'tstamp' => $timestamp],
            ['id' => $id, 'status' => $from->value],
        );

        return 1 === $affected;
    }

    /**
     * Stores the customer data and the pending opt-in (only while open).
     */
    public function saveCustomerData(int $id, CustomerData $customer, string $optInIdentifier, int $timestamp): bool
    {
        $affected = $this->connection->update(
            self::TABLE,
            [
                ...$customer->toRow(),
                'optin_identifier' => $optInIdentifier,
                'optin_requested_at' => $timestamp,
                'optin_confirmed_at' => 0,
                'tstamp' => $timestamp,
            ],
            ['id' => $id, 'status' => InvitationStatus::Open->value],
        );

        return 1 === $affected;
    }

    public function updateOptInIdentifier(int $id, string $optInIdentifier, int $timestamp): void
    {
        $this->connection->update(
            self::TABLE,
            ['optin_identifier' => $optInIdentifier, 'optin_requested_at' => $timestamp, 'tstamp' => $timestamp],
            ['id' => $id, 'status' => InvitationStatus::Open->value],
        );
    }

    public function updateExpiry(int $id, int $expiresAt, int $timestamp): void
    {
        $this->connection->update(self::TABLE, ['expires_at' => $expiresAt, 'tstamp' => $timestamp], ['id' => $id]);
    }

    public function setMailStatus(int $id, MailStatus $status, int $timestamp): void
    {
        $this->connection->update(self::TABLE, ['mail_status' => $status->value, 'tstamp' => $timestamp], ['id' => $id]);
    }

    /**
     * Active invitations whose expiry date has passed.
     *
     * @return list<int>
     */
    public function findExpiredActiveIds(int $now): array
    {
        return array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id FROM '.self::TABLE.' WHERE status IN (?) AND expires_at <= ?',
                [[InvitationStatus::Open->value, InvitationStatus::OptedIn->value], $now],
                [ArrayParameterType::STRING],
            ),
        );
    }

    /**
     * Not completed invitations that were last changed before $before and still
     * contain personal data.
     *
     * @return list<int>
     */
    public function findAnonymizableIds(int $before): array
    {
        return array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id FROM '.self::TABLE.' WHERE status IN (?) AND tstamp < ? AND anonymized_at = 0',
                [[InvitationStatus::Expired->value, InvitationStatus::Revoked->value], $before],
                [ArrayParameterType::STRING],
            ),
        );
    }

    public function anonymize(int $id, int $timestamp): void
    {
        $this->connection->update(
            self::TABLE,
            [
                ...CustomerData::emptyRow(),
                'prefill_company' => '',
                'prefill_email' => '',
                'token_hash' => null,
                'optin_identifier' => null,
                'anonymized_at' => $timestamp,
            ],
            ['id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Invitation
    {
        $optIn = $row['optin_identifier'] ?? null;
        $hash = $row['token_hash'] ?? null;

        return new Invitation(
            (int) $row['id'],
            (int) $row['template'],
            (string) $row['language'],
            \is_string($hash) && '' !== $hash ? $hash : null,
            (int) $row['expires_at'],
            InvitationStatus::tryFrom((string) $row['status']) ?? InvitationStatus::Revoked,
            (string) $row['prefill_company'],
            (string) $row['prefill_email'],
            (int) $row['created_by'],
            CustomerData::fromRow($row),
            \is_string($optIn) && '' !== $optIn ? $optIn : null,
            (int) $row['optin_requested_at'] ?: null,
            (int) $row['optin_confirmed_at'] ?: null,
        );
    }
}
