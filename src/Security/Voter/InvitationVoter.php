<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Security\Voter;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * An invitation can only be edited or deleted as long as no link has been
 * generated. Afterwards changes go through the dedicated actions (extend, revoke,
 * re-send), which are logged in the audit log.
 */
class InvitationVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly InvitationRepository $invitations)
    {
    }

    protected function getTable(): string
    {
        return InvitationRepository::TABLE;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if ($action instanceof CreateAction || $action instanceof ReadAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        if ($id < 1) {
            return true;
        }

        $invitation = $this->invitations->find($id);

        if (null === $invitation) {
            return true;
        }

        return InvitationStatus::Open === $invitation->status
            && null === $invitation->tokenHash
            && null === $invitation->optInRequestedAt;
    }
}
