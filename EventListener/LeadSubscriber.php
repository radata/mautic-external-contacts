<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadSubscriber implements EventSubscriberInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $pendingRestores = [];
    /** @var string[]|null */
    private ?array $leadColumns = null;

    public function __construct(
        private ProviderConfigRepository $providerConfigRepository,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_SAVE  => ['onLeadPreSave', 100],
            LeadEvents::LEAD_POST_SAVE => ['onLeadPostSave', -100],
        ];
    }

    public function onLeadPreSave(LeadEvent $event): void
    {
        $lead = $event->getLead();

        if ($this->isApiRequest()) {
            return;
        }

        $leadId = (int) $lead->getId();
        if ($leadId < 1) {
            return;
        }

        $leadTable   = MAUTIC_TABLE_PREFIX.'leads';
        $leadColumns = $this->getLeadColumns($leadTable);
        if (empty($leadColumns) || !in_array('provider', $leadColumns, true)) {
            return;
        }

        $persistedProvider = $this->connection->fetchOne(
            sprintf('SELECT provider FROM `%s` WHERE id = :id', $this->escapeSqlIdentifier($leadTable)),
            ['id' => $leadId]
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
            array_merge($config->getProtectedFields(), ['provider'])
        );
        $protectedFields = array_values(array_intersect($protectedFields, $leadColumns));
        if (empty($protectedFields)) {
            return;
        }

        $persistedValues = $this->fetchPersistedLeadValues($leadTable, $leadId, $protectedFields);
        if (empty($persistedValues)) {
            return;
        }

        // Keep a post-save hard restore in case any later listener mutates values again.
        $this->pendingRestores[$leadId] = $persistedValues;

        // Also restore in-entity before flush so ORM writes protected values back immediately.
        foreach ($persistedValues as $fieldAlias => $originalValue) {
            $currentValue = $lead->getFieldValue($fieldAlias);
            if ($this->valuesDiffer($currentValue, $originalValue)) {
                $lead->addUpdatedField($fieldAlias, $originalValue, $currentValue);
            }
        }
    }

    public function onLeadPostSave(LeadEvent $event): void
    {
        if ($this->isApiRequest()) {
            return;
        }

        $lead   = $event->getLead();
        $leadId = (int) $lead->getId();

        if ($leadId < 1 || empty($this->pendingRestores[$leadId])) {
            return;
        }

        $toRestore = $this->pendingRestores[$leadId];
        unset($this->pendingRestores[$leadId]);

        try {
            $this->connection->update(
                MAUTIC_TABLE_PREFIX.'leads',
                $toRestore,
                ['id' => $leadId]
            );

            if ($this->entityManager->contains($lead)) {
                $this->entityManager->refresh($lead);
            }
        } catch (\Throwable $e) {
            $this->logger->error('ExternalContacts: failed to restore protected fields for lead #{id}: {msg}', [
                'id'  => $leadId,
                'msg' => $e->getMessage(),
            ]);
        }
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
    private function getLeadColumns(string $leadTable): array
    {
        if (null !== $this->leadColumns) {
            return $this->leadColumns;
        }

        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns($leadTable);
        } catch (\Throwable $e) {
            $this->logger->error('ExternalContacts: unable to read lead table schema: {msg}', [
                'msg' => $e->getMessage(),
            ]);

            return [];
        }

        $this->leadColumns = [];
        foreach ($columns as $column) {
            $this->leadColumns[] = $column->getName();
        }

        return $this->leadColumns;
    }

    /**
     * @param string[] $protectedFields
     *
     * @return array<string, mixed>
     */
    private function fetchPersistedLeadValues(string $leadTable, int $leadId, array $protectedFields): array
    {
        $selectColumns = [];
        foreach ($protectedFields as $fieldAlias) {
            $selectColumns[] = sprintf('`%s`', $this->escapeSqlIdentifier($fieldAlias));
        }

        $sql = sprintf(
            'SELECT %s FROM `%s` WHERE id = :id',
            implode(', ', $selectColumns),
            $this->escapeSqlIdentifier($leadTable)
        );

        $row = $this->connection->fetchAssociative($sql, ['id' => $leadId]);
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
}
