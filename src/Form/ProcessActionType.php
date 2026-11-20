<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Form;

use Contao\CoreBundle\Form\Type\AbstractContaoType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Small CSRF protected form for secondary actions (re-send opt-in mail, change data).
 *
 * @extends AbstractContaoType<mixed>
 */
class ProcessActionType extends AbstractContaoType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $actions */
        $actions = $options['actions'];

        foreach ($actions as $name => $label) {
            $builder->add($name, SubmitType::class, ['label' => $label]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setRequired('actions');
        $resolver->setAllowedTypes('actions', 'array');
        $resolver->setDefault('translation_domain', false);
    }

    public function getBlockPrefix(): string
    {
        return 'pactum_action';
    }
}
