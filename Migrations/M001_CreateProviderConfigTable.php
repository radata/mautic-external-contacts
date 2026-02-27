<?php

namespace MauticPlugin\ExternalContactsBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class M001_CreateProviderConfigTable extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix('external_contact_providers'));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('external_contact_providers');

        $this->addSql("
            CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                provider_name VARCHAR(191) NOT NULL,
                protected_fields JSON NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                date_added DATETIME DEFAULT NULL,
                date_modified DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_provider_name (provider_name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ");
    }
}
