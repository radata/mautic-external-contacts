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
        if ($this->isApiRequest()) {
            return;
        }

        $lead     = $event->getLead();
        $provider = $lead->getFieldValue('provider');

        if (empty($provider)) {
            return;
        }

        $config = $this->providerConfigRepository->findActiveByName($provider);

        if (!$config) {
            return;
        }

        $protectedFields = $config->getProtectedFields();

        if (empty($protectedFields)) {
            return;
        }

        // Always protect the provider field itself from UI changes
        $protectedFields[] = 'provider';
        $protectedFields   = array_unique($protectedFields);

        $updatedFields = $lead->getUpdatedFields();
        $changes       = $lead->getChanges();
        $reverted      = [];

        foreach ($protectedFields as $fieldAlias) {
            if (!array_key_exists($fieldAlias, $updatedFields)) {
                continue;
            }

            // Get the original value from the changes array
            if (isset($changes['fields'][$fieldAlias])) {
                $originalValue = $changes['fields'][$fieldAlias][0];
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
