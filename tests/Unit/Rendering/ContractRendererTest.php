<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Rendering;

use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\UnknownPlaceholderException;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use PHPUnit\Framework\TestCase;

class ContractRendererTest extends TestCase
{
    public function testPublishResolvesContractorAndKeepsCustomerPlaceholders(): void
    {
        $version = Factory::version(content: '<p>Zwischen {{customer.company}} und {{contractor.name}}, Version {{version.number}}</p><script>alert(1)</script>');

        $html = RenderingFactory::renderer()->renderForPublish($version, Factory::template(), Factory::contractor(), []);

        $this->assertSame('<p>Zwischen {{customer.company}} und Muster Agentur GmbH, Version 3</p>', $html);
    }

    public function testInlineAndAppendedAnnexes(): void
    {
        $tom = new Annex(1, AnnexType::Tom, 'TOM', '<p>Verschlüsselung bei {{contractor.name}}</p>');

        $sub = new Annex(
            2,
            AnnexType::Subprocessor,
            'Unterauftragsverarbeiter',
            '',
            [
                new Subprocessor('Hosting GmbH', 'Serverweg 1', 'Hosting', Subprocessor::LOCATION_EU, ''),
                new Subprocessor('Mail Inc.', 'Somewhere', 'E-Mail', Subprocessor::LOCATION_THIRD_COUNTRY, 'Standardvertragsklauseln'),
            ],
        );

        $version = Factory::version(content: '<p>Vertrag</p>{{annex.tom}}<p>Ende</p>');
        $html = RenderingFactory::renderer()->renderForPublish($version, Factory::template(), Factory::contractor(), [$tom, $sub]);

        // TOM inline (before "Ende"), subprocessors appended after it
        $this->assertMatchesRegularExpression('#<p>Vertrag</p>\s*<section>.*Anlage 1: TOM.*Verschlüsselung bei Muster Agentur GmbH.*</section>\s*<p>Ende</p>\s*<section>.*Anlage 2: Unterauftragsverarbeiter#s', $html);
        $this->assertStringContainsString('Hosting GmbH', $html);
        $this->assertStringContainsString('Standardvertragsklauseln', $html);
        $this->assertStringContainsString('Drittland', $html);
    }

    public function testAnnexPlaceholderInOwnParagraphIsUnwrapped(): void
    {
        $tom = new Annex(1, AnnexType::Tom, 'TOM', '<p>Maßnahmen</p>');
        $version = Factory::version(content: '<p>Vertrag</p><p> {{annex.tom}} </p><p>Ende</p>');

        $html = RenderingFactory::renderer()->renderForPublish($version, Factory::template(), Factory::contractor(), [$tom]);

        $this->assertStringNotContainsString('<p><section>', $html);
        $this->assertMatchesRegularExpression('#<p>Vertrag</p>\s*<section>.*</section>\s*<p>Ende</p>#s', $html);
    }

    public function testUnknownPlaceholdersPreventPublishing(): void
    {
        $version = Factory::version(content: '<p>{{contractor.nmae}} {{file::evil.php}}</p>');

        try {
            RenderingFactory::renderer()->renderForPublish($version, Factory::template(), Factory::contractor(), []);
            $this->fail('Exception expected');
        } catch (UnknownPlaceholderException $e) {
            $this->assertSame(['contractor.nmae', 'file::evil.php'], $e->placeholders);
        }
    }

    public function testAnnexesMustNotReferenceOtherAnnexes(): void
    {
        $this->expectException(UnknownPlaceholderException::class);

        $annex = new Annex(1, AnnexType::Custom, 'X', '<p>{{annex.tom}}</p>');
        RenderingFactory::renderer()->renderForPublish(Factory::version(), Factory::template(), Factory::contractor(), [$annex]);
    }

    public function testAgreementRenderingIsDeterministic(): void
    {
        $version = Factory::version(VersionStatus::Published, rendered: '<p>{{customer.company}}, {{signatory.name}} ({{signatory.role}}) am {{agreement.date}}</p>', hash: 'x');
        $date = new \DateTimeImmutable('2026-11-16 10:00:00');

        $renderer = RenderingFactory::renderer();
        $a = $renderer->renderForAgreement($version, Factory::customer(), $date);
        $b = $renderer->renderForAgreement($version, Factory::customer(), $date);

        $this->assertSame('<p>Kunde AG, Kim Kunde (Geschäftsführung) am 16.11.2026</p>', $a);
        $this->assertSame($a, $b);
    }

    public function testAgreementRenderingNeutralizesRemainingBraces(): void
    {
        $version = Factory::version(VersionStatus::Published, rendered: '<p>{{unknown.key}}</p>', hash: 'x');

        $html = RenderingFactory::renderer()->renderForAgreement($version, Factory::customer(), new \DateTimeImmutable());

        $this->assertStringNotContainsString('{{', $html);
    }

    public function testDraftsCannotBeUsedForAgreements(): void
    {
        $this->expectException(InvalidVersionException::class);

        RenderingFactory::renderer()->renderForAgreement(Factory::version(), Factory::customer(), new \DateTimeImmutable());
    }
}
