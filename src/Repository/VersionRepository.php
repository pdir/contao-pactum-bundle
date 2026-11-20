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
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\VersionLockedException;

/**
 * Write access to versions only happens through methods guarded by "status =
 * draft", so a published version can never be modified here.
 */
class VersionRepository
{
    public const TABLE = 'tl_pactum_version';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $id): Version|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * The (single) published version of a template in a language.
     */
    public function findActive(int $templateId, string $language): Version|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM '.self::TABLE.' WHERE pid = ? AND language = ? AND status = ? ORDER BY published_at DESC, id DESC LIMIT 1',
            [$templateId, $language, VersionStatus::Published->value],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @return list<string>
     */
    public function findPublishedLanguages(int $templateId): array
    {
        return array_map(
            'strval',
            $this->connection->fetchFirstColumn(
                'SELECT DISTINCT language FROM '.self::TABLE.' WHERE pid = ? AND status = ? ORDER BY language',
                [$templateId, VersionStatus::Published->value],
            ),
        );
    }

    /**
     * @return list<int>
     */
    public function findPublishedIdsExcept(int $templateId, string $language, int $exceptId): array
    {
        return array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id FROM '.self::TABLE.' WHERE pid = ? AND language = ? AND status = ? AND id != ?',
                [$templateId, $language, VersionStatus::Published->value, $exceptId],
            ),
        );
    }

    public function countLocked(int $templateId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.self::TABLE.' WHERE pid = ? AND status != ?',
            [$templateId, VersionStatus::Draft->value],
        );
    }

    public function nextVersionNumber(int $templateId): int
    {
        return 1 + (int) $this->connection->fetchOne('SELECT MAX(version_number) FROM '.self::TABLE.' WHERE pid = ?', [$templateId]);
    }

    public function setVersionNumber(int $id, int $number): void
    {
        $this->connection->update(self::TABLE, ['version_number' => $number], ['id' => $id, 'status' => VersionStatus::Draft->value]);
    }

    /**
     * Creates a new draft with the content of an existing version.
     */
    public function insertDraftFrom(Version $source, int $versionNumber, int $timestamp): int
    {
        return $this->insertDraft($source->templateId, $versionNumber, $source->language, $source->content, $source->annexIds, $timestamp);
    }

    /**
     * @param list<int> $annexIds
     */
    public function insertDraft(int $templateId, int $versionNumber, string $language, string $content, array $annexIds, int $timestamp, string $changeNote = ''): int
    {
        $this->connection->insert(self::TABLE, [
            'pid' => $templateId,
            'tstamp' => $timestamp,
            'version_number' => $versionNumber,
            'language' => $language,
            'content' => $content,
            'valid_from' => 0,
            'change_note' => $changeNote,
            'annexes' => serialize(array_map('strval', $annexIds)),
            'status' => VersionStatus::Draft->value,
            'rendered_content' => null,
            'annex_snapshot' => null,
            'content_hash' => '',
            'published_at' => 0,
            'published_by' => 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @throws VersionLockedException if the version is not a draft (anymore)
     */
    public function markPublished(int $id, string $renderedContent, string $annexSnapshot, string $hash, int $validFrom, int $publishedAt, int $publishedBy): void
    {
        $affected = $this->connection->update(
            self::TABLE,
            [
                'tstamp' => $publishedAt,
                'status' => VersionStatus::Published->value,
                'rendered_content' => $renderedContent,
                'annex_snapshot' => $annexSnapshot,
                'content_hash' => $hash,
                'valid_from' => $validFrom,
                'published_at' => $publishedAt,
                'published_by' => $publishedBy,
            ],
            ['id' => $id, 'status' => VersionStatus::Draft->value],
        );

        if (1 !== $affected) {
            throw new VersionLockedException(\sprintf('Version %d is not a draft and cannot be published.', $id));
        }
    }

    public function archive(int $id, int $timestamp): void
    {
        $this->connection->update(
            self::TABLE,
            ['status' => VersionStatus::Archived->value, 'tstamp' => $timestamp],
            ['id' => $id, 'status' => VersionStatus::Published->value],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Version
    {
        $annexIds = $this->deserializeIds($row['annexes'] ?? null);

        return new Version(
            (int) $row['id'],
            (int) $row['pid'],
            (int) $row['version_number'],
            (string) $row['language'],
            (string) ($row['content'] ?? ''),
            (int) $row['valid_from'] ?: null,
            VersionStatus::tryFrom((string) $row['status']) ?? VersionStatus::Draft,
            (string) ($row['change_note'] ?? ''),
            $annexIds,
            isset($row['rendered_content']) ? (string) $row['rendered_content'] : null,
            isset($row['annex_snapshot']) ? (string) $row['annex_snapshot'] : null,
            '' !== (string) $row['content_hash'] ? (string) $row['content_hash'] : null,
            (int) $row['published_at'] ?: null,
        );
    }

    /**
     * @return list<int>
     */
    private function deserializeIds(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $data = unserialize($value, ['allowed_classes' => false]);

        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $data)));
    }
}
