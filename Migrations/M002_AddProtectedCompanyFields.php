<?php

namespace MauticPlugin\ExternalContactsBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class M002_AddProtectedCompanyFields extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix('external_contact_providers');

        return $schema->hasTable($table)
            && !$schema->getTable($table)->hasColumn('protected_company_fields');
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('external_contact_providers');

        $this->addSql("ALTER TABLE {$table} ADD protected_company_fields JSON DEFAULT NULL");
        $this->addSql("UPDATE {$table} SET protected_company_fields = '[]' WHERE protected_company_fields IS NULL");
        $this->addSql("ALTER TABLE {$table} MODIFY protected_company_fields JSON NOT NULL");
    }
}
