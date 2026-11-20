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

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Pdir\PactumBundle\Domain\MailStatus;
use Symfony\Contracts\Translation\TranslatorInterface;

class AnnouncementListener
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Recipient row: company, e-mail, mail status and – if any – the objection.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_pactum_announcement_recipient', target: 'list.sorting.child_record')]
    public function getRecipientLabel(array $row): string
    {
        $status = MailStatus::tryFrom((string) ($row['mail_status'] ?? '')) ?? MailStatus::Pending;
        $objectedAt = (int) ($row['objected_at'] ?? 0);

        $objection = 0 === $objectedAt
            ? '<span class="pactum-status pactum-status--none">'.$this->escape($this->translator->trans('be.announcement.no_objection', [], 'pactum')).'</span>'
            : \sprintf(
                '<span class="pactum-status pactum-status--objected"><strong>%s</strong></span> · %s',
                $this->escape($this->translator->trans('be.announcement.objected', ['%date%' => date('d.m.Y H:i', $objectedAt)], 'pactum')),
                $this->escape(implode(', ', $this->decodeList((string) ($row['objection_subprocessors'] ?? '')))),
            );

        return \sprintf(
            '<div class="tl_content_left"><strong>%s</strong> · %s · <span class="pactum-status pactum-status--%s">%s</span><br>%s</div>',
            $this->escape((string) ($row['company'] ?? '')),
            $this->escape((string) ($row['email'] ?? '')),
            $status->value,
            $this->escape($this->translator->trans('mail_status.'.$status->value, [], 'pactum')),
            $objection,
        );
    }

    /**
     * @return list<string>
     */
    private function decodeList(string $json): array
    {
        if ('' === $json) {
            return [];
        }

        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? array_values(array_map('strval', array_filter($data, 'is_scalar'))) : [];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
