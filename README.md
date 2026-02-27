# Mautic External Contacts Plugin

Mautic 7.x plugin that protects contact fields managed by external applications from being edited in the Mautic UI. When a contact is owned by an external provider, configured fields become read-only in the UI while remaining writable via the API and direct SQL.

## Features

- **Provider field**: Adds a `provider` text field to contacts — set by external apps to claim ownership
- **Field protection**: Configured fields become read-only in the Mautic UI when a provider is set
- **Per-provider config**: Each provider can protect a different set of fields
- **API pass-through**: Mautic REST API requests bypass protection — external apps can always update their contacts
- **Visual indicators**: Protected fields show a "Protected" badge and are greyed out in the edit form
- **Admin UI**: Manage providers and their protected fields from Settings > External Contacts

## Requirements

- Mautic 7.x (Docker FPM image)
- PHP 8.0+

## Installation

### Via Composer (Docker)

Ensure the composer and npm directories exist with correct permissions:

```bash
docker exec --user root mautic_web mkdir -p /var/www/.composer/cache/files /var/www/.composer/cache/repo /var/www/.composer/cache/vcs
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.composer
docker exec --user root mautic_web mkdir -p /var/www/.npm
docker exec --user root mautic_web chown -R www-data:www-data /var/www/.npm
```

Allow dev packages (only needed once per Mautic installation):

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config minimum-stability dev
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config prefer-stable true
```

Add the GitHub repository and install the plugin:

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer config repositories.mautic-external-contacts vcs \
  https://github.com/radata/mautic-external-contacts --no-interaction
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer require radata/mautic-external-contacts:dev-main \
  -W --no-interaction --ignore-platform-req=ext-gd
```

### Post-Installation

Clear cache, reload plugins, then enable in UI:

```bash
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

1. Go to **Settings > Plugins > External Contacts**
2. Set **Published** to **Yes**
3. The `provider` custom field is created automatically on install

### Update

```bash
docker exec --user www-data mautic_web rm -rf /var/www/html/vendor/composer/cache && \
docker exec --user www-data mautic_web composer clear-cache --working-dir=/var/www/html && \
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer update radata/mautic-external-contacts:dev-main -W --no-interaction --ignore-platform-req=ext-gd && \
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

## Configuration

### 1. Add a Provider

Go to **Settings > External Contacts** (admin menu) to configure providers:

| Field | Description |
|---|---|
| **Provider Name** | Must match the value stored in the contact's `provider` field (e.g. `APP`, `hollandworx`) |
| **Protected Fields** | Multi-select of contact field aliases that should be read-only in the UI |
| **Active** | Enable/disable protection for this provider |

### 2. Set Provider on Contacts

Set the `provider` field on contacts via your external app. This can be done via:

- **Mautic REST API**: `PATCH /api/contacts/{id}/edit` with `{"provider": "APP"}`
- **Direct SQL**: `UPDATE leads SET provider = 'APP' WHERE worker_id IS NOT NULL`

Once a contact has a `provider` value matching a configured provider, the protected fields become read-only in the Mautic UI.

## How It Works

### Backend Protection (LeadSubscriber)

Listens to `LEAD_PRE_SAVE` with high priority. When a contact is saved from the UI:

1. Checks if the contact has a `provider` value
2. Looks up the provider configuration
3. Detects UI requests (non-API routes don't have `mautic_api_` prefix)
4. Reverts any changes to protected fields back to their original values
5. The `provider` field itself is always protected from UI changes

API requests (`mautic_api_*` routes) bypass all protection — external apps can always update their contacts.

### Visual Protection (JavaScript)

On the contact edit form, when a provider is detected:

- A **"Managed by: {provider}"** badge appears next to the contact name
- Protected fields are visually disabled (greyed out, cursor: not-allowed)
- Each protected field gets a **"Protected"** label badge

## Custom Fields

The plugin creates one custom contact field on install:

| Field | Alias | Type | Description |
|---|---|---|---|
| **Provider** | `provider` | Text | Name of the external app that owns this contact |

## Example: n8n Integration

In your n8n SQL query, reference the provider field:

```sql
SELECT JSON_OBJECT(
  'id',       l.id,
  'email',    LOWER(l.email),
  'provider', l.provider,
  'worker_id', l.worker_id,
  'aws_id',   l.aws_id
  -- ...
) AS mauticData
FROM leads AS l
WHERE l.worker_id = $2 OR LOWER(l.email) = $3
LIMIT 1;
```

When creating contacts via the Mautic API, include `"provider": "APP"` to mark them as externally managed.

## Plugin Structure

```
plugins/ExternalContactsBundle/
├── Config/config.php                          # Routes, menu, services
├── Integration/
│   └── ExternalContactsIntegration.php        # Plugin registration (no auth needed)
├── Controller/
│   └── ProviderController.php                 # Admin CRUD for provider configs
├── Entity/
│   ├── ProviderConfig.php                     # Doctrine entity (external_contact_providers table)
│   └── ProviderConfigRepository.php           # Repository wrapper with findActiveByName()
├── Migrations/
│   └── M001_CreateProviderConfigTable.php     # Creates external_contact_providers table
├── EventListener/
│   ├── PluginSubscriber.php                   # Creates provider field on install/update
│   ├── LeadSubscriber.php                     # LEAD_PRE_SAVE: reverts protected fields on UI save
│   └── InjectCustomContentSubscriber.php      # Injects JS to disable fields + badge
├── Helper/
│   └── FieldInstaller.php                     # Creates the provider custom field
├── Resources/views/Provider/
│   ├── index.html.twig                        # Provider list page
│   └── form.html.twig                         # Add/edit provider form
├── Translations/en_US/messages.ini
├── ExternalContactsBundle.php                 # Bundle class
└── composer.json
```

## Uninstall

```bash
docker exec --user www-data --workdir /var/www/html mautic_web \
  composer remove radata/mautic-external-contacts -W --no-interaction
docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
docker exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload
```

## Troubleshooting

### Log Files

Check for ExternalContacts entries in Mautic logs:

```bash
docker exec mautic_web grep -i ExternalContacts /var/www/html/var/logs/mautic_prod-$(date +%Y-%m-%d).php
```

### Protected fields still editable

1. **Verify provider value**: Check the contact's `provider` field matches the configured provider name exactly (case-sensitive)
2. **Check provider is active**: Go to Settings > External Contacts and verify the provider is marked Active
3. **Clear cache** after plugin changes:
   ```bash
   docker exec --user www-data mautic_web rm -rf /var/www/html/var/cache/prod
   docker exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:warmup --env=prod
   ```

### Provider field not showing

1. Run `mautic:plugins:reload` to trigger field creation
2. Check Mautic logs for field creation errors
3. Verify the field exists: **Settings > Custom Fields** — look for `provider`

## License

MIT - see [LICENSE](LICENSE) for details.
