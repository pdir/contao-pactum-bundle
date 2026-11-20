<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hardens the page response that contains the agreement process: it shows
 * personal data and must therefore never be cached, indexed by the Contao search
 * (which honours "X-Robots-Tag: noindex") or leak its URL via referrer,
 * regardless of the page settings of the target page.
 */
#[AsEventListener(KernelEvents::RESPONSE)]
class ProcessResponseListener
{
    public const REQUEST_ATTRIBUTE = '_pactum_process';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE)) {
            return;
        }

        $response = $event->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
