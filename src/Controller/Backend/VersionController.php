<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Controller\Backend;

use Contao\BackendUser;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\UnknownPlaceholderException;
use Pdir\PactumBundle\Exception\VersionLockedException;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Version\VersionDuplicator;
use Pdir\PactumBundle\Version\VersionPublisher;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('%contao.backend.route_prefix%/pactum/version/{id}', requirements: ['id' => '\d+'], defaults: ['_scope' => 'backend', '_store_referrer' => false])]
class VersionController extends AbstractPactumBackendController
{
    public function __construct(
        private readonly VersionRepository $versions,
        private readonly TemplateRepository $templates,
        private readonly VersionPublisher $publisher,
        private readonly VersionDuplicator $duplicator,
        private readonly AnnouncementRepository $announcements,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/publish', name: 'pactum_backend_version_publish', methods: ['GET', 'POST'])]
    public function publish(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_TEMPLATES);

        $version = $this->versions->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_TEMPLATES, 'table' => 'tl_pactum_version', 'id' => $version->templateId]);

        if (VersionStatus::Draft !== $version->status) {
            $this->addFlash('error', $this->trans('be.version.not_draft'));

            return $this->redirect($back);
        }

        $error = null;
        $preview = null;

        try {
            $preview = $this->publisher->preview($id);
        } catch (UnknownPlaceholderException $e) {
            $error = $this->trans('be.version.unknown_placeholders', ['%placeholders%' => implode(', ', $e->placeholders)]);
        } catch (IncompleteContractorException) {
            $error = $this->trans('be.contractor.incomplete');
        } catch (InvalidVersionException $e) {
            $error = $this->transException($e);
        }

        if (null === $error && $request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            $user = $this->getUser();

            try {
                $published = $this->publisher->publish($id, $user instanceof BackendUser ? (int) $user->id : 0);
                $this->addFlash('confirm', $this->trans('be.version.published', ['%number%' => $published->versionNumber, '%hash%' => (string) $published->contentHash]));

                return $this->redirect($back);
            } catch (IncompleteContractorException|InvalidVersionException|UnknownPlaceholderException|VersionLockedException $e) {
                $error = $e instanceof UnknownPlaceholderException
                    ? $this->trans('be.version.unknown_placeholders', ['%placeholders%' => implode(', ', $e->placeholders)])
                    : $this->transException($e);
            }
        }

        return $this->render('@Contao/backend/pactum/version_publish.html.twig', [
            'headline' => $this->trans('be.version.publish_headline', ['%number%' => $version->versionNumber]),
            'title' => $this->trans('be.version.publish_headline', ['%number%' => $version->versionNumber]),
            'pactum_version' => $version,
            'template' => $this->templates->find($version->templateId),
            'preview' => $preview,
            'publish_error' => $error,
            'announcement' => $this->announcements->findLatestForVersion($id),
            'now' => $this->clock->now()->getTimestamp(),
            'back_url' => $back,
        ]);
    }

    #[Route('/duplicate', name: 'pactum_backend_version_duplicate', methods: ['GET', 'POST'])]
    public function duplicate(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_TEMPLATES);

        $version = $this->versions->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_TEMPLATES, 'table' => 'tl_pactum_version', 'id' => $version->templateId]);

        if ($request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            $newId = $this->duplicator->createDraftFrom($id);
            $new = $this->versions->find($newId);
            $this->addFlash('confirm', $this->trans('be.version.duplicated', ['%number%' => $new->versionNumber ?? 0, '%source%' => $version->versionNumber]));

            return $this->redirect($back);
        }

        return $this->render('@Contao/backend/pactum/confirm.html.twig', [
            'headline' => $this->trans('be.version.duplicate_headline', ['%number%' => $version->versionNumber]),
            'title' => $this->trans('be.version.duplicate_headline', ['%number%' => $version->versionNumber]),
            'question' => $this->trans('be.version.duplicate_question', ['%number%' => $version->versionNumber]),
            'submit_label' => $this->trans('be.version.duplicate_submit'),
            'back_url' => $back,
        ]);
    }

    #[Route('/preview', name: 'pactum_backend_version_preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_TEMPLATES);

        $version = $this->versions->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_TEMPLATES, 'table' => 'tl_pactum_version', 'id' => $version->templateId]);

        $error = null;
        $preview = null;

        try {
            $preview = $this->publisher->preview($id);
        } catch (UnknownPlaceholderException $e) {
            $error = $this->trans('be.version.unknown_placeholders', ['%placeholders%' => implode(', ', $e->placeholders)]);
        } catch (IncompleteContractorException) {
            $error = $this->trans('be.contractor.incomplete');
        } catch (InvalidVersionException $e) {
            $error = $this->transException($e);
        }

        return $this->render('@Contao/backend/pactum/version_preview.html.twig', [
            'headline' => $this->trans('be.version.preview_headline', ['%number%' => $version->versionNumber]),
            'title' => $this->trans('be.version.preview_headline', ['%number%' => $version->versionNumber]),
            'pactum_version' => $version,
            'preview' => $preview,
            'publish_error' => $error,
            'back_url' => $back,
        ]);
    }
}
