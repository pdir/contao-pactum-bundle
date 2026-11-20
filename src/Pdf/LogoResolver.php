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

use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Pdir\PactumBundle\Domain\Contractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Embeds the contractor logo as data URI, so the PDF renderer never has to load
 * files from disk or the network.
 */
class LogoResolver
{
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];

    public function __construct(
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $filesStorage,
    ) {
    }

    public function getDataUri(Contractor $contractor): string|null
    {
        if (null === $contractor->logo) {
            return null;
        }

        try {
            $uuid = Uuid::fromBinary($contractor->logo);
            $item = $this->filesStorage->get($uuid);

            if (null === $item || !$item->isFile()) {
                return null;
            }

            $mimeType = $item->getMimeType('application/octet-stream');

            if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                return null;
            }

            return 'data:'.$mimeType.';base64,'.base64_encode($this->filesStorage->read($uuid));
        } catch (\InvalidArgumentException|VirtualFilesystemException) {
            return null;
        }
    }
}
