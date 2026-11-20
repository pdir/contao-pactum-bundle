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
use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A contractor profile in use by a template must not be deleted.
 */
class ContractorVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    protected function getTable(): string
    {
        return ContractorRepository::TABLE;
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof DeleteAction) {
            return true;
        }

        $id = (int) $action->getCurrentId();

        return $id < 1 || 0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.TemplateRepository::TABLE.' WHERE contractor = ?', [$id]);
    }
}
