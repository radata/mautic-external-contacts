<?php

namespace MauticPlugin\ExternalContactsBundle\Helper;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Psr\Log\LoggerInterface;

class FieldInstaller
{
    private const FIELDS = [
        [
            'alias'      => 'provider',
            'label'      => 'Provider',
            'type'       => 'text',
            'group'      => 'core',
            'object'     => 'lead',
            'visible'    => true,
            'properties' => [],
        ],
    ];

    public function __construct(
        private FieldModel $fieldModel,
        private LoggerInterface $logger,
    ) {
    }

    public function installFields(): void
    {
        foreach (self::FIELDS as $config) {
            $existing = $this->fieldModel->getEntityByAlias($config['alias']);

            if ($existing) {
                $this->logger->info('ExternalContacts: field "{alias}" already exists, skipping.', [
                    'alias' => $config['alias'],
                ]);
                continue;
            }

            try {
                $field = new LeadField();
                $field->setAlias($config['alias']);
                $field->setLabel($config['label']);
                $field->setType($config['type']);
                $field->setGroup($config['group']);
                $field->setObject($config['object']);
                $field->setIsPublished(true);
                $field->setIsListable(true);
                $field->setIsVisible($config['visible'] ?? true);
                $field->setProperties($config['properties']);

                $this->fieldModel->saveEntity($field);

                $this->logger->info('ExternalContacts: created custom field "{alias}".', [
                    'alias' => $config['alias'],
                ]);
            } catch (\Exception $e) {
                $this->logger->error('ExternalContacts: failed to create field "{alias}": {message}', [
                    'alias'   => $config['alias'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
