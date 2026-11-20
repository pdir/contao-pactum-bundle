<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\EventListener;

use Pdir\PactumBundle\EventListener\ProcessResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ProcessResponseListenerTest extends TestCase
{
    public function testProcessPagesAreNeitherIndexedNorCached(): void
    {
        $request = new Request();
        $request->attributes->set(ProcessResponseListener::REQUEST_ATTRIBUTE, true);

        $response = $this->dispatch($request);

        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testOtherPagesAreNotTouched(): void
    {
        $this->assertFalse($this->dispatch(new Request())->headers->has('X-Robots-Tag'));
    }

    private function dispatch(Request $request): Response
    {
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response());
        (new ProcessResponseListener())($event);

        return $event->getResponse();
    }
}
