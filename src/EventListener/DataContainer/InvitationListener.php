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

use Contao\BackendUser;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Invitation\InvitationService;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class InvitationListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvitationService $invitationService,
        private readonly AuditLogger $auditLogger,
        private readonly Security $security,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_pactum_invitation', target: 'config.oncreate')]
    public function onCreate(string $table, int $insertId, array $fields, DataContainer $dc): void
    {
        $user = $this->security->getUser();
        $now = $this->clock->now()->getTimestamp();

        $this->connection->update(
            InvitationRepository::TABLE,
            [
                'created_at' => $now,
                'created_by' => $user instanceof BackendUser ? (int) $user->id : 0,
                'status' => InvitationStatus::Open->value,
                'expires_at' => $this->invitationService->getDefaultExpiry((int) ($fields['template'] ?? 0)),
            ],
            ['id' => $insertId],
        );

        $this->auditLogger->log(AuditEvent::InvitationCreated, $insertId);
    }

    /**
     * Fills an empty expiry date with the template default on save.
     */
    #[AsCallback(table: 'tl_pactum_invitation', target: 'fields.expires_at.save')]
    public function onSaveExpiry(mixed $value, DataContainer $dc): int
    {
        $timestamp = (int) $value;

        if ($timestamp > 0) {
            // Valid until the end of the selected day
            return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($this->clock->now()->getTimezone())->setTime(23, 59, 59)->getTimestamp();
        }

        $templateId = (int) ($dc->getCurrentRecord()['template'] ?? 0);

        return $this->invitationService->getDefaultExpiry($templateId);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $args
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.label.label')]
    public function getLabel(array $row, string $label, DataContainer $dc, array $args): array
    {
        $status = InvitationStatus::tryFrom((string) ($row['status'] ?? '')) ?? InvitationStatus::Open;

        if ($status->isActive() && (int) ($row['expires_at'] ?? 0) <= $this->clock->now()->getTimestamp()) {
            $status = InvitationStatus::Expired;
        }

        $company = (string) ($row['company'] ?? '') ?: (string) ($row['prefill_company'] ?? '');

        $args[0] = '' !== $company ? htmlspecialchars($company, ENT_QUOTES) : '–';
        $args[2] = htmlspecialchars($this->translator->trans('invitation_status.'.$status->value, [], 'pactum'), ENT_QUOTES);

        if (null === ($row['token_hash'] ?? null) && InvitationStatus::Open === $status) {
            $args[2] .= ' <span class="label-info">('.htmlspecialchars($this->translator->trans('be.invitation.no_link', [], 'pactum'), ENT_QUOTES).')</span>';
        }

        return array_values($args);
    }

    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.operations.edit.button')]
    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.operations.delete.button')]
    public function onlyWithoutLink(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord() ?? [];

        if (InvitationStatus::Open->value !== ($record['status'] ?? null) || null !== ($record['token_hash'] ?? null) || (int) ($record['optin_requested_at'] ?? 0) > 0) {
            $operation->disable();
        }
    }

    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.operations.link.button')]
    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.operations.revoke.button')]
    #[AsCallback(table: 'tl_pactum_invitation', target: 'list.operations.extend.button')]
    public function onlyWhenNotFinal(DataContainerOperation $operation): void
    {
        $status = InvitationStatus::tryFrom((string) ($operation->getRecord()['status'] ?? ''));

        if (null === $status || $status->isFinal()) {
            $operation->disable();
        }
    }
}
