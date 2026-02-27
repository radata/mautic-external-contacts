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
     * Inject a global JS that hooks into Mautic.onPageLoad — runs on every page load (AJAX or full).
     */
    public function injectCustomAssets(CustomAssetsEvent $event): void
    {
        $event->addScriptDeclaration(<<<'JS'
(function() {
    /**
     * ExternalContacts: disable protected fields on the lead edit form.
     * Reads config from window._ecProtectedFields (set via customContent injection).
     * Hooks into Mautic's onPageLoad so it works with AJAX navigation.
     */
    function ecApplyProtection(container) {
        var config = window._ecProtectedFields;
        if (!config || !config.fields || !config.fields.length) return;

        var fields = config.fields;
        var formEl = mQuery(container).find('form[name="lead"]');
        if (!formEl.length) formEl = mQuery('form[name="lead"]');
        if (!formEl.length) return;

        fields.forEach(function(alias) {
            var selectors = [
                '#lead_' + alias,
                '[name="lead[' + alias + ']"]'
            ];
            selectors.forEach(function(selector) {
                mQuery(formEl).find(selector).each(function() {
                    var el = mQuery(this);
                    el.attr('readonly', 'readonly');
                    el.attr('disabled', 'disabled');
                    el.css({
                        'background-color': '#f5f5f5',
                        'opacity': '0.7',
                        'cursor': 'not-allowed',
                        'pointer-events': 'none'
                    });

                    var formGroup = el.closest('.form-group');
                    if (formGroup.length && !formGroup.find('.ec-protected-badge').length) {
                        var badge = mQuery('<span>')
                            .addClass('label label-default ec-protected-badge')
                            .css({'margin-left': '5px', 'font-size': '10px'})
                            .text('Protected');
                        formGroup.find('label').first().append(badge);
                    }
                });
            });
        });

        // Patch form submit to re-enable disabled fields so values are sent
        if (!formEl.data('ec-patched')) {
            formEl.data('ec-patched', true);
            formEl.on('submit', function() {
                fields.forEach(function(alias) {
                    mQuery('#lead_' + alias).removeAttr('disabled');
                });
            });
        }
    }

    // Hook into Mautic's page load lifecycle
    mQuery(document).on('mautic:onPageLoad:after', function(event, container) {
        ecApplyProtection(container);
    });

    // Also run on initial full page load
    mQuery(document).ready(function() {
        ecApplyProtection('#app-content');
    });
})();
JS
        );
    }

    /**
     * Inject the badge + set the JS config variable with protected fields data.
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

        $protectedFields = $config->getProtectedFields();
        $protectedFields[] = 'provider';
        $protectedFields   = array_unique($protectedFields);

        $fieldsJson   = json_encode($protectedFields);
        $providerName = htmlspecialchars($provider, ENT_QUOTES, 'UTF-8');

        // Set JS global variable with config (read by the global script from injectCustomAssets)
        // Also include inline CSS as fallback for immediate visual protection
        $cssRules = '';
        foreach ($protectedFields as $alias) {
            $cssRules .= "#lead_{$alias}, [name=\"lead[{$alias}]\"] { ";
            $cssRules .= "pointer-events:none!important; background-color:#f5f5f5!important; ";
            $cssRules .= "opacity:0.7!important; cursor:not-allowed!important; }\n";
        }

        $event->addContent(<<<HTML
<span class="label label-warning ml-sm" title="Fields managed by this provider are read-only">
    Managed by: {$providerName}
</span>
<style>{$cssRules}</style>
<script>window._ecProtectedFields = {fields: {$fieldsJson}, provider: "{$providerName}"};</script>
HTML);
    }
}
