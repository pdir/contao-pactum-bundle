<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Sample;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Sample\SampleImporter;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class SampleImporterTest extends TestCase
{
    public function testImportsOnlyADraft(): void
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

        $annexes = $this->createMock(AnnexRepository::class);
        $annexes
            ->expects($this->once())
            ->method('insert')
            ->with(AnnexType::Tom, $this->stringContains('MUSTER'))
            ->willReturn(3)
        ;

        $versions = $this->createMock(VersionRepository::class);
        $versions
            ->method('nextVersionNumber')
            ->willReturn(2)
        ;
        $versions
            ->expects($this->never())
            ->method('markPublished')
        ;
        $versions
            ->expects($this->once())
            ->method('insertDraft')
            ->with(10, 2, 'de', $this->stringContains('MUSTER – NICHT RECHTSGEPRÜFT'), [3])
            ->willReturn(42)
        ;

        $result = (new SampleImporter($connection, $templates, $versions, $annexes, new MockClock()))->import(10, 'de');

        $this->assertSame(42, $result->versionId);
        $this->assertSame(3, $result->annexId);
    }

    public function testRejectsUnknownLanguages(): void
    {
        $importer = new SampleImporter($this->createStub(Connection::class), $this->createStub(TemplateRepository::class), $this->createStub(VersionRepository::class), $this->createStub(AnnexRepository::class), new MockClock());

        $this->expectException(PactumException::class);

        $importer->import(10, 'fr');
    }

    #[DataProvider('languageProvider')]
    public function testSampleTextCanBePublished(string $language): void
    {
        $dir = \dirname(__DIR__, 3).'/samples';
        $content = (string) file_get_contents($dir.'/agreement.'.$language.'.html');
        $tom = (string) file_get_contents($dir.'/tom.'.$language.'.html');

        $version = Factory::version(VersionStatus::Draft, $content, annexIds: [3]);
        $annex = new Annex(3, AnnexType::Tom, 'TOM', $tom, []);

        $rendered = RenderingFactory::renderer()->renderForPublish($version, Factory::template(), Factory::contractor(), [$annex]);

        $this->assertStringContainsString('Muster Agentur GmbH', $rendered);
        $this->assertStringContainsString('{{customer.company}}', $rendered);
        $this->assertStringContainsString('[', $rendered);
        $this->assertStringNotContainsString('{{annex.', $rendered);
        $this->assertStringNotContainsString('<p><section>', $rendered);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function languageProvider(): iterable
    {
        yield ['de'];
        yield ['en'];
    }
}
