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
use Pdir\PactumBundle\Repository\AgreementRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Agreements are evidence: they can never be created, changed or deleted through
 * the DataContainer (in addition to closed/notEditable/notDeletable).
 */
class EvidenceVoter extends AbstractDataContainerVoter
{
    protected function getTable(): string
    {
        return AgreementRepository::TABLE;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        return $action instanceof ReadAction;
    }
}
