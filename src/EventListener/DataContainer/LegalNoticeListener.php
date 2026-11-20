<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shows the legal notice ("no legal advice") in the Pactum back end modules.
 * Contao\Message is the core API for back end info messages.
 */
class LegalNoticeListener
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsCallback(table: 'tl_pactum_template', target: 'config.onload')]
    #[AsCallback(table: 'tl_pactum_version', target: 'config.onload')]
    #[AsCallback(table: 'tl_pactum_annex', target: 'config.onload')]
    #[AsCallback(table: 'tl_pactum_invitation', target: 'config.onload')]
    #[AsCallback(table: 'tl_pactum_agreement', target: 'config.onload')]
    #[AsCallback(table: 'tl_pactum_contractor', target: 'config.onload')]
    public function addLegalNotice(): void
    {
        $request = $this->requestStack->getCurrentRequest();

        // Only on list views, not on every edit/save request
        if (null === $request || $request->query->has('act') || $request->isMethod('POST')) {
            return;
        }

        $this->framework->getAdapter(Message::class)->addInfo($this->translator->trans('be.legal_notice', [], 'pactum'));
    }
}
