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
    var ecIsBound = false;

    function ecReadConfig(container) {
        var scope = mQuery(container || document);
        var configEl = scope.find('.ec-protected-config[data-ec-protected-fields]').first();
        if (!configEl.length) {
            configEl = mQuery('.ec-protected-config[data-ec-protected-fields]').first();
        }
        if (!configEl.length) {
            return null;
        }

        var fields = [];
        try {
            fields = JSON.parse(configEl.attr('data-ec-protected-fields') || '[]');
        } catch (e) {
            return null;
        }
        if (!Array.isArray(fields) || !fields.length) {
            return null;
        }

        var leadId = parseInt(configEl.attr('data-ec-lead-id') || '0', 10);
        if (isNaN(leadId)) {
            leadId = 0;
        }

        return {
            fields: fields,
            provider: configEl.attr('data-ec-provider') || '',
            leadId: leadId
        };
    }

    function ecMatchesLead(formEl, config) {
        if (!config || !config.leadId) {
            return true;
        }

        var leadIdInput = mQuery(formEl).find('#lead_id, input[name="lead[id]"]').first();
        if (!leadIdInput.length) {
            return true;
        }

        var currentLeadId = parseInt(leadIdInput.val() || '0', 10);
        if (isNaN(currentLeadId) || !currentLeadId) {
            return true;
        }

        return currentLeadId === config.leadId;
    }

    function ecApplyProtection(container) {
        var config = ecReadConfig(container);
        if (!config) return;

        var formEl = mQuery(container).find('form[name="lead"]');
        if (!formEl.length) formEl = mQuery('form[name="lead"]');
        if (!formEl.length) return;
        if (!ecMatchesLead(formEl, config)) return;

        config.fields.forEach(function(alias) {
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

        $fieldsJson       = json_encode(array_values($protectedFields));
        $fieldsJsonAttr   = htmlspecialchars($fieldsJson ?: '[]', ENT_QUOTES, 'UTF-8');
        $providerName     = htmlspecialchars($provider, ENT_QUOTES, 'UTF-8');
        $leadId           = (int) $lead->getId();

        // Inline CSS fallback for immediate visual protection while JS initializes.
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
<span class="ec-protected-config hide"
      data-ec-provider="{$providerName}"
      data-ec-lead-id="{$leadId}"
      data-ec-protected-fields="{$fieldsJsonAttr}"></span>
<style>{$cssRules}</style>
HTML);
    }
}
