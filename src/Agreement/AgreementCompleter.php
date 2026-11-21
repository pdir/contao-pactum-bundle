<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Agreement;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Event\AgreementCompletedEvent;
use Pdir\PactumBundle\Exception\AlreadyCompletedException;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\NoActiveVersionException;
use Pdir\PactumBundle\Exception\VersionChangedException;
use Pdir\PactumBundle\Invitation\InvitationStateMachine;
use Pdir\PactumBundle\Pdf\PdfDocumentData;
use Pdir\PactumBundle\Pdf\PdfGenerator;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Privacy\ClientDataPolicy;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Concludes an agreement. Guarantees:
 *
 * - only invitations in status "opted_in" can be completed
 * - the customer agrees to exactly the version that was displayed
 * - the version text is unchanged since publishing (hash check)
 * - a conditional status update plus a unique index on the invitation make
 *   double conclusions impossible (reload, back button, parallel requests)
 * - the PDF is generated before the transaction; mail errors do not block
 */
class AgreementCompleter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvitationRepository $invitations,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly VersionRepository $versions,
        private readonly AgreementRepository $agreements,
        private readonly InvitationStateMachine $stateMachine,
        private readonly ContractRenderer $renderer,
        private readonly DocumentHasher $hasher,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfStorage $pdfStorage,
        private readonly ClientDataPolicy $clientDataPolicy,
        private readonly AgreementMailer $mailer,
        private readonly AuditLogger $auditLogger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $consentTexts the checkbox labels as displayed
     *
     * @throws AlreadyCompletedException
     * @throws InvitationNotUsableException
     * @throws VersionChangedException
     * @throws NoActiveVersionException
     * @throws InvalidVersionException
     * @throws IncompleteContractorException
     */
    public function complete(int $invitationId, int $displayedVersionId, array $consentTexts, string|null $ip, string|null $userAgent): Agreement
    {
        $nowDate = $this->clock->now();
        $now = $nowDate->getTimestamp();

        $invitation = $this->invitations->find($invitationId) ?? throw new InvitationNotUsableException(\sprintf('Invitation %d does not exist.', $invitationId));
        $status = $invitation->getEffectiveStatus($now);

        if (InvitationStatus::Completed === $status) {
            throw new AlreadyCompletedException(\sprintf('Invitation %d has already been completed.', $invitationId));
        }

        if (!$this->stateMachine->can($status, InvitationStatus::Completed)) {
            throw new InvitationNotUsableException(\sprintf('Invitation %d cannot be completed in status "%s".', $invitationId, $status->value));
        }

        $template = $this->templates->find($invitation->templateId) ?? throw new InvitationNotUsableException(\sprintf('Template of invitation %d does not exist.', $invitationId));
        $contractor = $this->contractors->find($template->contractorId);

        if (null === $contractor || !$contractor->isComplete()) {
            throw new IncompleteContractorException(\sprintf('The contractor profile of template %d is missing or incomplete.', $template->id), translationKey: 'be.contractor.incomplete');
        }

        $version = $this->versions->findActive($template->id, $invitation->language) ?? throw new NoActiveVersionException(\sprintf('Template %d has no published version.', $template->id));

        if ($version->id !== $displayedVersionId) {
            throw new VersionChangedException(\sprintf('Invitation %d: displayed version %d is no longer active.', $invitationId, $displayedVersionId));
        }

        if (null === $version->contentHash || null === $version->renderedContent || !$this->hasher->matches($version->contentHash, $version->renderedContent)) {
            throw new InvalidVersionException(\sprintf('Integrity check of version %d failed.', $version->id));
        }

        $document = $this->renderer->renderForAgreement($version, $invitation->customer, $nowDate);
        $documentHash = $this->hasher->hash($document);
        $uuid = Uuid::v4()->toRfc4122();

        $storedIp = $this->clientDataPolicy->ip($ip);
        $storedUserAgent = $this->clientDataPolicy->userAgent($userAgent);

        $pdf = $this->pdfGenerator->generate(new PdfDocumentData(
            contractor: $contractor,
            templateTitle: $template->title,
            version: $version,
            customer: $invitation->customer,
            documentHtml: $document,
            preview: false,
            reference: $uuid,
            optInAt: (int) $invitation->optInConfirmedAt,
            consentedAt: $now,
            documentHash: $documentHash,
            consentTexts: $consentTexts,
        ));

        $pdfPath = $this->pdfStorage->store($uuid, $pdf, $now);
        $pdfHash = $this->hasher->hash($pdf);

        try {
            $agreementId = $this->connection->transactional(
                function () use ($invitation, $status, $template, $version, $uuid, $documentHash, $document, $pdfPath, $pdfHash, $now, $consentTexts, $storedIp, $storedUserAgent): int {
                    if (!$this->invitations->transition($invitation->id, $status, InvitationStatus::Completed, $now, ['token_hash' => null])) {
                        throw new AlreadyCompletedException(\sprintf('Invitation %d was completed concurrently.', $invitation->id));
                    }

                    $agreementId = $this->agreements->insert(
                        uuid: $uuid,
                        invitationId: $invitation->id,
                        templateId: $template->id,
                        versionId: $version->id,
                        language: $version->language,
                        versionHash: (string) $version->contentHash,
                        documentHash: $documentHash,
                        documentHtml: $document,
                        pdfPath: $pdfPath,
                        pdfHash: $pdfHash,
                        customer: $invitation->customer,
                        optInAt: (int) $invitation->optInConfirmedAt,
                        consentedAt: $now,
                        consentTexts: $consentTexts,
                        ip: $storedIp,
                        userAgent: $storedUserAgent,
                        ipDeleteAfter: $this->clientDataPolicy->deleteAfter($storedIp, $storedUserAgent, $now),
                    );

                    $this->auditLogger->log(
                        AuditEvent::AgreementCompleted,
                        $invitation->id,
                        $agreementId,
                        [
                            'version' => $version->id,
                            'version_hash' => $version->contentHash,
                            'document_hash' => $documentHash,
                            'pdf_hash' => $pdfHash,
                        ],
                    );

                    return $agreementId;
                },
            );
        } catch (\Throwable $e) {
            $this->pdfStorage->delete($pdfPath);

            throw $e;
        }

        $agreement = $this->agreements->find($agreementId) ?? throw new \LogicException(\sprintf('Agreement %d could not be loaded.', $agreementId));

        $this->eventDispatcher->dispatch(new AgreementCompletedEvent($agreement));
        $this->mailer->send($agreement);

        return $agreement;
    }
}
