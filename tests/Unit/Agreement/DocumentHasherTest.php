<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Agreement;

use Pdir\PactumBundle\Agreement\DocumentHasher;
use PHPUnit\Framework\TestCase;

class DocumentHasherTest extends TestCase
{
    public function testHashAndMatch(): void
    {
        $hasher = new DocumentHasher();
        $hash = $hasher->hash('<p>Vertrag</p>');

        $this->assertSame(hash('sha256', '<p>Vertrag</p>'), $hash);
        $this->assertTrue($hasher->matches($hash, '<p>Vertrag</p>'));
        $this->assertFalse($hasher->matches($hash, '<p>Vertrag </p>'));
        $this->assertFalse($hasher->matches('', ''), 'An empty expected hash never matches');
    }

    public function testHashFile(): void
    {
        $hasher = new DocumentHasher();
        $file = tempnam(sys_get_temp_dir(), 'pactum');
        file_put_contents($file, 'pdf');

        $this->assertSame(hash('sha256', 'pdf'), $hasher->hashFile($file));
        $this->assertNull($hasher->hashFile($file.'-missing'));

        unlink($file);
    }
}
