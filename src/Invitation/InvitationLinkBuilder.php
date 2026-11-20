<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Invitation;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Pdir\PactumBundle\Exception\PactumException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InvitationLinkBuilder
{
    public const TOKEN_PARAMETER = 'pactum';

    public const OPTIN_PARAMETER = 'optin';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $urlGenerator,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function build(int $pageId, #[\SensitiveParameter] string $token, array $parameters = []): string
    {
        $this->framework->initialize();

        $page = $this->framework->getAdapter(PageModel::class)->findById($pageId);

        if (!$page instanceof PageModel) {
            throw new PactumException(\sprintf('Target page %d of the contract template does not exist.', $pageId));
        }

        $url = $this->urlGenerator->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);
        $query = http_build_query([self::TOKEN_PARAMETER => $token, ...$parameters], '', '&', PHP_QUERY_RFC3986);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }
}
