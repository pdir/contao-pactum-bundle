<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Functional;

use Pdir\PactumBundle\Command\SubprocessorImportCommand;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorImporter;
use Pdir\PactumBundle\Subprocessor\SubprocessorListParser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the sub-processor import against the real schema: replacement of the rows,
 * sorting, scope (other annexes stay untouched), transaction rollback and the
 * console command end to end.
 */
class SubprocessorImportTest extends DatabaseTestCase
{
    private const NOW = 1_800_000_000;

    private const YAML = <<<'YAML'
        stand: "November 2026"
        subunternehmer:
          - name: "Hetzner Online GmbH"
            anschrift:
              - "Industriestr. 25"
              - "91710 Gunzenhausen"
            leistung: "Webhosting, Mailhosting"
            land: "Deutschland"
          - name: "Mail Inc."
            anschrift: "1 Main Street"
            leistung: "E-Mail-Versand"
            land: "USA"
            hinweis: "EU-US Data Privacy Framework"
        YAML;

    public function testReplacesTheSubprocessorsOfTheAnnex(): void
    {
        $repository = new AnnexRepository($this->getConnection());
        $annexId = $repository->insert(AnnexType::Subprocessor, 'Anlage 2', '', 1);
        $otherAnnexId = $repository->insert(AnnexType::Subprocessor, 'Andere Anlage', '', 1);

        $this->insertSubprocessor($annexId, 'Alt GmbH', 128);
        $this->insertSubprocessor($otherAnnexId, 'Fremd GmbH', 128);

        $result = $this->importer()->import($annexId, (new SubprocessorListParser())->parse(self::YAML));

        $this->assertSame(['Hetzner Online GmbH', 'Mail Inc.'], $result->added);
        $this->assertSame(['Alt GmbH'], $result->removed);

        $annex = $repository->findByIds([$annexId])[0];

        $this->assertSame(['Hetzner Online GmbH', 'Mail Inc.'], array_map(static fn (Subprocessor $s): string => $s->name, $annex->subprocessors));
        $this->assertSame("Industriestr. 25\n91710 Gunzenhausen\nDeutschland", $annex->subprocessors[0]->address);
        $this->assertSame(Subprocessor::LOCATION_EU, $annex->subprocessors[0]->location);
        $this->assertSame(Subprocessor::LOCATION_THIRD_COUNTRY, $annex->subprocessors[1]->location);
        $this->assertSame('EU-US Data Privacy Framework', $annex->subprocessors[1]->transferBasis);

        $rows = $this->getConnection()->fetchAllAssociative('SELECT sorting, tstamp FROM tl_pactum_subprocessor WHERE pid = ? ORDER BY sorting', [$annexId]);

        $this->assertSame([128, 256], array_map(static fn (array $r): int => (int) $r['sorting'], $rows));
        $this->assertSame([self::NOW, self::NOW], array_map(static fn (array $r): int => (int) $r['tstamp'], $rows));
        $this->assertSame(self::NOW, (int) $this->getConnection()->fetchOne('SELECT tstamp FROM tl_pactum_annex WHERE id = ?', [$annexId]));

        // Other annexes are not touched
        $this->assertSame(['Fremd GmbH'], array_map(static fn (Subprocessor $s): string => $s->name, $repository->findByIds([$otherAnnexId])[0]->subprocessors));
    }

    public function testASecondImportOfTheSameListChangesNothing(): void
    {
        $repository = new AnnexRepository($this->getConnection());
        $annexId = $repository->insert(AnnexType::Subprocessor, 'Anlage 2', '', 1);

        $list = (new SubprocessorListParser())->parse(self::YAML);

        $this->importer()->import($annexId, $list);

        $ids = $this->getConnection()->fetchFirstColumn('SELECT id FROM tl_pactum_subprocessor ORDER BY id');

        $result = $this->importer()->import($annexId, $list);

        $this->assertFalse($result->hasChanges());
        $this->assertSame(2, $result->unchanged);
        $this->assertSame($ids, $this->getConnection()->fetchFirstColumn('SELECT id FROM tl_pactum_subprocessor ORDER BY id'));
    }

    public function testDryRunDoesNotWrite(): void
    {
        $repository = new AnnexRepository($this->getConnection());
        $annexId = $repository->insert(AnnexType::Subprocessor, 'Anlage 2', '', 1);
        $this->insertSubprocessor($annexId, 'Alt GmbH', 128);

        $result = $this->importer()->import($annexId, (new SubprocessorListParser())->parse(self::YAML), true);

        $this->assertTrue($result->hasChanges());
        $this->assertSame(['Alt GmbH'], $this->getConnection()->fetchFirstColumn('SELECT name FROM tl_pactum_subprocessor'));
    }

    public function testFailedImportIsRolledBack(): void
    {
        $repository = new AnnexRepository($this->getConnection());
        $annexId = $repository->insert(AnnexType::Subprocessor, 'Anlage 2', '', 1);
        $this->insertSubprocessor($annexId, 'Alt GmbH', 128);

        // Exceeds varchar(255): the insert fails after the old rows were deleted
        $invalid = [
            new Subprocessor('Neu GmbH', 'Weg 1', 'Hosting', Subprocessor::LOCATION_EU, ''),
            new Subprocessor(str_repeat('x', 300), 'Weg 2', 'Hosting', Subprocessor::LOCATION_EU, ''),
        ];

        try {
            $this->importer()->import($annexId, $invalid);
            $this->fail('The import was expected to fail.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(['Alt GmbH'], $this->getConnection()->fetchFirstColumn('SELECT name FROM tl_pactum_subprocessor'));
    }

    public function testCommandImportsTheYamlFile(): void
    {
        $repository = new AnnexRepository($this->getConnection());
        $annexId = $repository->insert(AnnexType::Subprocessor, 'Anlage 2', '', 1);
        $tomId = $repository->insert(AnnexType::Tom, 'TOM', '<p>TOM</p>', 1);

        $file = tempnam(sys_get_temp_dir(), 'pactum');
        file_put_contents($file, self::YAML);

        try {
            $tester = new CommandTester(new SubprocessorImportCommand(new SubprocessorListParser(), $this->importer()));

            $this->assertSame(Command::SUCCESS, $tester->execute(['annex-id' => (string) $annexId, 'file' => $file, '--dry-run' => true]));
            $this->assertStringContainsString('Dry run', $tester->getDisplay());
            $this->assertSame(0, (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM tl_pactum_subprocessor'));

            $this->assertSame(Command::SUCCESS, $tester->execute(['annex-id' => (string) $annexId, 'file' => $file]));
            $this->assertStringContainsString('Mail Inc.', $tester->getDisplay());
            $this->assertSame(2, (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM tl_pactum_subprocessor WHERE pid = ?', [$annexId]));

            $this->assertSame(Command::FAILURE, $tester->execute(['annex-id' => (string) $tomId, 'file' => $file]));
            $this->assertStringContainsString('not a subprocessor annex', $tester->getDisplay());
        } finally {
            unlink($file);
        }
    }

    private function importer(): SubprocessorImporter
    {
        return new SubprocessorImporter($this->getConnection(), new AnnexRepository($this->getConnection()), new MockClock('@'.self::NOW));
    }

    private function insertSubprocessor(int $annexId, string $name, int $sorting): void
    {
        $this->getConnection()->insert('tl_pactum_subprocessor', [
            'pid' => $annexId,
            'sorting' => $sorting,
            'tstamp' => 1,
            'name' => $name,
            'address' => 'Weg 1',
            'service' => 'Hosting',
            'location' => Subprocessor::LOCATION_EU,
            'transfer_basis' => '',
        ]);
    }
}
