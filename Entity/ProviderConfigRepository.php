<?php

namespace MauticPlugin\ExternalContactsBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<ProviderConfig>
 */
class ProviderConfigRepository extends CommonRepository
{
    public function findActiveByName(string $providerName): ?ProviderConfig
    {
        return $this->findOneBy([
            'providerName' => $providerName,
            'isActive'     => true,
        ]);
    }

    /**
     * @return ProviderConfig[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }

    public function getTableAlias(): string
    {
        return 'ecp';
    }
}
