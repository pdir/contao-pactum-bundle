<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders the agreement PDF (cover page, contract incl. annexes, conclusion
 * protocol) from the overridable template
 * "@Contao/pactum/pdf/agreement.html.twig". Remote resources and PHP are disabled
 * in dompdf.
 */
class PdfGenerator
{
    public const TEMPLATE = '@Contao/pactum/pdf/agreement.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly LogoResolver $logoResolver,
    ) {
    }

    public function generate(PdfDocumentData $data): string
    {
        $html = $this->twig->render(self::TEMPLATE, [
            'data' => $data,
            'contractor' => $data->contractor,
            'customer' => $data->customer,
            'version' => $data->version,
            'locale' => $data->version->language,
            'logo' => $this->logoResolver->getDataUri($data->contractor),
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->addInfo('Title', $data->templateTitle);
        $dompdf->addInfo('Creator', 'Pactum – AVV/DPA for Contao');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
