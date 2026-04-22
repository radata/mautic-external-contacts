<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\LeadBundle\Event\CompanyEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfig;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadSubscriber implements EventSubscriberInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $pendingRestores = [];
    /** @var array<string, array{provider: string, fields: string[]}> */
    private array $pendingWarnings = [];
    /** @var array<string, string[]> */
    private array $tableColumns = [];

    public function __construct(
        private ProviderConfigRepository $providerConfigRepository,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private FlashBag $flashBag,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_SAVE     => ['onLeadPreSave', 100],
            LeadEvents::LEAD_POST_SAVE    => ['onLeadPostSave', -100],
            LeadEvents::COMPANY_PRE_SAVE  => ['onCompanyPreSave', 100],
            LeadEvents::COMPANY_POST_SAVE => ['onCompanyPostSave', -100],
        ];
    }

    public function onLeadPreSave(LeadEvent $event): void
    {
        $this->protectEntityFromUiSave(
            'lead',
            $event->getLead(),
            static fn (ProviderConfig $config): array => $config->getProtectedFields()
        );
    }

    public function onLeadPostSave(LeadEvent $event): void
    {
        $this->restoreEntityAfterUiSave('lead', $event->getLead());
    }

    public function onCompanyPreSave(CompanyEvent $event): void
    {
        $this->protectEntityFromUiSave(
            'company',
            $event->getCompany(),
            static fn (ProviderConfig $config): array => $config->getProtectedCompanyFields()
        );
    }

    public function onCompanyPostSave(CompanyEvent $event): void
    {
        $this->restoreEntityAfterUiSave('company', $event->getCompany());
    }

    private function isApiRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            // No HTTP request context (CLI/worker). Do not enforce UI-only locks.
            return true;
        }

        $route = (string) $request->attributes->get('_route', '');
        if (str_starts_with($route, 'mautic_api_')) {
            return true;
        }

        return str_starts_with($request->getPathInfo(), '/api/');
    }

    /**
     * @param array<int, mixed> $fields
     *
     * @return string[]
     */
    private function normalizeProtectedFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }

            $alias = trim($field);
            if ('' === $alias) {
                continue;
            }

            $normalized[] = $alias;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return string[]
     */
    private function getTableColumns(string $tableName): array
    {
        if (isset($this->tableColumns[$tableName])) {
            return $this->tableColumns[$tableName];
        }

        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);
        } catch (\Throwable $e) {
            $this->logger->error('ExternalContacts: unable to read table schema for {table}: {msg}', [
                'table' => $tableName,
                'msg' => $e->getMessage(),
            ]);

            return [];
        }

        $this->tableColumns[$tableName] = [];
        foreach ($columns as $column) {
            $this->tableColumns[$tableName][] = $column->getName();
        }

        return $this->tableColumns[$tableName];
    }

    /**
     * @param string[] $protectedFields
     *
     * @return array<string, mixed>
     */
    private function fetchPersistedFieldValues(string $tableName, int $entityId, array $protectedFields): array
    {
        $selectColumns = [];
        foreach ($protectedFields as $fieldAlias) {
            $selectColumns[] = sprintf('`%s`', $this->escapeSqlIdentifier($fieldAlias));
        }

        $sql = sprintf(
            'SELECT %s FROM `%s` WHERE id = :id',
            implode(', ', $selectColumns),
            $this->escapeSqlIdentifier($tableName)
        );

        $row = $this->connection->fetchAssociative($sql, ['id' => $entityId]);
        if (!is_array($row)) {
            return [];
        }

        return $row;
    }

    private function valuesDiffer(mixed $first, mixed $second): bool
    {
        if (is_array($first)) {
            $first = implode('|', $first);
        }
        if (is_array($second)) {
            $second = implode('|', $second);
        }

        return (string) ($first ?? '') !== (string) ($second ?? '');
    }

    private function escapeSqlIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * @param object $entity
     * @param callable(ProviderConfig): array<int, mixed> $protectedFieldsResolver
     */
    private function protectEntityFromUiSave(string $object, object $entity, callable $protectedFieldsResolver): void
    {
        if ($this->isApiRequest()) {
            return;
        }

        $entityId = (int) $entity->getId();
        if ($entityId < 1) {
            return;
        }

        $tableName      = MAUTIC_TABLE_PREFIX.$this->getTableNameForObject($object);
        $providerField  = $this->getProviderFieldForObject($object);
        $tableColumns   = $this->getTableColumns($tableName);
        $restoreKey     = $this->getEntityKey($object, $entityId);
        if (empty($tableColumns) || !in_array($providerField, $tableColumns, true)) {
            return;
        }

        $persistedProvider = $this->connection->fetchOne(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE id = :id',
                $this->escapeSqlIdentifier($providerField),
                $this->escapeSqlIdentifier($tableName)
            ),
            ['id' => $entityId]
        );
        if (!is_string($persistedProvider) || '' === trim($persistedProvider)) {
            return;
        }

        $provider = trim($persistedProvider);
        $config   = $this->providerConfigRepository->findActiveByName($provider);
        if (!$config) {
            return;
        }

        $protectedFields = $this->normalizeProtectedFields(
            array_merge($protectedFieldsResolver($config), [$providerField])
        );
        $protectedFields = array_values(array_intersect($protectedFields, $tableColumns));
        if (empty($protectedFields)) {
            return;
        }

        $persistedValues = $this->fetchPersistedFieldValues($tableName, $entityId, $protectedFields);
        if (empty($persistedValues)) {
            return;
        }

        $this->pendingRestores[$restoreKey] = $persistedValues;
        $blockedAliases                     = [];

        foreach ($persistedValues as $fieldAlias => $originalValue) {
            $currentValue = $entity->getFieldValue($fieldAlias);
            if ($this->valuesDiffer($currentValue, $originalValue)) {
                $entity->addUpdatedField($fieldAlias, $originalValue, $currentValue);
                $blockedAliases[] = $fieldAlias;
            }
        }

        if (!empty($blockedAliases)) {
            sort($blockedAliases);
            $this->pendingWarnings[$restoreKey] = [
                'provider' => $provider,
                'fields'   => $blockedAliases,
            ];
        }
    }

    private function restoreEntityAfterUiSave(string $object, object $entity): void
    {
        if ($this->isApiRequest()) {
            return;
        }

        $entityId = (int) $entity->getId();
        if ($entityId < 1) {
            return;
        }

        $restoreKey = $this->getEntityKey($object, $entityId);
        if (empty($this->pendingRestores[$restoreKey])) {
            return;
        }

        $tableName  = MAUTIC_TABLE_PREFIX.$this->getTableNameForObject($object);
        $toRestore  = $this->pendingRestores[$restoreKey];
        unset($this->pendingRestores[$restoreKey]);

        try {
            $this->connection->update($tableName, $toRestore, ['id' => $entityId]);

            if ($this->entityManager->contains($entity)) {
                $this->entityManager->refresh($entity);
            }
        } catch (\Throwable $e) {
            $this->logger->error('ExternalContacts: failed to restore protected fields for {object} #{id}: {msg}', [
                'object' => $object,
                'id'     => $entityId,
                'msg'    => $e->getMessage(),
            ]);
        }

        if (!empty($this->pendingWarnings[$restoreKey])) {
            $warning = $this->pendingWarnings[$restoreKey];
            unset($this->pendingWarnings[$restoreKey]);

            $this->flashBag->add(
                'external_contacts.notice.protected_fields_ignored',
                [
                    '%provider%' => $warning['provider'],
                    '%fields%'   => implode(', ', $warning['fields']),
                ],
                FlashBag::LEVEL_WARNING,
                'messages'
            );
        }
    }

    private function getEntityKey(string $object, int $entityId): string
    {
        return $object.':'.$entityId;
    }

    private function getProviderFieldForObject(string $object): string
    {
        return match ($object) {
            'company' => 'companyprovider',
            default   => 'provider',
        };
    }

    private function getTableNameForObject(string $object): string
    {
        return match ($object) {
            'company' => 'companies',
            default   => 'leads',
        };
    }
}
