<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Pdf;

use Pdir\PactumBundle\Pdf\PdfStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class PdfStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pactum-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testStoresWithUnguessableNameAndRestrictivePermissions(): void
    {
        $storage = new PdfStorage($this->dir);
        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $path = $storage->store($uuid, '%PDF-1.7', (new \DateTimeImmutable('2026-11-16 12:00:00'))->getTimestamp());

        $this->assertSame('agreements/2026/11/'.$uuid.'.pdf', $path);
        $this->assertTrue($storage->exists($path));
        $this->assertSame('%PDF-1.7', file_get_contents($storage->getAbsolutePath($path)));
        $this->assertSame('0640', substr(\sprintf('%o', fileperms($storage->getAbsolutePath($path))), -4));
    }

    public function testExistingFilesAreNeverOverwritten(): void
    {
        $storage = new PdfStorage($this->dir);
        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
        $storage->store($uuid, 'a', 0);

        $this->expectException(\RuntimeException::class);

        $storage->store($uuid, 'b', 0);
    }

    public function testPathTraversalIsRejected(): void
    {
        $storage = new PdfStorage($this->dir);

        $this->expectException(\InvalidArgumentException::class);

        $storage->getAbsolutePath('agreements/../../etc/passwd');
    }

    public function testInvalidUuidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfStorage($this->dir))->store('../evil', 'x', 0);
    }
}
