<?php

namespace MauticPlugin\ExternalContactsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
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
        ];
    }

    public function injectCustomContent(CustomContentEvent $event): void
    {
        // Inject on the contact edit/view page where customContent('lead.name.after', _context) is called
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

        // Build CSS rules to immediately style protected fields (works even if script doesn't run)
        $cssRules = '';
        foreach ($protectedFields as $alias) {
            $cssRules .= "#lead_{$alias}, [name=\"lead[{$alias}]\"] {\n";
            $cssRules .= "  pointer-events: none !important;\n";
            $cssRules .= "  background-color: #f5f5f5 !important;\n";
            $cssRules .= "  opacity: 0.7 !important;\n";
            $cssRules .= "  cursor: not-allowed !important;\n";
            $cssRules .= "}\n";
        }

        $event->addContent(<<<HTML
<span class="label label-warning ml-sm" title="Fields managed by this provider are read-only">
    Managed by: {$providerName}
</span>
<style>{$cssRules}</style>
<script>
(function() {
    var protectedFields = {$fieldsJson};
    var applied = false;

    function disableProtectedFields() {
        if (applied) return;
        var found = 0;
        protectedFields.forEach(function(alias) {
            var selectors = [
                '#lead_' + alias,
                '[name="lead[' + alias + ']"]'
            ];
            selectors.forEach(function(selector) {
                var els = document.querySelectorAll(selector);
                els.forEach(function(el) {
                    el.setAttribute('readonly', 'readonly');
                    el.setAttribute('disabled', 'disabled');
                    found++;

                    var formGroup = el.closest('.form-group');
                    if (formGroup && !formGroup.querySelector('.ec-protected-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'label label-default ec-protected-badge';
                        badge.style.cssText = 'margin-left:5px;font-size:10px;';
                        badge.textContent = 'Protected';
                        var label = formGroup.querySelector('label');
                        if (label) label.appendChild(badge);
                    }
                });
            });
        });
        if (found > 0) applied = true;

        // Intercept form submit to re-enable fields so values are sent
        var form = document.querySelector('form[name="lead"]');
        if (form && !form._ecPatched) {
            form._ecPatched = true;
            form.addEventListener('submit', function() {
                protectedFields.forEach(function(alias) {
                    var el = document.getElementById('lead_' + alias);
                    if (el) el.removeAttribute('disabled');
                });
            });
        }
    }

    // Try immediately
    disableProtectedFields();

    // Retry with delays for AJAX-loaded content
    setTimeout(disableProtectedFields, 100);
    setTimeout(disableProtectedFields, 500);
    setTimeout(disableProtectedFields, 1500);

    // Use Mautic's jQuery if available
    if (typeof mQuery !== 'undefined') {
        mQuery(document).ready(disableProtectedFields);
        mQuery(document).on('ajaxComplete', disableProtectedFields);
    }
})();
</script>
HTML);
    }
}
