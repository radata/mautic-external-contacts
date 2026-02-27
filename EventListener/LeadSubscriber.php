<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadSubscriber implements EventSubscriberInterface
{
    /**
     * Stores original values to restore after save.
     * Keyed by lead ID → [alias => originalValue].
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pendingRestores = [];

    public function __construct(
        private ProviderConfigRepository $providerConfigRepository,
        private RequestStack $requestStack,
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

        $this->logger->debug('ExternalContacts: LEAD_PRE_SAVE fired for lead ID={id}', [
            'id' => $lead->getId(),
        ]);

        if ($this->isApiRequest()) {
            $this->logger->debug('ExternalContacts: skipping — API request');

            return;
        }

        // For new contacts, nothing to protect
        if (!$lead->getId()) {
            return;
        }

        $provider = $lead->getFieldValue('provider');

        $this->logger->debug('ExternalContacts: provider value = "{provider}"', [
            'provider' => $provider ?? '(null)',
        ]);

        if (empty($provider)) {
            return;
        }

        $config = $this->providerConfigRepository->findActiveByName($provider);

        if (!$config) {
            $this->logger->debug('ExternalContacts: no active config for provider "{provider}"', [
                'provider' => $provider,
            ]);

            return;
        }

        $protectedFields = $config->getProtectedFields();

        if (empty($protectedFields)) {
            return;
        }

        // Always protect the provider field itself from UI changes
        $protectedFields[] = 'provider';
        $protectedFields   = array_unique($protectedFields);

        $this->logger->debug('ExternalContacts: protected fields = [{fields}]', [
            'fields' => implode(', ', $protectedFields),
        ]);

        $changes = $lead->getChanges();
        $updatedFields = $lead->getUpdatedFields();

        $this->logger->debug('ExternalContacts: updatedFields keys = [{keys}]', [
            'keys' => implode(', ', array_keys($updatedFields)),
        ]);
        $this->logger->debug('ExternalContacts: changes keys = [{keys}], fields keys = [{fieldKeys}]', [
            'keys'      => implode(', ', array_keys($changes)),
            'fieldKeys' => implode(', ', array_keys($changes['fields'] ?? [])),
        ]);

        // Collect original values for protected fields that were changed
        $toRestore = [];

        foreach ($protectedFields as $fieldAlias) {
            // Check if this field was changed (via addUpdatedField / changes tracking)
            if (isset($changes['fields'][$fieldAlias])) {
                $originalValue = $changes['fields'][$fieldAlias][0]; // [0] = old value
                $toRestore[$fieldAlias] = $originalValue;

                $this->logger->info(
                    'ExternalContacts: will restore "{field}" from "{new}" back to "{orig}" after save',
                    [
                        'field' => $fieldAlias,
                        'new'   => $changes['fields'][$fieldAlias][1] ?? '?',
                        'orig'  => $originalValue,
                    ]
                );
            }
        }

        if (!empty($toRestore)) {
            $this->pendingRestores[$lead->getId()] = $toRestore;
        }
    }

    public function onLeadPostSave(LeadEvent $event): void
    {
        $lead   = $event->getLead();
        $leadId = $lead->getId();

        if (empty($this->pendingRestores[$leadId])) {
            return;
        }

        $toRestore = $this->pendingRestores[$leadId];
        unset($this->pendingRestores[$leadId]);

        $this->logger->info('ExternalContacts: restoring {count} protected fields for lead #{id} via DBAL', [
            'count' => count($toRestore),
            'id'    => $leadId,
        ]);

        // Direct DBAL UPDATE to restore original values — bypasses ORM entirely
        try {
            $this->connection->update(
                MAUTIC_TABLE_PREFIX.'leads',
                $toRestore,
                ['id' => $leadId]
            );

            $this->logger->info('ExternalContacts: successfully restored fields [{fields}] for lead #{id}', [
                'fields' => implode(', ', array_keys($toRestore)),
                'id'     => $leadId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('ExternalContacts: DBAL restore failed for lead #{id}: {msg}', [
                'id'  => $leadId,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function isApiRequest(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return false;
        }

        $route = $request->attributes->get('_route', '');

        return str_starts_with($route, 'mautic_api_');
    }
}
