<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Pdf;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Stores agreement PDFs outside the public web root. Files are only delivered
 * through controllers with permission checks.
 */
class PdfStorage
{
    private const PATH_PATTERN = '#^agreements/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#';

    private readonly Filesystem $filesystem;

    public function __construct(private readonly string $storageDir)
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * @return string the path relative to the storage directory
     */
    public function store(string $uuid, string $content, int $timestamp): string
    {
        $relative = \sprintf('agreements/%s/%s.pdf', date('Y/m', $timestamp), strtolower($uuid));

        if (1 !== preg_match(self::PATH_PATTERN, $relative)) {
            throw new \InvalidArgumentException('Invalid agreement UUID.');
        }

        $absolute = $this->getAbsolutePath($relative);

        if ($this->filesystem->exists($absolute)) {
            throw new \RuntimeException('The agreement PDF already exists and must not be overwritten.');
        }

        $this->filesystem->dumpFile($absolute, $content);
        $this->filesystem->chmod($absolute, 0640);

        return $relative;
    }

    public function getAbsolutePath(string $relative): string
    {
        if (1 !== preg_match(self::PATH_PATTERN, $relative)) {
            throw new \InvalidArgumentException('Invalid agreement PDF path.');
        }

        return Path::join($this->storageDir, $relative);
    }

    public function exists(string $relative): bool
    {
        return '' !== $relative && $this->filesystem->exists($this->getAbsolutePath($relative));
    }

    public function delete(string $relative): void
    {
        if ('' !== $relative) {
            $this->filesystem->remove($this->getAbsolutePath($relative));
        }
    }
}
