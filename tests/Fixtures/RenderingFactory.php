<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Fixtures;

use Pdir\PactumBundle\Rendering\AnnexRenderer;
use Pdir\PactumBundle\Rendering\ContractHtmlSanitizer;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Rendering\PlaceholderProviderInterface;
use Pdir\PactumBundle\Rendering\PlaceholderResolver;
use Pdir\PactumBundle\Rendering\Provider\ContractorPlaceholderProvider;
use Pdir\PactumBundle\Rendering\Provider\CustomerPlaceholderProvider;
use Pdir\PactumBundle\Rendering\Provider\VersionPlaceholderProvider;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the real rendering services with the bundle translations and templates,
 * without a Symfony kernel.
 */
final class RenderingFactory
{
    public static function translator(): Translator
    {
        $translator = new Translator('de');
        $translator->addLoader('xlf', new XliffFileLoader());

        foreach (['de', 'en'] as $locale) {
            $translator->addResource('xlf', __DIR__.'/../../translations/pactum.'.$locale.'.xlf', $locale, 'pactum');
        }

        return $translator;
    }

    public static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(self::translator()));

        return $twig;
    }

    /**
     * @param list<PlaceholderProviderInterface> $additionalProviders
     */
    public static function resolver(array $additionalProviders = []): PlaceholderResolver
    {
        $translator = self::translator();

        return new PlaceholderResolver(
            [new ContractorPlaceholderProvider(), new VersionPlaceholderProvider($translator), new CustomerPlaceholderProvider($translator), ...$additionalProviders],
            new ContractHtmlSanitizer(),
        );
    }

    public static function renderer(): ContractRenderer
    {
        $sanitizer = new ContractHtmlSanitizer();

        return new ContractRenderer(self::resolver(), $sanitizer, new AnnexRenderer(self::twig(), $sanitizer));
    }
}
