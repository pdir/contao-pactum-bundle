<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering\Provider;

use Pdir\PactumBundle\Rendering\PlaceholderContext;
use Pdir\PactumBundle\Rendering\PlaceholderProviderInterface;
use Pdir\PactumBundle\Rendering\PlaceholderStage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class VersionPlaceholderProvider implements PlaceholderProviderInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getStage(): PlaceholderStage
    {
        return PlaceholderStage::Publish;
    }

    public function getKeys(): array
    {
        return ['version.number', 'version.valid_from', 'template.title'];
    }

    public function provide(PlaceholderContext $context): array
    {
        $values = [];

        if ($context->version) {
            $values['version.number'] = (string) $context->version->versionNumber;

            if ($context->version->validFrom) {
                $format = $this->translator->trans('date_format', [], 'pactum', $context->locale);
                $values['version.valid_from'] = date($format, $context->version->validFrom);
            }
        }

        if ($context->template) {
            $values['template.title'] = $context->template->title;
        }

        return $values;
    }
}
