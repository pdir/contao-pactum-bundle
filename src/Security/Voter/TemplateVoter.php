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
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A template with published/archived versions must not be deleted, as this would
 * also delete the versions agreements refer to.
 */
class TemplateVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly VersionRepository $versions)
    {
    }

    protected function getTable(): string
    {
        return TemplateRepository::TABLE;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        return $id < 1 || 0 === $this->versions->countLocked($id);
    }
}
