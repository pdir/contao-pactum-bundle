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
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Repository\VersionRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Published and archived versions are read-only in the back end. The status is
 * read from the database, not from the (possibly stale) request data.
 */
class VersionVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly VersionRepository $versions)
    {
    }

    protected function getTable(): string
    {
        return VersionRepository::TABLE;
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

        $version = $this->versions->find($id);

        return null === $version || VersionStatus::Draft === $version->status;
    }
}
