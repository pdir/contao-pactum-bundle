<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle;

use Pdir\PactumBundle\Rendering\PlaceholderProviderInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class PactumBundle extends AbstractBundle
{
    protected string $extensionAlias = 'pactum';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('invitation_ttl_days')
            ->info('Default lifetime of an invitation link in days.')
            ->min(1)
            ->defaultValue(30)
            ->end()
            ->integerNode('optin_ttl_hours')
            ->info('Lifetime of the double opt-in confirmation link in hours.')
            ->min(1)
            ->defaultValue(24)
            ->end()
            ->enumNode('ip_mode')
            ->info('How to store the IP address on conclusion: "none", "anonymized" (default) or "full".')
            ->values(['none', 'anonymized', 'full'])
            ->defaultValue('anonymized')
            ->end()
            ->booleanNode('store_user_agent')
            ->info('Store the user agent on conclusion.')
            ->defaultFalse()
            ->end()
            ->integerNode('ip_retention_days')
            ->info('Delete IP address and user agent of agreements after this many days.')
            ->min(1)
            ->defaultValue(90)
            ->end()
            ->integerNode('cleanup_after_days')
            ->info('Anonymize invitations that were not completed (expired/revoked) after this many days.')
            ->min(1)
            ->defaultValue(30)
            ->end()
            ->integerNode('agreement_retention_days')
            ->info('Delete concluded agreements (incl. PDF and audit log) after this many days. Null = never (default).')
            ->min(1)
            ->defaultNull()
            ->end()
            ->scalarNode('storage_dir')
            ->info('Directory for generated PDFs. Must not be publicly accessible.')
            ->cannotBeEmpty()
            ->defaultValue('%kernel.project_dir%/var/pactum')
            ->end()
            ->integerNode('download_ttl_minutes')
            ->info('Lifetime of signed frontend download links in minutes.')
            ->min(1)
            ->defaultValue(15)
            ->end()
            ->end()
        ;
    }

    /**
     * @param array{invitation_ttl_days: int, optin_ttl_hours: int, ip_mode: string, store_user_agent: bool, ip_retention_days: int, cleanup_after_days: int, agreement_retention_days: int|null, storage_dir: string, download_ttl_minutes: int} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ($config as $key => $value) {
            $container->parameters()->set('pactum.'.$key, $value);
        }

        $builder
            ->registerForAutoconfiguration(PlaceholderProviderInterface::class)
            ->addTag('pactum.placeholder_provider')
        ;

        $container->import('../config/services.yaml');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'pactum_token' => [
                    'policy' => 'sliding_window',
                    'limit' => 20,
                    'interval' => '10 minutes',
                ],
                'pactum_submit' => [
                    'policy' => 'sliding_window',
                    'limit' => 15,
                    'interval' => '10 minutes',
                ],
                'pactum_optin_mail' => [
                    'policy' => 'sliding_window',
                    'limit' => 3,
                    'interval' => '60 minutes',
                ],
            ],
        ]);
    }
}
