<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Security\Voter\AuditLogVoter;
use Pdir\PactumBundle\Security\Voter\ContractorVoter;
use Pdir\PactumBundle\Security\Voter\EvidenceVoter;
use Pdir\PactumBundle\Security\Voter\InvitationVoter;
use Pdir\PactumBundle\Security\Voter\TemplateVoter;
use Pdir\PactumBundle\Security\Voter\VersionVoter;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class VoterTest extends TestCase
{
    public function testPublishedVersionsAreReadOnly(): void
    {
        $versions = $this->createStub(VersionRepository::class);
        $versions
            ->method('find')
            ->willReturnCallback(static fn (int $id) => Factory::version(1 === $id ? VersionStatus::Draft : VersionStatus::Published, id: $id))
        ;

        $voter = new VersionVoter($versions);
        $table = VersionRepository::TABLE;

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new UpdateAction($table, ['id' => 1])));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new DeleteAction($table, ['id' => 1])));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new UpdateAction($table, ['id' => 2])));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new DeleteAction($table, ['id' => 2])));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new ReadAction($table, ['id' => 2])));
    }

    public function testInvitationsAreLockedOnceALinkExists(): void
    {
        $withLink = Factory::invitation(InvitationStatus::Open);
        $withoutLink = new Invitation(8, 10, 'de', null, 2_000_000_000, InvitationStatus::Open, '', '', 1, Factory::customer(), null, null, null);

        $invitations = $this->createStub(InvitationRepository::class);
        $invitations
            ->method('find')
            ->willReturnCallback(static fn (int $id) => 8 === $id ? $withoutLink : $withLink)
        ;

        $voter = new InvitationVoter($invitations);
        $table = InvitationRepository::TABLE;

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new UpdateAction($table, ['id' => 8])));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new UpdateAction($table, ['id' => 7])));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new DeleteAction($table, ['id' => 7])));
    }

    public function testEvidenceIsReadOnly(): void
    {
        foreach ([[new EvidenceVoter(), AgreementRepository::TABLE], [new AuditLogVoter(), AuditLogRepository::TABLE]] as [$voter, $table]) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new ReadAction($table, ['id' => 1])));
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new CreateAction($table)));
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new UpdateAction($table, ['id' => 1])));
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new DeleteAction($table, ['id' => 1])));
        }
    }

    public function testTemplatesWithPublishedVersionsCannotBeDeleted(): void
    {
        $versions = $this->createStub(VersionRepository::class);
        $versions
            ->method('countLocked')
            ->willReturnCallback(static fn (int $id): int => 1 === $id ? 0 : 2)
        ;

        $voter = new TemplateVoter($versions);
        $table = TemplateRepository::TABLE;

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new DeleteAction($table, ['id' => 1])));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new DeleteAction($table, ['id' => 2])));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new UpdateAction($table, ['id' => 2])));
    }

    public function testContractorsInUseCannotBeDeleted(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn(1)
        ;

        $voter = new ContractorVoter($connection);
        $table = ContractorRepository::TABLE;

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $table, new DeleteAction($table, ['id' => 1])));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $table, new UpdateAction($table, ['id' => 1])));
    }

    private function vote(AbstractDataContainerVoter $voter, string $table, CreateAction|DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $voter->vote($this->createStub(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$table]);
    }
}
