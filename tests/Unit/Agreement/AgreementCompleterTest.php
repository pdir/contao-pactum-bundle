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

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Agreement\AgreementCompleter;
use Pdir\PactumBundle\Agreement\AgreementMailer;
use Pdir\PactumBundle\Agreement\DocumentHasher;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Event\AgreementCompletedEvent;
use Pdir\PactumBundle\Exception\AlreadyCompletedException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\VersionChangedException;
use Pdir\PactumBundle\Invitation\InvitationStateMachine;
use Pdir\PactumBundle\Pdf\PdfGenerator;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Privacy\ClientDataPolicy;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
class AgreementCompleterTest extends TestCase
{
    private const RENDERED = '<p>{{customer.company}} / {{signatory.name}} / {{agreement.date}}</p>';

    private string $storageDir;

    private InvitationRepository&MockObject $invitations;

    private AgreementRepository&MockObject $agreements;

    private AgreementMailer&MockObject $mailer;

    private EventDispatcherInterface&MockObject $dispatcher;

    private Version $version;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/pactum-test-'.bin2hex(random_bytes(4));
        $this->invitations = $this->createMock(InvitationRepository::class);
        $this->agreements = $this->createMock(AgreementRepository::class);
        $this->mailer = $this->createMock(AgreementMailer::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->version = Factory::version(VersionStatus::Published, rendered: self::RENDERED, hash: hash('sha256', self::RENDERED));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storageDir);
    }

    public function testCompletesAgreementWithHashesPdfAndAnonymizedIp(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation())
        ;
        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->with(7, InvitationStatus::OptedIn, InvitationStatus::Completed, 1_800_000_000, ['token_hash' => null])
            ->willReturn(true)
        ;

        $expectedDocument = '<p>Kunde AG / Kim Kunde / '.date('d.m.Y', 1_800_000_000).'</p>';

        $this->agreements
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                function (string $uuid, int $invitationId, int $templateId, int $versionId, string $language, string $versionHash, string $documentHash, string $documentHtml, string $pdfPath, string $pdfHash, $customer, int $optInAt, int $consentedAt, array $consentTexts, string $ip, string $userAgent, int $ipDeleteAfter) use ($expectedDocument): int {
                    $this->assertSame(7, $invitationId);
                    $this->assertSame(100, $versionId);
                    $this->assertSame(hash('sha256', self::RENDERED), $versionHash);
                    $this->assertSame($expectedDocument, $documentHtml);
                    $this->assertSame(hash('sha256', $expectedDocument), $documentHash);
                    $this->assertSame(hash('sha256', '%PDF-test'), $pdfHash);
                    $this->assertFileExists($this->storageDir.'/'.$pdfPath);
                    $this->assertSame('192.0.2.0', $ip);
                    $this->assertSame('', $userAgent);
                    $this->assertSame(1_800_000_000 + 90 * 86400, $ipDeleteAfter);
                    $this->assertSame(['read' => 'Gelesen', 'authorized' => 'Berechtigt'], $consentTexts);
                    $this->assertSame(1_700_000_100, $optInAt);

                    return 55;
                },
            )
        ;
        $this->agreements
            ->method('find')
            ->willReturn($this->agreement())
        ;

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(AgreementCompletedEvent::class))
        ;

        $this->mailer
            ->expects($this->once())
            ->method('send')
        ;

        $agreement = $this->createCompleter()->complete(7, 100, ['read' => 'Gelesen', 'authorized' => 'Berechtigt'], '192.0.2.77', 'Mozilla/5.0');

        $this->assertSame(55, $agreement->id);
    }

    public function testSkippingTheOptInIsImpossible(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;

        $this->agreements
            ->expects($this->never())
            ->method('insert')
        ;

        $this->expectException(InvitationNotUsableException::class);

        $this->createCompleter()->complete(7, 100, [], null, null);
    }

    public function testExpiredInvitationCannotBeCompleted(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::OptedIn, 1_000))
        ;

        $this->agreements
            ->expects($this->never())
            ->method('insert')
        ;

        $this->expectException(InvitationNotUsableException::class);

        $this->createCompleter()->complete(7, 100, [], null, null);
    }

    public function testAlreadyCompletedInvitationIsRejected(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Completed))
        ;

        $this->expectException(AlreadyCompletedException::class);

        $this->createCompleter()->complete(7, 100, [], null, null);
    }

    public function testConcurrentCompletionIsRejectedAndThePdfRemoved(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation())
        ;
        $this->invitations
            ->method('transition')
            ->willReturn(false)
        ;

        $this->agreements
            ->expects($this->never())
            ->method('insert')
        ;

        $this->mailer
            ->expects($this->never())
            ->method('send')
        ;

        try {
            $this->createCompleter()->complete(7, 100, [], null, null);
            $this->fail('Exception expected');
        } catch (AlreadyCompletedException) {
        }

        $this->assertSame([], glob($this->storageDir.'/agreements/*/*/*.pdf') ?: []);
    }

    public function testAgreementRequiresTheDisplayedVersion(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation())
        ;

        $this->expectException(VersionChangedException::class);

        $this->createCompleter()->complete(7, 99, [], null, null);
    }

    public function testTamperedVersionTextIsDetected(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation())
        ;

        $this->version = Factory::version(VersionStatus::Published, rendered: self::RENDERED.'<p>manipulated</p>', hash: hash('sha256', self::RENDERED));

        $this->expectException(InvalidVersionException::class);

        $this->createCompleter()->complete(7, 100, [], null, null);
    }

    private function agreement(): Agreement
    {
        return new Agreement(55, 'uuid', 7, 10, 100, 'de', 'h', 'd', '<p></p>', 'agreements/2027/01/x.pdf', 'p', Factory::customer(), 1, 2, [], '', '', MailStatus::Pending);
    }

    private function createCompleter(): AgreementCompleter
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection))
        ;

        $templates = $this->createStub(TemplateRepository::class);
        $templates
            ->method('find')
            ->willReturn(Factory::template())
        ;

        $contractors = $this->createStub(ContractorRepository::class);
        $contractors
            ->method('find')
            ->willReturn(Factory::contractor())
        ;

        $versions = $this->createStub(VersionRepository::class);
        $versions
            ->method('findActive')
            ->willReturn($this->version)
        ;

        $pdf = $this->createStub(PdfGenerator::class);
        $pdf
            ->method('generate')
            ->willReturn('%PDF-test')
        ;

        return new AgreementCompleter(
            $connection,
            $this->invitations,
            $templates,
            $contractors,
            $versions,
            $this->agreements,
            new InvitationStateMachine(),
            RenderingFactory::renderer(),
            new DocumentHasher(),
            $pdf,
            new PdfStorage($this->storageDir),
            new ClientDataPolicy('anonymized', false, 90),
            $this->mailer,
            $this->createStub(AuditLogger::class),
            $this->dispatcher,
            new MockClock('@1800000000'),
        );
    }
}
