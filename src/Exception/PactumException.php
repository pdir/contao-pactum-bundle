<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Exception;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base exception. The message is English (logs, console); exceptions shown in the
 * back end additionally carry a translation key of the "pactum" domain.
 */
class PactumException extends \RuntimeException implements TranslatableInterface
{
    /**
     * @param array<string, string|int> $translationParameters
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        \Throwable|null $previous = null,
        private readonly string|null $translationKey = null,
        private readonly array $translationParameters = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function trans(TranslatorInterface $translator, string|null $locale = null): string
    {
        if (null === $this->translationKey) {
            return $this->getMessage();
        }

        return $translator->trans($this->translationKey, $this->translationParameters, 'pactum', $locale);
    }
}
