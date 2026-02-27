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

        $event->addContent(<<<HTML
<span class="label label-warning ml-sm" title="Fields managed by this provider are read-only">
    Managed by: {$providerName}
</span>
<script>
(function() {
    var protectedFields = {$fieldsJson};

    function disableProtectedFields() {
        protectedFields.forEach(function(alias) {
            // Try standard form field selectors
            var selectors = [
                '#lead_' + alias,
                '[name="lead[' + alias + ']"]',
                '#lead_field_' + alias,
                'select[id*="' + alias + '"]'
            ];

            selectors.forEach(function(selector) {
                var els = document.querySelectorAll(selector);
                els.forEach(function(el) {
                    el.setAttribute('readonly', 'readonly');
                    el.setAttribute('disabled', 'disabled');
                    el.style.backgroundColor = '#f5f5f5';
                    el.style.opacity = '0.7';
                    el.style.cursor = 'not-allowed';

                    // Add a visual indicator to the parent form-group
                    var formGroup = el.closest('.form-group');
                    if (formGroup && !formGroup.querySelector('.ec-protected-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'label label-default ec-protected-badge';
                        badge.style.marginLeft = '5px';
                        badge.style.fontSize = '10px';
                        badge.textContent = 'Protected';
                        var label = formGroup.querySelector('label');
                        if (label) {
                            label.appendChild(badge);
                        }
                    }
                });
            });
        });
    }

    // Run on DOM ready and after any Mautic AJAX content loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', disableProtectedFields);
    } else {
        disableProtectedFields();
    }

    // Re-apply after Mautic reloads content
    if (typeof MauticVars !== 'undefined') {
        document.addEventListener('mautic:ajax:loaded', disableProtectedFields);
    }

    // Also handle form submission to re-enable fields so their values are submitted
    var form = document.querySelector('form[name="lead"]');
    if (form) {
        form.addEventListener('submit', function() {
            protectedFields.forEach(function(alias) {
                var el = document.getElementById('lead_' + alias);
                if (el) {
                    el.removeAttribute('disabled');
                }
            });
        });
    }
})();
</script>
HTML);
    }
}
