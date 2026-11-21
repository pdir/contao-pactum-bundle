<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Version;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Agreement\DocumentHasher;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Event\VersionPublishedEvent;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\UnknownPlaceholderException;
use Pdir\PactumBundle\Exception\VersionLockedException;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes a draft: renders and freezes the text including annexes, stores the
 * SHA-256 hash and archives the previously published version of the same template
 * and language (exactly one active version per template/language).
 */
class VersionPublisher
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VersionRepository $versions,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly AnnexRepository $annexes,
        private readonly ContractRenderer $renderer,
        private readonly DocumentHasher $hasher,
        private readonly AuditLogger $auditLogger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Renders the draft exactly as it would be published, without saving.
     *
     * @throws InvalidVersionException
     * @throws IncompleteContractorException
     * @throws UnknownPlaceholderException
     */
    public function preview(int $versionId): string
    {
        $version = $this->versions->find($versionId) ?? throw new InvalidVersionException(\sprintf('Version %d does not exist.', $versionId), translationKey: 'error.version_not_found', translationParameters: ['%id%' => $versionId]);

        if (VersionStatus::Draft !== $version->status) {
            return (string) $version->renderedContent;
        }

        [$template, $contractor] = $this->loadTemplateAndContractor($version);

        return $this->renderer->renderForPublish(
            $this->withValidFrom($version, $version->validFrom ?? $this->clock->now()->getTimestamp()),
            $template,
            $contractor,
            $this->annexes->findByIds($version->annexIds),
        );
    }

    /**
     * @throws VersionLockedException
     * @throws InvalidVersionException
     * @throws IncompleteContractorException
     * @throws UnknownPlaceholderException
     */
    public function publish(int $versionId, int $userId): Version
    {
        $now = $this->clock->now()->getTimestamp();

        $archived = $this->connection->transactional(
            function () use ($versionId, $userId, $now): array {
                $version = $this->versions->find($versionId) ?? throw new InvalidVersionException(\sprintf('Version %d does not exist.', $versionId), translationKey: 'error.version_not_found', translationParameters: ['%id%' => $versionId]);

                if (VersionStatus::Draft !== $version->status) {
                    throw new VersionLockedException(\sprintf('Version %d is not a draft.', $versionId), translationKey: 'error.version_not_draft', translationParameters: ['%id%' => $versionId]);
                }

                if ('' === $version->language) {
                    throw new InvalidVersionException(\sprintf('Version %d has no language.', $versionId), translationKey: 'error.version_no_language');
                }

                if ($version->validFrom && $version->validFrom > $now) {
                    throw new InvalidVersionException(\sprintf('Version %d: the "valid from" date must not be in the future.', $versionId), translationKey: 'error.version_valid_from_future');
                }

                [$template, $contractor] = $this->loadTemplateAndContractor($version);

                // Serialize concurrent publish requests of the same template
                $this->templates->lockForUpdate($template->id);

                $validFrom = $version->validFrom ?? $now;
                $version = $this->withValidFrom($version, $validFrom);
                $annexes = $this->annexes->findByIds($version->annexIds);

                $rendered = $this->renderer->renderForPublish($version, $template, $contractor, $annexes);
                $snapshot = json_encode(array_map(static fn (Annex $a): array => $a->toArray(), $annexes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $hash = $this->hasher->hash($rendered);

                $this->versions->markPublished($versionId, $rendered, $snapshot, $hash, $validFrom, $now, $userId);
                $this->auditLogger->log(AuditEvent::VersionPublished, context: ['version' => $versionId, 'template' => $template->id, 'hash' => $hash]);

                $archived = $this->versions->findPublishedIdsExcept($template->id, $version->language, $versionId);

                foreach ($archived as $id) {
                    $this->versions->archive($id, $now);
                    $this->auditLogger->log(AuditEvent::VersionArchived, context: ['version' => $id, 'replaced_by' => $versionId]);
                }

                return $archived;
            },
        );

        $published = $this->versions->find($versionId) ?? throw new InvalidVersionException(\sprintf('Version %d does not exist.', $versionId), translationKey: 'error.version_not_found', translationParameters: ['%id%' => $versionId]);

        $this->eventDispatcher->dispatch(new VersionPublishedEvent($published, $archived));

        return $published;
    }

    /**
     * @return array{0: ContractTemplate, 1: Contractor}
     */
    private function loadTemplateAndContractor(Version $version): array
    {
        $template = $this->templates->find($version->templateId) ?? throw new InvalidVersionException(\sprintf('Template %d does not exist.', $version->templateId), translationKey: 'error.template_not_found', translationParameters: ['%id%' => $version->templateId]);
        $contractor = $this->contractors->find($template->contractorId);

        if (null === $contractor || !$contractor->isComplete()) {
            throw new IncompleteContractorException(\sprintf('The contractor profile of template %d is missing or incomplete.', $template->id), translationKey: 'be.contractor.incomplete');
        }

        return [$template, $contractor];
    }

    private function withValidFrom(Version $v, int $validFrom): Version
    {
        return new Version($v->id, $v->templateId, $v->versionNumber, $v->language, $v->content, $validFrom, $v->status, $v->changeNote, $v->annexIds, $v->renderedContent, $v->annexSnapshot, $v->contentHash, $v->publishedAt);
    }
}
