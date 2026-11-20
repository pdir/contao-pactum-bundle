<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Subprocessor;

use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;
use Pdir\PactumBundle\Subprocessor\SubprocessorDiff;
use PHPUnit\Framework\TestCase;

class SubprocessorDiffTest extends TestCase
{
    public function testDetectsAddedChangedAndRemovedEntries(): void
    {
        $old = [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH'), $this->sub('Gamma GmbH')];
        $new = [$this->sub('Alpha GmbH'), $this->sub('beta gmbh', 'Mailhosting'), $this->sub('Delta GmbH')];

        $changes = (new SubprocessorDiff())->compare($old, $new);

        $this->assertSame(['Delta GmbH'], SubprocessorChanges::names($changes->added));
        $this->assertSame(['beta gmbh'], SubprocessorChanges::names($changes->changed));
        $this->assertSame(['Gamma GmbH'], SubprocessorChanges::names($changes->removed));
        $this->assertSame(1, $changes->unchanged);
        $this->assertTrue($changes->hasContentChanges());
        $this->assertSame(['Delta GmbH', 'beta gmbh'], SubprocessorChanges::names($changes->getObjectable()));
    }

    public function testIdenticalListsHaveNoChanges(): void
    {
        $list = [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH')];

        $changes = (new SubprocessorDiff())->compare($list, $list);

        $this->assertFalse($changes->hasContentChanges());
        $this->assertFalse($changes->reordered);
        $this->assertSame(2, $changes->unchanged);
    }

    public function testReorderingIsNoContentChange(): void
    {
        $changes = (new SubprocessorDiff())->compare(
            [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH')],
            [$this->sub('Beta GmbH'), $this->sub('Alpha GmbH')],
        );

        $this->assertFalse($changes->hasContentChanges());
        $this->assertTrue($changes->reordered);
    }

    public function testChangesSurviveSerialization(): void
    {
        $changes = (new SubprocessorDiff())->compare(
            [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH')],
            [$this->sub('Gamma GmbH'), $this->sub('Alpha GmbH', 'Backup')],
        );

        $restored = SubprocessorChanges::fromArray(json_decode((string) json_encode($changes->toArray()), true));

        $this->assertSame($changes->toArray(), $restored->toArray());
    }

    private function sub(string $name, string $service = 'Hosting'): Subprocessor
    {
        return new Subprocessor($name, 'Musterweg 1, 12345 Musterstadt', $service, Subprocessor::LOCATION_EU, '');
    }
}
