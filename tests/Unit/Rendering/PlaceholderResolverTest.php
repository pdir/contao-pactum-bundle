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

use Pdir\PactumBundle\Rendering\HtmlValue;
use Pdir\PactumBundle\Rendering\PlaceholderContext;
use Pdir\PactumBundle\Rendering\PlaceholderProviderInterface;
use Pdir\PactumBundle\Rendering\PlaceholderStage;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Pdir\PactumBundle\Tests\Fixtures\RenderingFactory;
use PHPUnit\Framework\TestCase;

class PlaceholderResolverTest extends TestCase
{
    public function testResolvesOnlyPlaceholdersOfTheGivenStage(): void
    {
        $resolver = RenderingFactory::resolver();
        $html = '<p>{{ contractor.name }} / {{customer.company}}</p>';

        $result = $resolver->resolve($html, PlaceholderStage::Publish, new PlaceholderContext('de', contractor: Factory::contractor()));

        $this->assertSame('<p>Muster Agentur GmbH / {{customer.company}}</p>', $result);
    }

    public function testEscapesValuesIncludingCurlyBraces(): void
    {
        $resolver = RenderingFactory::resolver();
        $customer = Factory::customer('<script>alert(1)</script> {{env::host}} & Co');

        $result = $resolver->resolve('<p>{{customer.company}}</p>', PlaceholderStage::Agreement, new PlaceholderContext('de', customer: $customer));

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('{{', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&#123;&#123;env::host&#125;&#125;', $result);
        $this->assertStringContainsString('&amp; Co', $result);
    }

    public function testMultiLineValuesBecomeLineBreaks(): void
    {
        $result = RenderingFactory::resolver()->resolve('{{contractor.address}}', PlaceholderStage::Publish, new PlaceholderContext('de', contractor: Factory::contractor()));

        $this->assertSame("Musterstraße 1<br>\n12345 Musterstadt<br>\nDeutschland", $result);
    }

    public function testFindsUnknownPlaceholdersInsertTagsAndBrokenMarkup(): void
    {
        $resolver = RenderingFactory::resolver();
        $html = '<p>{{contractor.name}} {{contractor.nmae}} {{date}} {{env::host}} {{<strong>customer.company</strong>}} {{ annex.tom }} {{ unbalanced</p>';

        $unknown = $resolver->findUnknown($html, ['annex.tom']);

        $this->assertContains('contractor.nmae', $unknown);
        $this->assertContains('date', $unknown);
        $this->assertContains('env::host', $unknown);
        $this->assertContains('<strong>customer.company</strong>', $unknown);
        $this->assertContains('{{…}}', $unknown);
        $this->assertNotContains('contractor.name', $unknown);
        $this->assertNotContains('annex.tom', $unknown);
    }

    public function testCustomProviderHtmlValuesAreSanitized(): void
    {
        $provider = new class implements PlaceholderProviderInterface {
            public function getStage(): PlaceholderStage
            {
                return PlaceholderStage::Agreement;
            }

            public function getKeys(): array
            {
                return ['custom.html', 'custom.text'];
            }

            public function provide(PlaceholderContext $context): array
            {
                return [
                    'custom.html' => new HtmlValue('<b>fett</b><script>evil()</script><img src="https://tracker.example">'),
                    'custom.text' => 'plain',
                    'custom.ignored' => 'not declared',
                ];
            }
        };

        $resolver = RenderingFactory::resolver([$provider]);
        $result = $resolver->resolve('{{custom.html}}|{{custom.text}}|{{custom.ignored}}', PlaceholderStage::Agreement, new PlaceholderContext('de'));

        $this->assertSame('<b>fett</b>|plain|{{custom.ignored}}', $result);
        $this->assertContains('custom.html', $resolver->getKnownKeys(PlaceholderStage::Agreement));
        $this->assertNotContains('custom.html', $resolver->getKnownKeys(PlaceholderStage::Publish));
    }

    public function testAgreementDateUsesLocaleFormat(): void
    {
        $resolver = RenderingFactory::resolver();
        $date = new \DateTimeImmutable('2026-11-16 12:00:00');

        $this->assertSame('16.11.2026', $resolver->resolve('{{agreement.date}}', PlaceholderStage::Agreement, new PlaceholderContext('de', agreementDate: $date)));
        $this->assertSame('16 November 2026', $resolver->resolve('{{agreement.date}}', PlaceholderStage::Agreement, new PlaceholderContext('en', agreementDate: $date)));
    }
}
