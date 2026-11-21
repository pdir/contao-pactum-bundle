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
use Pdir\PactumBundle\Announcement\ObjectionService;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Objection against an announced sub-processor change: affected sub-processors,
 * name and the (required) reason.
 *
 * @extends AbstractContaoType<array{subprocessors: list<string>, name: string|null, reason: string|null}>
 */
class ObjectionType extends AbstractContaoType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = (string) $options['pactum_locale'];

        /** @var list<string> $names */
        $names = $options['subprocessors'];

        $builder
            ->add(
                'subprocessors',
                ChoiceType::class,
                [
                    'label' => $this->translator->trans('objection.form.subprocessors', [], 'pactum', $locale),
                    'choices' => array_combine($names, $names),
                    'multiple' => true,
                    'expanded' => true,
                    'constraints' => [new Count(min: 1, minMessage: $this->translator->trans('objection.form.subprocessors_required', [], 'pactum', $locale))],
                ],
            )
            ->add(
                'name',
                TextType::class,
                [
                    'label' => $this->translator->trans('objection.form.name', [], 'pactum', $locale),
                    'constraints' => [new NotBlank(), new Length(max: 255)],
                    'attr' => ['autocomplete' => 'name'],
                ],
            )
            ->add(
                'reason',
                TextareaType::class,
                [
                    'label' => $this->translator->trans('objection.form.reason', [], 'pactum', $locale),
                    'help' => $this->translator->trans('objection.form.reason_help', [], 'pactum', $locale),
                    'constraints' => [new NotBlank(), new Length(max: ObjectionService::MAX_REASON_LENGTH)],
                    'attr' => ['rows' => 6],
                ],
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => $this->translator->trans('objection.form.submit', [], 'pactum', $locale)],
            )
        ;

        // Customer input must never form placeholders or Contao insert tags
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event): void {
                $data = $event->getData();

                if (!\is_array($data)) {
                    return;
                }

                foreach (['name', 'reason'] as $field) {
                    if (isset($data[$field]) && \is_string($data[$field])) {
                        // Keep line breaks in the reason, remove other control characters
                        $data[$field] = trim((string) preg_replace('/[{}\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $data[$field]));
                    }
                }

                $event->setData($data);
            },
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setRequired(['pactum_locale', 'subprocessors']);
        $resolver->setAllowedTypes('pactum_locale', 'string');
        $resolver->setAllowedTypes('subprocessors', 'string[]');
        $resolver->setDefault('translation_domain', false);
    }

    public function getBlockPrefix(): string
    {
        return 'pactum_objection';
    }
}
