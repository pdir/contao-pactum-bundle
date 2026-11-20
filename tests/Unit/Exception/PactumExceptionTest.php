<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Exception;

use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\PactumException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

class PactumExceptionTest extends TestCase
{
    public function testTranslationKeyIsUsedForTheBackEnd(): void
    {
        $e = new InvalidVersionException('Version 5 does not exist.', translationKey: 'error.version_not_found', translationParameters: ['%id%' => 5]);

        $this->assertSame('Version 5 does not exist.', $e->getMessage());
        $this->assertSame('Die Version mit der ID 5 existiert nicht.', $e->trans($this->translator('de')));
        $this->assertSame('Version ID 5 does not exist.', $e->trans($this->translator('en')));
    }

    public function testMessageIsTheFallbackWithoutKey(): void
    {
        $this->assertSame('Something failed.', (new PactumException('Something failed.'))->trans($this->translator('de')));
    }

    private function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('xlf', new XliffFileLoader());

        foreach (['de', 'en'] as $language) {
            $translator->addResource('xlf', __DIR__.'/../../../translations/pactum.'.$language.'.xlf', $language, 'pactum');
        }

        return $translator;
    }
}
