<?php

namespace MauticPlugin\ExternalContactsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

class ProviderConfig extends CommonEntity
{
    private ?int $id = null;

    private string $providerName = '';

    private array $protectedFields = [];

    private array $protectedCompanyFields = [];

    private bool $isActive = true;

    private ?\DateTimeInterface $dateAdded = null;

    private ?\DateTimeInterface $dateModified = null;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('external_contact_providers');

        $builder->addId();

        $builder->createField('providerName', Types::STRING)
            ->columnName('provider_name')
            ->length(191)
            ->unique()
            ->build();

        $builder->createField('protectedFields', Types::JSON)
            ->columnName('protected_fields')
            ->build();

        $builder->createField('protectedCompanyFields', Types::JSON)
            ->columnName('protected_company_fields')
            ->build();

        $builder->createField('isActive', Types::BOOLEAN)
            ->columnName('is_active')
            ->build();

        $builder->addDateAdded(true);

        $builder->createField('dateModified', Types::DATETIME_MUTABLE)
            ->columnName('date_modified')
            ->nullable()
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function setProviderName(string $providerName): self
    {
        $this->providerName = $providerName;

        return $this;
    }

    public function getProtectedFields(): array
    {
        return $this->normalizeFieldAliases($this->protectedFields);
    }

    public function setProtectedFields(array $protectedFields): self
    {
        $this->protectedFields = $this->normalizeFieldAliases($protectedFields);

        return $this;
    }

    public function getProtectedCompanyFields(): array
    {
        return $this->normalizeFieldAliases($this->protectedCompanyFields);
    }

    public function setProtectedCompanyFields(array $protectedCompanyFields): self
    {
        $this->protectedCompanyFields = $this->normalizeFieldAliases($protectedCompanyFields);

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDateAdded(): ?\DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(?\DateTimeInterface $dateAdded): self
    {
        $this->dateAdded = $dateAdded;

        return $this;
    }

    public function getDateModified(): ?\DateTimeInterface
    {
        return $this->dateModified;
    }

    public function setDateModified(?\DateTimeInterface $dateModified): self
    {
        $this->dateModified = $dateModified;

        return $this;
    }

    public function getName(): string
    {
        return $this->providerName;
    }

    /**
     * @param array<int, mixed> $fieldAliases
     *
     * @return string[]
     */
    private function normalizeFieldAliases(array $fieldAliases): array
    {
        $normalized = [];

        foreach ($fieldAliases as $fieldAlias) {
            if (!is_string($fieldAlias)) {
                continue;
            }

            $fieldAlias = trim($fieldAlias);
            if ('' === $fieldAlias) {
                continue;
            }

            $normalized[] = $fieldAlias;
        }

        return array_values(array_unique($normalized));
    }
}
