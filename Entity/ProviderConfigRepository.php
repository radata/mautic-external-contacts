<?php

namespace MauticPlugin\ExternalContactsBundle\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class ProviderConfigRepository
{
    private EntityRepository $repo;

    public function __construct(EntityManagerInterface $em)
    {
        $this->repo = $em->getRepository(ProviderConfig::class);
    }

    public function findActiveByName(string $providerName): ?ProviderConfig
    {
        return $this->repo->findOneBy([
            'providerName' => $providerName,
            'isActive'     => true,
        ]);
    }

    /**
     * @return ProviderConfig[]
     */
    public function findAllActive(): array
    {
        return $this->repo->findBy(['isActive' => true]);
    }

    /**
     * @return ProviderConfig[]
     */
    public function findAll(): array
    {
        return $this->repo->findBy([], ['providerName' => 'ASC']);
    }

    public function find(int $id): ?ProviderConfig
    {
        return $this->repo->find($id);
    }

    public function findOneBy(array $criteria): ?ProviderConfig
    {
        return $this->repo->findOneBy($criteria);
    }
}
