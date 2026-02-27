<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class LeadSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ProviderConfigRepository $providerConfigRepository,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_SAVE => ['onLeadPreSave', 100],
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

        $this->logger->debug('ExternalContacts: protected fields = [{fields}]', [
            'fields' => implode(', ', $protectedFields),
        ]);

        if (empty($protectedFields)) {
            return;
        }

        // Always protect the provider field itself from UI changes
        $protectedFields[] = 'provider';
        $protectedFields   = array_unique($protectedFields);

        $updatedFields = $lead->getUpdatedFields();
        $changes       = $lead->getChanges();

        $this->logger->debug('ExternalContacts: updatedFields keys = [{keys}]', [
            'keys' => implode(', ', array_keys($updatedFields)),
        ]);
        $this->logger->debug('ExternalContacts: changes keys = [{keys}], fields keys = [{fieldKeys}]', [
            'keys'      => implode(', ', array_keys($changes)),
            'fieldKeys' => implode(', ', array_keys($changes['fields'] ?? [])),
        ]);

        $reverted = [];

        foreach ($protectedFields as $fieldAlias) {
            if (!array_key_exists($fieldAlias, $updatedFields)) {
                continue;
            }

            // Get the original value from the changes array
            if (isset($changes['fields'][$fieldAlias])) {
                $originalValue = $changes['fields'][$fieldAlias][0];

                $this->logger->debug('ExternalContacts: reverting "{field}" from "{new}" back to "{orig}"', [
                    'field' => $fieldAlias,
                    'new'   => $updatedFields[$fieldAlias],
                    'orig'  => $originalValue,
                ]);

                $lead->addUpdatedField($fieldAlias, $originalValue, $updatedFields[$fieldAlias]);
                $reverted[] = $fieldAlias;
            }
        }

        if (!empty($reverted)) {
            $this->logger->info('ExternalContacts: reverted UI changes to protected fields [{fields}] for provider "{provider}".', [
                'fields'   => implode(', ', $reverted),
                'provider' => $provider,
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
