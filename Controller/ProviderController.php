<?php

namespace MauticPlugin\ExternalContactsBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfig;
use MauticPlugin\ExternalContactsBundle\Entity\ProviderConfigRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProviderController extends FormController
{
    private const AUTO_PROTECTED_FIELDS = [
        'lead'    => ['provider'],
        'company' => ['companyprovider'],
    ];

    public function indexAction(
        EntityManagerInterface $em,
    ): Response {
        /** @var ProviderConfigRepository $repo */
        $repo      = $em->getRepository(ProviderConfig::class);
        $providers = $repo->findBy([], ['providerName' => 'ASC']);

        return $this->delegateView([
            'viewParameters' => [
                'providers' => $providers,
            ],
            'contentTemplate' => '@ExternalContacts/Provider/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_external_contacts_providers',
                'mauticContent' => 'externalContactsProviders',
                'route'         => $this->generateUrl('mautic_external_contacts_providers'),
            ],
        ]);
    }

    public function editAction(
        Request $request,
        EntityManagerInterface $em,
        FieldModel $fieldModel,
        int $objectId = 0,
    ): Response {
        /** @var ProviderConfigRepository $repo */
        $repo   = $em->getRepository(ProviderConfig::class);
        $isNew  = (0 === $objectId);
        $entity = $isNew ? new ProviderConfig() : $repo->find($objectId);
        $formRoute = $isNew
            ? $this->generateUrl('mautic_external_contacts_provider_new')
            : $this->generateUrl('mautic_external_contacts_provider_edit', ['objectId' => $objectId]);

        if (!$entity) {
            return $this->notFound();
        }

        $leadFieldChoices    = $this->buildFieldChoices($fieldModel, 'lead');
        $companyFieldChoices = $this->buildFieldChoices($fieldModel, 'company');

        if ('POST' === $request->getMethod()) {
            $data = $request->request->all();
            $existingProviderName = $entity->getProviderName();

            $providerName           = trim($data['provider_name'] ?? '');
            $protectedFields        = $this->normalizePostedFieldAliases($data['protected_fields'] ?? []);
            $protectedCompanyFields = $this->normalizePostedFieldAliases($data['protected_company_fields'] ?? []);
            $isActive               = (bool) ($data['is_active'] ?? true);

            $entity->setProviderName($providerName);
            $entity->setProtectedFields($protectedFields);
            $entity->setProtectedCompanyFields($protectedCompanyFields);
            $entity->setIsActive($isActive);

            if (empty($providerName)) {
                $this->addFlashMessage('Provider name is required.', [], 'error');

                return $this->delegateView([
                    'viewParameters' => [
                        'entity'              => $entity,
                        'leadFieldChoices'    => $leadFieldChoices,
                        'companyFieldChoices' => $companyFieldChoices,
                        'isNew'               => $isNew,
                    ],
                    'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
                    'passthroughVars' => [
                        'mauticContent' => 'externalContactsProvider',
                        'route'         => $formRoute,
                    ],
                ]);
            }

            // Check for duplicate provider name
            if ($isNew || $existingProviderName !== $providerName) {
                $existing = $repo->findOneBy(['providerName' => $providerName]);
                if ($existing && $existing->getId() !== $entity->getId()) {
                    $this->addFlashMessage('A provider with this name already exists.', [], 'error');

                    return $this->delegateView([
                        'viewParameters' => [
                            'entity'              => $entity,
                            'leadFieldChoices'    => $leadFieldChoices,
                            'companyFieldChoices' => $companyFieldChoices,
                            'isNew'               => $isNew,
                        ],
                        'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
                        'passthroughVars' => [
                            'mauticContent' => 'externalContactsProvider',
                            'route'         => $formRoute,
                        ],
                    ]);
                }
            }

            if ($isNew) {
                $entity->setDateAdded(new \DateTime());
            }
            $entity->setDateModified(new \DateTime());

            $em->persist($entity);
            $em->flush();

            $this->addFlashMessage('mautic.core.notice.updated', [
                '%name%' => $providerName,
            ]);

            return $this->redirectToRoute('mautic_external_contacts_providers');
        }

        return $this->delegateView([
            'viewParameters' => [
                'entity'              => $entity,
                'leadFieldChoices'    => $leadFieldChoices,
                'companyFieldChoices' => $companyFieldChoices,
                'isNew'               => $isNew,
            ],
            'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
            'passthroughVars' => [
                'mauticContent' => 'externalContactsProvider',
                'route'         => $formRoute,
            ],
        ]);
    }

    public function deleteAction(
        Request $request,
        EntityManagerInterface $em,
        int $objectId,
    ): Response {
        /** @var ProviderConfigRepository $repo */
        $repo   = $em->getRepository(ProviderConfig::class);
        $entity = $repo->find($objectId);

        if ($entity) {
            $em->remove($entity);
            $em->flush();

            $this->addFlashMessage('mautic.core.notice.deleted', [
                '%name%' => $entity->getProviderName(),
            ]);
        }

        return $this->redirectToRoute('mautic_external_contacts_providers');
    }

    /**
     * @return array<string, string>
     */
    private function buildFieldChoices(FieldModel $fieldModel, string $object): array
    {
        $fieldChoices = [];
        $fields       = $fieldModel->getFieldList(false, true, ['isPublished' => true, 'object' => $object]);
        $excluded     = self::AUTO_PROTECTED_FIELDS[$object] ?? [];

        foreach ($fields as $alias => $label) {
            if (in_array($alias, $excluded, true)) {
                continue;
            }

            $fieldChoices[$alias] = $label.' ('.$alias.')';
        }

        return $fieldChoices;
    }

    /**
     * @return string[]
     */
    private function normalizePostedFieldAliases(mixed $fieldAliases): array
    {
        if (!is_array($fieldAliases)) {
            return [];
        }

        $normalized = [];
        foreach ($fieldAliases as $fieldAlias) {
            if (!is_string($fieldAlias)) {
                continue;
            }

            $fieldAlias = trim($fieldAlias);
            if ('' === $fieldAlias) {
                continue;
            }

            $normalized[] = $fieldAlias;
        }

        return array_values(array_unique($normalized));
    }
}
