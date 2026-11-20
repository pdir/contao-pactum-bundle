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

final class ContractorPlaceholderProvider implements PlaceholderProviderInterface
{
    use FormatsAddressTrait;

    public function getStage(): PlaceholderStage
    {
        return PlaceholderStage::Publish;
    }

    public function getKeys(): array
    {
        return [
            'contractor.name',
            'contractor.street',
            'contractor.postal',
            'contractor.city',
            'contractor.country',
            'contractor.address',
            'contractor.representative',
            'contractor.dpo_name',
            'contractor.dpo_contact',
            'contractor.email',
        ];
    }

    public function provide(PlaceholderContext $context): array
    {
        $c = $context->contractor;

        if (null === $c) {
            return [];
        }

        return [
            'contractor.name' => $c->name,
            'contractor.street' => $c->street,
            'contractor.postal' => $c->postal,
            'contractor.city' => $c->city,
            'contractor.country' => $c->country,
            'contractor.address' => $this->formatAddress($c->street, $c->postal, $c->city, $c->country),
            'contractor.representative' => $c->representative,
            'contractor.dpo_name' => $c->dpoName,
            'contractor.dpo_contact' => $c->dpoContact,
            'contractor.email' => $c->contactEmail,
        ];
    }
}
