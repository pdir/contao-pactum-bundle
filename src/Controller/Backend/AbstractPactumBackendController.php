<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Pdir\PactumBundle\Exception\PactumException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared helpers for the Pactum back end pages. Access is bound to the regular
 * Contao module permissions (user/group settings), so templates, invitations and
 * agreements can be granted separately.
 *
 * Template variables must not collide with the ones used by "be_main" (e.g.
 * "version" or "main"), hence the "pactum_" prefix where needed. "headline" and
 * "title" are passed on purpose.
 */
abstract class AbstractPactumBackendController extends AbstractBackendController
{
    public const MODULE_TEMPLATES = 'pactum_templates';

    public const MODULE_INVITATIONS = 'pactum_invitations';

    public const MODULE_AGREEMENTS = 'pactum_agreements';

    public const MODULE_ANNOUNCEMENTS = 'pactum_announcements';

    public static function getSubscribedServices(): array
    {
        $services = parent::getSubscribedServices();
        $services['contao.csrf.token_manager'] = ContaoCsrfTokenManager::class;
        $services['translator'] = TranslatorInterface::class;

        return $services;
    }

    protected function denyUnlessModule(string $module): void
    {
        if (!$this->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)) {
            throw new AccessDeniedException(\sprintf('Not allowed to access the back end module "%s".', $module));
        }
    }

    /**
     * POST requests in the back end are also checked by Contao's request token
     * listener; this explicit check keeps the controllers safe on their own.
     */
    protected function denyUnlessValidRequestToken(Request $request): void
    {
        /** @var ContaoCsrfTokenManager $manager */
        $manager = $this->container->get('contao.csrf.token_manager');
        $tokenName = $this->getParameter('contao.csrf_token_name');

        if (!\is_string($tokenName) || !$manager->isTokenValid(new CsrfToken($tokenName, (string) $request->request->get('REQUEST_TOKEN')))) {
            throw new AccessDeniedException('Invalid request token.');
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    protected function trans(string $id, array $parameters = []): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->container->get('translator');

        return $translator->trans($id, $parameters, 'pactum');
    }

    /**
     * Back end text of an exception: translated if it carries a translation key,
     * otherwise its (English) message.
     */
    protected function transException(PactumException $exception): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->container->get('translator');

        return $exception->trans($translator);
    }

    protected function addFlash(string $type, mixed $message): void
    {
        $session = $this->container->get('request_stack')->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            // Contao renders "contao.BE.*" flash messages in the back end
            $session->getFlashBag()->add('contao.BE.'.$type, $message);
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    protected function redirectToModule(string $module, array $parameters = []): RedirectResponse
    {
        return $this->redirectToRoute('contao_backend', ['do' => $module, ...$parameters]);
    }
}
