<?php

namespace MauticPlugin\ExternalContactsBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class ExternalContactsIntegration extends AbstractIntegration
{
    protected bool $coreIntegration = false;

    public function getName(): string
    {
        return 'ExternalContacts';
    }

    public function getDisplayName(): string
    {
        return 'External Contacts';
    }

    public function getSecretKeys(): array
    {
        return [];
    }

    public function getRequiredKeyFields(): array
    {
        return [];
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }
}
