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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Step 1: customer and signatory data.
 *
 * @extends AbstractContaoType<array<string, string|null>>
 */
class CustomerDataType extends AbstractContaoType
{
    public const FIELDS = [
        'company' => true,
        'street' => true,
        'postal' => true,
        'city' => true,
        'country' => false,
        'signatory_name' => true,
        'signatory_role' => true,
        'signatory_email' => true,
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = (string) $options['pactum_locale'];

        foreach (self::FIELDS as $name => $required) {
            $constraints = [new Length(max: 'postal' === $name ? 32 : 255)];

            if ($required) {
                $constraints[] = new NotBlank();
            }

            if ('signatory_email' === $name) {
                $constraints[] = new Email(mode: Email::VALIDATION_MODE_STRICT);
            }

            $builder->add(
                $name,
                'signatory_email' === $name ? EmailType::class : TextType::class,
                [
                    'label' => $this->translator->trans('form.'.$name, [], 'pactum', $locale),
                    'required' => $required,
                    'constraints' => $constraints,
                    'attr' => ['autocomplete' => $this->getAutocomplete($name)],
                ],
            );
        }

        $builder->add(
            'submit',
            SubmitType::class,
            ['label' => $this->translator->trans('form.submit_customer_data', [], 'pactum', $locale)],
        );

        // Customer input must never form placeholders or Contao insert tags
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event): void {
                $data = $event->getData();

                if (!\is_array($data)) {
                    return;
                }

                foreach (array_keys(self::FIELDS) as $name) {
                    if (isset($data[$name]) && \is_string($data[$name])) {
                        $data[$name] = trim((string) preg_replace('/[{}\x00-\x1F\x7F]/u', '', $data[$name]));
                    }
                }

                $event->setData($data);
            },
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setRequired('pactum_locale');
        $resolver->setAllowedTypes('pactum_locale', 'string');
        $resolver->setDefault('translation_domain', false);
    }

    public function getBlockPrefix(): string
    {
        return 'pactum_customer';
    }

    private function getAutocomplete(string $name): string
    {
        return match ($name) {
            'company' => 'organization',
            'street' => 'street-address',
            'postal' => 'postal-code',
            'city' => 'address-level2',
            'country' => 'country-name',
            'signatory_name' => 'name',
            'signatory_role' => 'organization-title',
            'signatory_email' => 'email',
            default => 'off',
        };
    }
}
