<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Notification;

use Pdir\PactumBundle\Exception\NotificationFailedException;

/**
 * Abstraction for all Pactum notifications. The default implementation uses
 * Symfony Mailer; an alternative (e.g. Notification Center, planned for 1.1) can
 * replace it by aliasing this interface.
 */
interface NotificationDispatcherInterface
{
    /**
     * @throws NotificationFailedException
     */
    public function dispatch(Notification $notification): void;
}
