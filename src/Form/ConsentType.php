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
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Step 4: consent. The checkbox labels are passed in already translated, so
 * exactly the displayed texts can be stored as evidence.
 *
 * @extends AbstractContaoType<array<string, mixed>>
 */
class ConsentType extends AbstractContaoType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{read: string, authorized: string} $texts */
        $texts = $options['consent_texts'];

        $builder
            ->add('version_id', HiddenType::class)
            ->add(
                'read',
                CheckboxType::class,
                [
                    'label' => $texts['read'],
                    'required' => true,
                    'constraints' => [new IsTrue()],
                ],
            )
            ->add(
                'authorized',
                CheckboxType::class,
                [
                    'label' => $texts['authorized'],
                    'required' => true,
                    'constraints' => [new IsTrue()],
                ],
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => (string) $options['submit_label']],
            )
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setRequired(['consent_texts', 'submit_label']);
        $resolver->setAllowedTypes('consent_texts', 'array');
        $resolver->setAllowedTypes('submit_label', 'string');
        $resolver->setDefault('translation_domain', false);
    }

    public function getBlockPrefix(): string
    {
        return 'pactum_consent';
    }
}
