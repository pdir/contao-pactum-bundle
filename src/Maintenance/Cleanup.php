<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Maintenance;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Invitation\InvitationService;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Psr\Clock\ClockInterface;

/**
 * Data minimization (F9):
 *
 * 1. mark overdue invitations as expired
 * 2. anonymize expired/revoked invitations after "cleanup_after_days"
 * 3. remove IP address/user agent of agreements after "ip_retention_days"
 * 4. delete concluded agreements only if "agreement_retention_days" is set
 *    (default: never)
 */
class Cleanup
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvitationService $invitationService,
        private readonly InvitationRepository $invitations,
        private readonly AgreementRepository $agreements,
        private readonly AuditLogRepository $auditLog,
        private readonly AuditLogger $auditLogger,
        private readonly PdfStorage $pdfStorage,
        private readonly ClockInterface $clock,
        private readonly int $cleanupAfterDays,
        private readonly int|null $agreementRetentionDays,
    ) {
    }

    public function run(): CleanupReport
    {
        $now = $this->clock->now()->getTimestamp();
        $expired = $this->invitationService->expireOverdue();

        $anonymized = 0;

        foreach ($this->invitations->findAnonymizableIds($now - $this->cleanupAfterDays * 86400) as $id) {
            $this->invitations->anonymize($id, $now);
            $this->auditLogger->log(AuditEvent::InvitationAnonymized, $id);
            ++$anonymized;
        }

        $purged = $this->agreements->purgeClientData($now);

        foreach ($purged as $agreementId) {
            $this->auditLogger->log(AuditEvent::AgreementPersonalDataPurged, agreementId: $agreementId, context: ['fields' => 'ip,user_agent']);
        }

        $deleted = 0;

        if (null !== $this->agreementRetentionDays) {
            foreach ($this->agreements->findConcludedBefore($now - $this->agreementRetentionDays * 86400) as $agreement) {
                $this->connection->transactional(
                    function () use ($agreement): void {
                        $this->auditLog->deleteForAgreement($agreement->id, $agreement->invitationId);
                        $this->agreements->delete($agreement->id);
                        $this->invitations->delete($agreement->invitationId);
                    },
                );

                $this->pdfStorage->delete($agreement->pdfPath);
                ++$deleted;
            }
        }

        return new CleanupReport($expired, $anonymized, \count($purged), $deleted);
    }
}
