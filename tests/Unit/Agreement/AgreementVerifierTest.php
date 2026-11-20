<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Agreement;

use Pdir\PactumBundle\Agreement\AgreementVerifier;
use Pdir\PactumBundle\Agreement\DocumentHasher;
use Pdir\PactumBundle\Agreement\VerificationCheck;
use Pdir\PactumBundle\Agreement\VerificationResult;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class AgreementVerifierTest extends TestCase
{
    private const RENDERED = '<p>Vertrag für {{customer.company}}</p>';

    private string $storageDir;

    private PdfStorage $storage;

    private string $pdfPath;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/pactum-verify-'.bin2hex(random_bytes(4));

        $this->storage = new PdfStorage($this->storageDir);
        $this->pdfPath = $this->storage->store('0b5c1a2e-0000-4000-8000-000000000001', '%PDF-original', 1_800_000_000);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDir);
    }

    public function testIntactEvidenceIsValid(): void
    {
        $result = $this->verify($this->agreement());

        $this->assertTrue($result->isValid());
        $this->assertTrue($this->check($result, 'document_reproducible_from_version')->passed);
    }

    public function testManipulatedDocumentIsDetected(): void
    {
        $result = $this->verify($this->agreement(documentHtml: '<p>Vertrag für Andere GmbH</p>'));

        $this->assertFalse($result->isValid());
        $this->assertFalse($this->check($result, 'document_matches_document_hash')->passed);
    }

    public function testManipulatedPdfIsDetected(): void
    {
        file_put_contents($this->storage->getAbsolutePath($this->pdfPath), '%PDF-tampered');

        $result = $this->verify($this->agreement());

        $this->assertFalse($result->isValid());
        $this->assertFalse($this->check($result, 'pdf_matches_pdf_hash')->passed);
    }

    public function testMissingPdfIsDetected(): void
    {
        $this->storage->delete($this->pdfPath);

        $this->assertFalse($this->check($this->verify($this->agreement()), 'pdf_exists')->passed);
    }

    public function testManipulatedVersionTextIsDetected(): void
    {
        $version = Factory::version(VersionStatus::Published, rendered: self::RENDERED.'<p>neu</p>', hash: hash('sha256', self::RENDERED));

        $result = $this->verify($this->agreement(), $version);

        $this->assertFalse($result->isValid());
        $this->assertFalse($this->check($result, 'version_text_matches_version_hash')->passed);
    }

    public function testUnknownAgreement(): void
    {
        $result = $this->verify(null);

        $this->assertFalse($result->isValid());
    }

    private function agreement(string|null $documentHtml = null): Agreement
    {
        $original = '<p>Vertrag für Kunde AG</p>';

        return new Agreement(
            55,
            '0b5c1a2e-0000-4000-8000-000000000001',
            7,
            10,
            100,
            'de',
            hash('sha256', self::RENDERED),
            hash('sha256', $original),
            $documentHtml ?? $original,
            $this->pdfPath,
            hash('sha256', '%PDF-original'),
            Factory::customer(),
            1_799_999_000,
            1_800_000_000,
            [],
            '',
            '',
            MailStatus::Sent,
        );
    }

    private function verify(Agreement|null $agreement, Version|null $version = null): VerificationResult
    {
        $agreements = $this->createStub(AgreementRepository::class);
        $agreements
            ->method('find')
            ->willReturn($agreement)
        ;

        $versions = $this->createStub(VersionRepository::class);
        $versions
            ->method('find')
            ->willReturn($version ?? Factory::version(VersionStatus::Published, rendered: self::RENDERED, hash: hash('sha256', self::RENDERED)))
        ;

        return (new AgreementVerifier($agreements, $versions, new DocumentHasher(), $this->storage, RenderingFactory::renderer()))->verify(55);
    }

    private function check(VerificationResult $result, string $name): VerificationCheck
    {
        foreach ($result->checks as $check) {
            if ($name === $check->name) {
                return $check;
            }
        }

        $this->fail('Check '.$name.' not found');
    }
}
