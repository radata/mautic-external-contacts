<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InjectCustomContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ProviderConfigRepository $providerConfigRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['injectCustomContent', 0],
            CoreEvents::VIEW_INJECT_CUSTOM_ASSETS  => ['injectCustomAssets', 0],
        ];
    }

    /**
     * Inject a global JS that applies UI protection to lead/company forms on every page load.
     */
    public function injectCustomAssets(CustomAssetsEvent $event): void
    {
        $providerConfigs = [];
        foreach ($this->providerConfigRepository->findAllActive() as $config) {
            $providerConfigs[$config->getProviderName()] = [
                'lead'    => $config->getProtectedFields(),
                'company' => $config->getProtectedCompanyFields(),
            ];
        }

        $providerConfigsJson = json_encode(
            $providerConfigs,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (false === $providerConfigsJson) {
            $providerConfigsJson = '{}';
        }

        $script = str_replace('__EC_PROVIDER_CONFIGS__', $providerConfigsJson, <<<'JS'
(function() {
    var ecProviderConfigs = __EC_PROVIDER_CONFIGS__;
    var ecIsBound = false;

    function ecNormalize(value) {
        return String(value || '').trim();
    }

    function ecGetFormConfig(container) {
        var scope = mQuery(container || document);
        var formEl = scope.find('form[name="lead"]').first();
        if (formEl.length) {
            return { formEl: formEl, object: 'lead', providerField: 'provider' };
        }

        formEl = scope.find('form[name="company"]').first();
        if (formEl.length) {
            return { formEl: formEl, object: 'company', providerField: 'companyprovider' };
        }

        formEl = mQuery('form[name="lead"]').first();
        if (formEl.length) {
            return { formEl: formEl, object: 'lead', providerField: 'provider' };
        }

        formEl = mQuery('form[name="company"]').first();
        if (formEl.length) {
            return { formEl: formEl, object: 'company', providerField: 'companyprovider' };
        }

        return null;
    }

    function ecGetEntityId(formConfig) {
        var idSelectors = [
            '#' + formConfig.object + '_id',
            'input[name="' + formConfig.object + '[id]"]'
        ];
        var idInput = formConfig.formEl.find(idSelectors.join(', ')).first();
        if (!idInput.length) {
            return 0;
        }

        var entityId = parseInt(idInput.val() || '0', 10);
        return isNaN(entityId) ? 0 : entityId;
    }

    function ecGetField(formConfig, alias) {
        var selectors = [
            '#' + formConfig.object + '_' + alias,
            '[name="' + formConfig.object + '[' + alias + ']"]'
        ];

        return formConfig.formEl.find(selectors.join(', ')).first();
    }

    function ecGetProvider(formConfig) {
        var field = ecGetField(formConfig, formConfig.providerField);
        if (!field.length) {
            return '';
        }

        return ecNormalize(field.val());
    }

    function ecGetProtectedFields(formConfig, provider) {
        var providerConfig = ecProviderConfigs[provider] || {};
        var fields = providerConfig[formConfig.object] || [];
        var seen = {};
        var result = [];

        if (!Array.isArray(fields)) {
            fields = [];
        }

        fields.concat([formConfig.providerField]).forEach(function(alias) {
            alias = ecNormalize(alias);
            if (!alias || seen[alias]) {
                return;
            }

            seen[alias] = true;
            result.push(alias);
        });

        return result;
    }

    function ecEnsureNotice(formConfig, provider) {
        var notice = formConfig.formEl.prev('.ec-managed-notice');
        var noticeText = 'Managed by: ' + provider;

        if (!notice.length) {
            notice = mQuery('<div class="alert alert-warning ec-managed-notice">').text(noticeText);
            formConfig.formEl.before(notice);
            return;
        }

        notice.text(noticeText);
    }

    function ecLockField(formConfig, alias) {
        var field = ecGetField(formConfig, alias);
        if (!field.length) {
            return;
        }

        field.each(function() {
            var el = mQuery(this);
            el.attr('readonly', 'readonly');
            el.attr('disabled', 'disabled');
            el.css({
                'background-color': '#f5f5f5',
                'opacity': '0.7',
                'cursor': 'not-allowed',
                'pointer-events': 'none'
            });

            if (el.hasClass('chosen-select') || el.next('.chosen-container').length) {
                el.trigger('chosen:updated');
            }

            var formGroup = el.closest('.form-group');
            if (formGroup.length && !formGroup.find('.ec-protected-badge[data-ec-alias="' + alias + '"]').length) {
                var badge = mQuery('<span>')
                    .addClass('label label-default ec-protected-badge')
                    .attr('data-ec-alias', alias)
                    .css({'margin-left': '5px', 'font-size': '10px'})
                    .text('Protected');
                formGroup.find('label').first().append(badge);
            }
        });
    }

    function ecApplyProtection(container) {
        var formConfig = ecGetFormConfig(container);
        var entityId;
        var provider;
        var protectedFields;

        if (!formConfig) return;

        entityId = ecGetEntityId(formConfig);
        if (!entityId) return;

        provider = ecGetProvider(formConfig);
        if (!provider || !ecProviderConfigs[provider]) return;

        protectedFields = ecGetProtectedFields(formConfig, provider);
        if (!protectedFields.length) return;

        ecEnsureNotice(formConfig, provider);
        protectedFields.forEach(function(alias) {
            ecLockField(formConfig, alias);
        });
    }

    function ecBind() {
        if (ecIsBound) return;
        ecIsBound = true;

        mQuery(document).on('mautic:onPageLoad:after', function(event, container) {
            ecApplyProtection(container);
        });

        mQuery(document).ready(function() {
            ecApplyProtection('#app-content');
        });
    }

    if (typeof window.mQuery === 'function') {
        ecBind();
        return;
    }

    var tries = 0;
    var waitForMQuery = setInterval(function() {
        if (typeof window.mQuery === 'function') {
            clearInterval(waitForMQuery);
            ecBind();
            return;
        }

        tries++;
        if (tries > 80) {
            clearInterval(waitForMQuery);
        }
    }, 50);
})();
JS
        );

        $event->addScriptDeclaration($script);
    }

    /**
     * Inject a provider badge on lead views that already expose a custom content hook.
     */
    public function injectCustomContent(CustomContentEvent $event): void
    {
        if ('lead.name.after' !== $event->getContext()) {
            return;
        }

        $vars = $event->getVars();
        $lead = $vars['lead'] ?? null;

        if (!$lead) {
            return;
        }

        $provider = $lead->getFieldValue('provider');

        if (empty($provider)) {
            return;
        }

        $config = $this->providerConfigRepository->findActiveByName($provider);

        if (!$config) {
            return;
        }

        $providerName     = htmlspecialchars($provider, ENT_QUOTES, 'UTF-8');

        $event->addContent(<<<HTML
<span class="label label-warning ml-sm" title="Fields managed by this provider are read-only">
    Managed by: {$providerName}
</span>
HTML);
    }
}
