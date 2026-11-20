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

use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Subprocessor\SubprocessorListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubprocessorListParserTest extends TestCase
{
    public function testParsesGermanKeys(): void
    {
        $yaml = <<<'YAML'
            stand: "November 2026"
            subunternehmer:
              - name: "Hetzner Online GmbH"
                anschrift:
                  - "Industriestr. 25"
                  - "91710 Gunzenhausen"
                leistung: "Webhosting, Mailhosting"
                land: "Deutschland"
            YAML;

        $result = (new SubprocessorListParser())->parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('Hetzner Online GmbH', $result[0]->name);
        $this->assertSame("Industriestr. 25\n91710 Gunzenhausen\nDeutschland", $result[0]->address);
        $this->assertSame('Webhosting, Mailhosting', $result[0]->service);
        $this->assertSame(Subprocessor::LOCATION_EU, $result[0]->location);
        $this->assertSame('', $result[0]->transferBasis);
    }

    public function testParsesEnglishKeysAndPlainLists(): void
    {
        $yaml = <<<'YAML'
            - name: "Mail Inc."
              address: "1 Main Street\nSan Francisco"
              service: "E-Mail"
              country: "USA"
              transfer_basis: "EU-US Data Privacy Framework"
            YAML;

        $result = (new SubprocessorListParser())->parse($yaml);

        $this->assertSame("1 Main Street\nSan Francisco\nUSA", $result[0]->address);
        $this->assertSame(Subprocessor::LOCATION_THIRD_COUNTRY, $result[0]->location);
        $this->assertSame('EU-US Data Privacy Framework', $result[0]->transferBasis);
    }

    public function testDoesNotDuplicateTheCountryLine(): void
    {
        $yaml = <<<'YAML'
            - name: "ClickMeeting Sp. z o.o."
              anschrift: ["al. Grunwaldzka 413", "80-309 Gdansk", "Polen"]
              leistung: "Online-Meetings"
              land: "Polen"
            YAML;

        $result = (new SubprocessorListParser())->parse($yaml);

        $this->assertSame("al. Grunwaldzka 413\n80-309 Gdansk\nPolen", $result[0]->address);
        $this->assertSame(Subprocessor::LOCATION_EU, $result[0]->location);
    }

    public function testExplicitLocationWins(): void
    {
        $yaml = <<<'YAML'
            - name: "Hosting AG"
              address: "Bahnhofstrasse 1, Zürich"
              service: "Hosting"
              location: "third_country"
              transfer_basis: "Angemessenheitsbeschluss Schweiz"
            YAML;

        $result = (new SubprocessorListParser())->parse($yaml);

        $this->assertSame(Subprocessor::LOCATION_THIRD_COUNTRY, $result[0]->location);
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidLists(string $yaml, string $message): void
    {
        $this->expectException(PactumException::class);
        $this->expectExceptionMessage($message);

        (new SubprocessorListParser())->parse($yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'invalid yaml' => ['- name: [unclosed', 'Invalid YAML'];
        yield 'no list' => ['stand: "2026"', 'does not contain a list'];
        yield 'missing fields' => ["- name: \"A GmbH\"\n  land: \"Deutschland\"", 'missing required fields: address, service'];
        yield 'no country' => ["- name: \"A GmbH\"\n  anschrift: \"Weg 1\"\n  leistung: \"X\"", 'either "country" or "location"'];
        yield 'third country without basis' => ["- name: \"A Inc.\"\n  anschrift: \"Way 1\"\n  leistung: \"X\"\n  land: \"USA\"", 'requires a "transfer_basis"'];
        yield 'bad location' => ["- name: \"A GmbH\"\n  anschrift: \"Weg 1\"\n  leistung: \"X\"\n  location: \"moon\"", 'must be "eu" or "third_country"'];
        yield 'duplicate' => ["- {name: \"A GmbH\", anschrift: \"Weg 1\", leistung: \"X\", land: \"DE\"}\n- {name: \"a gmbh\", anschrift: \"Weg 2\", leistung: \"Y\", land: \"DE\"}", 'listed more than once'];
    }

    public function testParsesTheFileFromDisk(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pactum');
        file_put_contents($file, "- {name: \"A GmbH\", anschrift: \"Weg 1\", leistung: \"X\", land: \"Deutschland\"}\n");

        try {
            $this->assertCount(1, (new SubprocessorListParser())->parseFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testRejectsMissingFiles(): void
    {
        $this->expectException(PactumException::class);

        (new SubprocessorListParser())->parseFile('/does/not/exist.yaml');
    }
}
