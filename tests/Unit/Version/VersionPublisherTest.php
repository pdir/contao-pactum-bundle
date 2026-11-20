<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Version;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Agreement\DocumentHasher;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Event\VersionPublishedEvent;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\UnknownPlaceholderException;
use Pdir\PactumBundle\Exception\VersionLockedException;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use Pdir\PactumBundle\Version\VersionPublisher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class VersionPublisherTest extends TestCase
{
    private MockObject&VersionRepository $versions;

    private EventDispatcherInterface&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->versions = $this->createMock(VersionRepository::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    public function testPublishFreezesTextHashesItAndArchivesThePredecessor(): void
    {
        $draft = Factory::version(content: '<p>{{contractor.name}} / {{customer.company}}</p>');
        $published = Factory::version(VersionStatus::Published, rendered: 'x', hash: 'y');

        $this->versions
            ->method('find')
            ->willReturnOnConsecutiveCalls($draft, $published)
        ;
        $this->versions
            ->method('findPublishedIdsExcept')
            ->with(10, 'de', 100)
            ->willReturn([42])
        ;

        $expectedText = '<p>Muster Agentur GmbH / {{customer.company}}</p>';

        $this->versions
            ->expects($this->once())
            ->method('markPublished')
            ->with(100, $expectedText, '[]', hash('sha256', $expectedText), 1_800_000_000, 1_800_000_000, 5)
        ;
        $this->versions
            ->expects($this->once())
            ->method('archive')
            ->with(42, 1_800_000_000)
        ;

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(VersionPublishedEvent::class))
        ;

        $this->createPublisher()->publish(100, 5);
    }

    public function testPublishedVersionsAreLocked(): void
    {
        $this->versions
            ->method('find')
            ->willReturn(Factory::version(VersionStatus::Published, rendered: 'x', hash: 'y'))
        ;
        $this->versions
            ->expects($this->never())
            ->method('markPublished')
        ;

        $this->expectException(VersionLockedException::class);

        $this->createPublisher()->publish(100, 5);
    }

    public function testValidFromMustNotBeInTheFuture(): void
    {
        $v = Factory::version();

        $this->versions
            ->method('find')
            ->willReturn(new Version($v->id, $v->templateId, 1, 'de', 'x', 1_900_000_000, VersionStatus::Draft, '', [], null, null, null, null))
        ;

        $this->expectException(InvalidVersionException::class);

        $this->createPublisher()->publish(100, 5);
    }

    public function testIncompleteContractorPreventsPublishing(): void
    {
        $this->versions
            ->method('find')
            ->willReturn(Factory::version())
        ;

        $this->expectException(IncompleteContractorException::class);

        $this->createPublisher(new Contractor(1, '', '', '', '', '', '', '', '', '', '', '', '', null))->publish(100, 5);
    }

    public function testUnknownPlaceholdersPreventPublishing(): void
    {
        $this->versions
            ->method('find')
            ->willReturn(Factory::version(content: '{{contractor.nmae}}'))
        ;
        $this->versions
            ->expects($this->never())
            ->method('markPublished')
        ;

        $this->expectException(UnknownPlaceholderException::class);

        $this->createPublisher()->publish(100, 5);
    }

    private function createPublisher(Contractor|null $contractor = null): VersionPublisher
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
            ->willReturn($contractor ?? Factory::contractor())
        ;

        $annexes = $this->createStub(AnnexRepository::class);
        $annexes
            ->method('findByIds')
            ->willReturn([])
        ;

        return new VersionPublisher(
            $connection,
            $this->versions,
            $templates,
            $contractors,
            $annexes,
            RenderingFactory::renderer(),
            new DocumentHasher(),
            $this->createStub(AuditLogger::class),
            $this->dispatcher,
            new MockClock('@1800000000'),
        );
    }
}
