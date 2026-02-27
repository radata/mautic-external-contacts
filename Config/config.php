<?php

return [
    'name'        => 'External Contacts',
    'description' => 'Protect contact fields managed by external providers from UI editing',
    'version'     => '1.0.5',
    'author'      => 'Radata',

    'routes' => [
        'main' => [
            'mautic_external_contacts_providers' => [
                'path'       => '/external-contacts/providers',
                'controller' => 'MauticPlugin\ExternalContactsBundle\Controller\ProviderController::indexAction',
            ],
            'mautic_external_contacts_provider_new' => [
                'path'       => '/external-contacts/providers/new',
                'controller' => 'MauticPlugin\ExternalContactsBundle\Controller\ProviderController::editAction',
                'defaults'   => [
                    'objectId' => 0,
                ],
            ],
            'mautic_external_contacts_provider_edit' => [
                'path'       => '/external-contacts/providers/edit/{objectId}',
                'controller' => 'MauticPlugin\ExternalContactsBundle\Controller\ProviderController::editAction',
            ],
            'mautic_external_contacts_provider_delete' => [
                'path'       => '/external-contacts/providers/delete/{objectId}',
                'controller' => 'MauticPlugin\ExternalContactsBundle\Controller\ProviderController::deleteAction',
            ],
        ],
    ],

    'menu' => [
        'admin' => [
            'external_contacts.menu.providers' => [
                'route'     => 'mautic_external_contacts_providers',
                'iconClass' => 'ri-shield-user-line',
                'access'    => 'admin',
                'priority'  => 50,
            ],
        ],
    ],

    'services' => [
        'integrations' => [
            'mautic.integration.external_contacts' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\Integration\ExternalContactsIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
        'events' => [
            'mautic.external_contacts.subscriber.plugin' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\EventListener\PluginSubscriber::class,
                'arguments' => [
                    'mautic.external_contacts.field_installer',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.external_contacts.subscriber.lead' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\EventListener\LeadSubscriber::class,
                'arguments' => [
                    'mautic.external_contacts.provider_config_repository',
                    'request_stack',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.external_contacts.subscriber.custom_content' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\EventListener\InjectCustomContentSubscriber::class,
                'arguments' => [
                    'mautic.external_contacts.provider_config_repository',
                ],
            ],
        ],
        'others' => [
            'mautic.external_contacts.field_installer' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\Helper\FieldInstaller::class,
                'arguments' => [
                    'mautic.lead.model.field',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.external_contacts.provider_config_repository' => [
                'class'     => \MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                ],
            ],
        ],
    ],
];
