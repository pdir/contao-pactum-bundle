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

    public const OBJECTION_PARAMETER = 'pactum_objection';

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
        return $this->buildUrl($pageId, [self::TOKEN_PARAMETER => $token, ...$parameters]);
    }

    /**
     * Personal link to object to an announced sub-processor change.
     */
    public function buildObjection(int $pageId, #[\SensitiveParameter] string $token): string
    {
        return $this->buildUrl($pageId, [self::OBJECTION_PARAMETER => $token]);
    }

    /**
     * @param array<string, string> $query
     */
    private function buildUrl(int $pageId, #[\SensitiveParameter] array $query): string
    {
        $this->framework->initialize();

        $page = $this->framework->getAdapter(PageModel::class)->findById($pageId);

        if (!$page instanceof PageModel) {
            throw new PactumException(\sprintf('Target page %d of the contract template does not exist.', $pageId), translationKey: 'error.target_page_not_found', translationParameters: ['%id%' => $pageId]);
        }

        $url = $this->urlGenerator->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
