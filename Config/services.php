<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Mautic 7.2 removed these legacy service IDs, which Config/config.php still passes as arguments.
return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->alias('mautic.helper.encryption', Mautic\CoreBundle\Helper\EncryptionHelper::class)->public();
    $services->alias('mautic.core.service.flashbag', Mautic\CoreBundle\Service\FlashBag::class)->public();
};
