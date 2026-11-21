<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Intl\Locales;
use Contao\DataContainer;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Repository\VersionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class VersionListener
{
    public function __construct(
        private readonly VersionRepository $versions,
        private readonly Locales $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Assigns the next version number to a new draft.
     *
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_pactum_version', target: 'config.oncreate')]
    public function onCreate(string $table, int $insertId, array $fields, DataContainer $dc): void
    {
        $templateId = (int) ($fields['pid'] ?? 0);

        if ($templateId > 0) {
            $this->versions->setVersionNumber($insertId, $this->versions->nextVersionNumber($templateId));
        }
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_pactum_version', target: 'fields.language.options')]
    #[AsCallback(table: 'tl_pactum_invitation', target: 'fields.language.options')]
    public function getLanguageOptions(): array
    {
        return $this->locales->getLanguages();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_pactum_version', target: 'list.label.label')]
    public function getLabel(array $row, string $label): string
    {
        $status = VersionStatus::tryFrom((string) ($row['status'] ?? '')) ?? VersionStatus::Draft;
        $statusLabel = $this->translator->trans('version_status.'.$status->value, [], 'pactum');
        $note = htmlspecialchars((string) ($row['change_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE);
        $hash = (string) ($row['content_hash'] ?? '');

        return \sprintf(
            '<strong>v%d</strong> · %s · <span class="pactum-status pactum-status--%s">%s</span>%s%s',
            (int) ($row['version_number'] ?? 0),
            htmlspecialchars((string) ($row['language'] ?? ''), ENT_QUOTES),
            $status->value,
            htmlspecialchars($statusLabel, ENT_QUOTES),
            '' !== $note ? ' <span class="label-info">'.$note.'</span>' : '',
            '' !== $hash ? ' <code class="label-info" title="SHA-256">'.substr($hash, 0, 12).'…</code>' : '',
        );
    }

    #[AsCallback(table: 'tl_pactum_version', target: 'list.operations.edit.button')]
    #[AsCallback(table: 'tl_pactum_version', target: 'list.operations.delete.button')]
    #[AsCallback(table: 'tl_pactum_version', target: 'list.operations.publish.button')]
    #[AsCallback(table: 'tl_pactum_version', target: 'list.operations.announce.button')]
    public function onlyForDrafts(DataContainerOperation $operation): void
    {
        if (VersionStatus::Draft->value !== ($operation->getRecord()['status'] ?? null)) {
            $operation->disable();
        }
    }
}
