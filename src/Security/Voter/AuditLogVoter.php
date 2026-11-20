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
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The audit log is append-only and never writable through the DataContainer.
 */
class AuditLogVoter extends AbstractDataContainerVoter
{
    protected function getTable(): string
    {
        return AuditLogRepository::TABLE;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        return $action instanceof ReadAction;
    }
}
