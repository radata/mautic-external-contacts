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

        if (!$entity) {
            return $this->notFound();
        }

        // Get all lead field aliases for the multi-select
        $leadFields = $fieldModel->getFieldList(false, true, ['isPublished' => true, 'object' => 'lead']);
        $fieldChoices = [];
        foreach ($leadFields as $group => $fields) {
            foreach ($fields as $alias => $label) {
                $fieldChoices[$alias] = $label . ' (' . $alias . ')';
            }
        }

        if ('POST' === $request->getMethod()) {
            $data = $request->request->all();

            $providerName    = trim($data['provider_name'] ?? '');
            $protectedFields = $data['protected_fields'] ?? [];
            $isActive        = (bool) ($data['is_active'] ?? true);

            if (empty($providerName)) {
                $this->addFlashMessage('Provider name is required.', [], 'error');

                return $this->delegateView([
                    'viewParameters' => [
                        'entity'       => $entity,
                        'fieldChoices' => $fieldChoices,
                        'isNew'        => $isNew,
                    ],
                    'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
                    'passthroughVars' => [
                        'mauticContent' => 'externalContactsProvider',
                        'route'         => $this->generateUrl('mautic_external_contacts_provider_edit', ['objectId' => $objectId]),
                    ],
                ]);
            }

            // Check for duplicate provider name
            if ($isNew || $entity->getProviderName() !== $providerName) {
                $existing = $repo->findOneBy(['providerName' => $providerName]);
                if ($existing && $existing->getId() !== $entity->getId()) {
                    $this->addFlashMessage('A provider with this name already exists.', [], 'error');

                    return $this->delegateView([
                        'viewParameters' => [
                            'entity'       => $entity,
                            'fieldChoices' => $fieldChoices,
                            'isNew'        => $isNew,
                        ],
                        'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
                        'passthroughVars' => [
                            'mauticContent' => 'externalContactsProvider',
                            'route'         => $this->generateUrl('mautic_external_contacts_provider_edit', ['objectId' => $objectId]),
                        ],
                    ]);
                }
            }

            $entity->setProviderName($providerName);
            $entity->setProtectedFields(array_values($protectedFields));
            $entity->setIsActive($isActive);

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
                'entity'       => $entity,
                'fieldChoices' => $fieldChoices,
                'isNew'        => $isNew,
            ],
            'contentTemplate' => '@ExternalContacts/Provider/form.html.twig',
            'passthroughVars' => [
                'mauticContent' => 'externalContactsProvider',
                'route'         => $this->generateUrl('mautic_external_contacts_provider_edit', ['objectId' => $objectId]),
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
}
