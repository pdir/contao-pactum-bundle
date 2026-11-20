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

use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\VersionRepository;

/**
 * Verifies the evidence of an agreement: version text, final document and stored
 * PDF against the hashes recorded at conclusion time.
 */
class AgreementVerifier
{
    public function __construct(
        private readonly AgreementRepository $agreements,
        private readonly VersionRepository $versions,
        private readonly DocumentHasher $hasher,
        private readonly PdfStorage $pdfStorage,
        private readonly ContractRenderer $renderer,
    ) {
    }

    public function verify(int $agreementId): VerificationResult
    {
        $agreement = $this->agreements->find($agreementId);

        if (null === $agreement) {
            return new VerificationResult($agreementId, [new VerificationCheck('agreement_exists', false)]);
        }

        $checks = [new VerificationCheck('agreement_exists', true)];
        $version = $this->versions->find($agreement->versionId);

        if (null === $version || null === $version->renderedContent || null === $version->contentHash) {
            $checks[] = new VerificationCheck('version_exists', false, details: 'Version '.$agreement->versionId.' or its frozen text is missing.');
        } else {
            $checks[] = new VerificationCheck('version_exists', true);
            $checks[] = new VerificationCheck('version_text_matches_version_hash', $this->hasher->matches($version->contentHash, $version->renderedContent));
            $checks[] = new VerificationCheck('agreement_version_hash_matches', hash_equals($version->contentHash, $agreement->versionHash));

            // Informational: re-rendering depends on the current placeholder providers and
            // timezone; the stored document hash is authoritative.
            $date = (new \DateTimeImmutable('@'.$agreement->consentedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $rerendered = $this->renderer->renderForAgreement($version, $agreement->customer, $date);
            $checks[] = new VerificationCheck('document_reproducible_from_version', hash_equals($agreement->documentHtml, $rerendered), false);
        }

        $checks[] = new VerificationCheck('document_matches_document_hash', $this->hasher->matches($agreement->documentHash, $agreement->documentHtml));

        try {
            $pdfFile = $this->pdfStorage->getAbsolutePath($agreement->pdfPath);
            $pdfHash = $this->hasher->hashFile($pdfFile);
        } catch (\InvalidArgumentException) {
            $pdfHash = null;
        }

        if (null === $pdfHash) {
            $checks[] = new VerificationCheck('pdf_exists', false, details: 'The stored PDF file is missing or not readable.');
        } else {
            $checks[] = new VerificationCheck('pdf_exists', true);
            $checks[] = new VerificationCheck('pdf_matches_pdf_hash', '' !== $agreement->pdfHash && hash_equals($agreement->pdfHash, $pdfHash));
        }

        return new VerificationResult($agreementId, $checks);
    }
}
