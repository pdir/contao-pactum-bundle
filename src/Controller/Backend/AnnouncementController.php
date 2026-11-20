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
use Pdir\PactumBundle\Announcement\AnnouncementService;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Announces the sub-processor changes of a draft version to all customers of the
 * template (clause "change of sub-processors", Art. 28(2) GDPR).
 */
#[Route('%contao.backend.route_prefix%/pactum/version/{id}', requirements: ['id' => '\d+'], defaults: ['_scope' => 'backend', '_store_referrer' => false])]
class AnnouncementController extends AbstractPactumBackendController
{
    public function __construct(
        private readonly VersionRepository $versions,
        private readonly AnnouncementService $announcements,
        private readonly AnnouncementRepository $announcementRepository,
    ) {
    }

    #[Route('/announce', name: 'pactum_backend_version_announce', methods: ['GET', 'POST'])]
    public function announce(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_ANNOUNCEMENTS);

        $version = $this->versions->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_TEMPLATES, 'table' => 'tl_pactum_version', 'id' => $version->templateId]);

        $existing = $this->announcementRepository->findLatestForVersion($id);

        if (null !== $existing) {
            $this->addFlash('info', $this->trans('be.announcement.already_announced', ['%date%' => date('d.m.Y', $existing->effectiveAt)]));

            return $this->redirect($this->generateUrl('contao_backend', ['do' => self::MODULE_ANNOUNCEMENTS, 'table' => 'tl_pactum_announcement_recipient', 'id' => $existing->id]));
        }

        $error = null;
        $preview = null;

        try {
            $preview = $this->announcements->preview($id);

            if ([] === $preview->recipients) {
                $error = $this->trans('be.announcement.no_recipients');
            }
        } catch (IncompleteContractorException) {
            $error = $this->trans('be.contractor.incomplete');
        } catch (PactumException $e) {
            $error = $this->transException($e);
        }

        if (null === $error && $request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            $user = $this->getUser();

            try {
                $result = $this->announcements->announce($id, $user instanceof BackendUser ? (int) $user->id : 0);

                $this->addFlash('confirm', $this->trans('be.announcement.sent', ['%sent%' => $result->sent, '%date%' => date('d.m.Y', $result->effectiveAt)]));

                if ($result->failed > 0) {
                    $this->addFlash('error', $this->trans('be.announcement.failed', ['%failed%' => $result->failed]));
                }

                return $this->redirect($this->generateUrl('contao_backend', ['do' => self::MODULE_ANNOUNCEMENTS, 'table' => 'tl_pactum_announcement_recipient', 'id' => $result->announcementId]));
            } catch (PactumException $e) {
                $error = $this->transException($e);
            }
        }

        return $this->render('@Contao/backend/pactum/version_announce.html.twig', [
            'headline' => $this->trans('be.announcement.headline', ['%number%' => $version->versionNumber]),
            'title' => $this->trans('be.announcement.headline', ['%number%' => $version->versionNumber]),
            'announcement_preview' => $preview,
            'announce_error' => $error,
            'back_url' => $back,
        ]);
    }
}
