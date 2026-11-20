<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Subprocessor;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorImporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class SubprocessorImporterTest extends TestCase
{
    public function testReplacesTheListAndReportsTheChanges(): void
    {
        $current = [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH'), $this->sub('Gamma GmbH')];
        $new = [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH', 'Mailhosting'), $this->sub('Delta GmbH')];

        $annexes = $this->annexes(new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', $current));
        $annexes
            ->expects($this->once())
            ->method('replaceSubprocessors')
            ->with(5, $new, 1_700_000_000)
            ->willReturn(3)
        ;

        $result = (new SubprocessorImporter($this->connection(), $annexes, new MockClock('@1700000000')))->import(5, $new);

        $this->assertSame(['Delta GmbH'], $result->added);
        $this->assertSame(['Gamma GmbH'], $result->removed);
        $this->assertSame(['Beta GmbH'], $result->changed);
        $this->assertSame(1, $result->unchanged);
        $this->assertFalse($result->dryRun);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $annexes = $this->annexes(new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', []));
        $annexes
            ->expects($this->never())
            ->method('replaceSubprocessors')
        ;

        $result = (new SubprocessorImporter($this->connection(), $annexes, new MockClock()))->import(5, [$this->sub('Alpha GmbH')], true);

        $this->assertSame(['Alpha GmbH'], $result->added);
        $this->assertTrue($result->dryRun);
    }

    public function testDoesNotWriteWithoutChanges(): void
    {
        $list = [$this->sub('Alpha GmbH')];

        $annexes = $this->annexes(new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', $list));
        $annexes
            ->expects($this->never())
            ->method('replaceSubprocessors')
        ;

        $result = (new SubprocessorImporter($this->connection(), $annexes, new MockClock()))->import(5, $list);

        $this->assertFalse($result->hasChanges());
        $this->assertSame(1, $result->unchanged);
    }

    public function testTreatsReorderingAsChange(): void
    {
        $annexes = $this->annexes(new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH')]));
        $annexes
            ->expects($this->once())
            ->method('replaceSubprocessors')
        ;

        $result = (new SubprocessorImporter($this->connection(), $annexes, new MockClock()))->import(5, [$this->sub('Beta GmbH'), $this->sub('Alpha GmbH')]);

        $this->assertTrue($result->hasChanges());
    }

    public function testRejectsOtherAnnexTypes(): void
    {
        $annexes = $this->annexes(new Annex(5, AnnexType::Tom, 'TOM', '<p>x</p>', []));

        $this->expectException(PactumException::class);
        $this->expectExceptionMessage('is not a subprocessor annex');

        (new SubprocessorImporter($this->connection(), $annexes, new MockClock()))->import(5, [$this->sub('Alpha GmbH')]);
    }

    public function testRejectsUnknownAnnexes(): void
    {
        $annexes = $this->annexes(null);

        $this->expectException(PactumException::class);
        $this->expectExceptionMessage('does not exist');

        (new SubprocessorImporter($this->connection(), $annexes, new MockClock()))->import(99, [$this->sub('Alpha GmbH')]);
    }

    public function testRejectsEmptyLists(): void
    {
        $this->expectException(PactumException::class);

        (new SubprocessorImporter($this->connection(), $this->annexes(null), new MockClock()))->import(5, []);
    }

    private function sub(string $name, string $service = 'Webhosting'): Subprocessor
    {
        return new Subprocessor($name, "Weg 1\n12345 Stadt\nDeutschland", $service, Subprocessor::LOCATION_EU, '');
    }

    private function connection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection))
        ;

        return $connection;
    }

    /**
     * @return AnnexRepository&MockObject
     */
    private function annexes(Annex|null $annex): AnnexRepository
    {
        $annexes = $this->createMock(AnnexRepository::class);
        $annexes
            ->method('findByIds')
            ->willReturn(null === $annex ? [] : [$annex])
        ;

        return $annexes;
    }
}
