<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Domain;

use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\TestCase;

class ContractorTest extends TestCase
{
    public function testCompleteProfile(): void
    {
        $this->assertTrue(Factory::contractor()->isComplete());
        $this->assertSame('avv@example.org', Factory::contractor()->getNotificationRecipient());
    }

    public function testMissingRequiredFieldsAreReported(): void
    {
        $contractor = new Contractor(1, ' ', '', '1', 'X', '', 'Y', '', '', 'a@example.org', '', '', '', null);

        $this->assertFalse($contractor->isComplete());
        $this->assertSame(['name', 'street', 'senderEmail'], $contractor->getMissingRequiredFields());
        $this->assertSame('a@example.org', $contractor->getNotificationRecipient());
    }
}
