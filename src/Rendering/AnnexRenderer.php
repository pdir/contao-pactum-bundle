<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering;

use Pdir\PactumBundle\Domain\Annex;
use Twig\Environment;

/**
 * Renders an annex with a bundle template (overridable in the templates folder).
 * Back end content is inserted as sanitized markup, never as Twig.
 */
class AnnexRenderer
{
    public const TEMPLATE = '@Contao/pactum/annex.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly ContractHtmlSanitizer $sanitizer,
    ) {
    }

    public function render(Annex $annex, int $number, string $locale): string
    {
        $sanitized = new Annex($annex->id, $annex->type, $annex->title, $this->sanitizer->sanitize($annex->content), $annex->subprocessors);

        $html = $this->twig->render(self::TEMPLATE, [
            'annex' => $sanitized,
            'number' => $number,
            'locale' => $locale,
        ]);

        return $this->sanitizer->sanitize($html);
    }
}
