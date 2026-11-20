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

final class CustomerPlaceholderProvider implements PlaceholderProviderInterface
{
    use FormatsAddressTrait;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getStage(): PlaceholderStage
    {
        return PlaceholderStage::Agreement;
    }

    public function getKeys(): array
    {
        return [
            'customer.company',
            'customer.street',
            'customer.postal',
            'customer.city',
            'customer.country',
            'customer.address',
            'signatory.name',
            'signatory.role',
            'signatory.email',
            'agreement.date',
        ];
    }

    public function provide(PlaceholderContext $context): array
    {
        $values = [];

        if ($c = $context->customer) {
            $values += [
                'customer.company' => $c->company,
                'customer.street' => $c->street,
                'customer.postal' => $c->postal,
                'customer.city' => $c->city,
                'customer.country' => $c->country,
                'customer.address' => $this->formatAddress($c->street, $c->postal, $c->city, $c->country),
                'signatory.name' => $c->signatoryName,
                'signatory.role' => $c->signatoryRole,
                'signatory.email' => $c->signatoryEmail,
            ];
        }

        if ($context->agreementDate) {
            $format = $this->translator->trans('date_format', [], 'pactum', $context->locale);
            $values['agreement.date'] = $context->agreementDate->format($format);
        }

        return $values;
    }
}
