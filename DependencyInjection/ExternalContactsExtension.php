<?php

declare(strict_types=1);

namespace MauticPlugin\ExternalContactsBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class ExternalContactsExtension extends Extension
{
    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config'));
        // Not named services.php on purpose: app/config/services.php only auto-registers a
        // plugin's classes (controllers, subscribers, ...) when that file is absent.
        $loader->load('legacy_aliases.php');
    }
}
