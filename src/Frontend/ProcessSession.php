<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Frontend;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Binds the frontend process to the browser session after the invitation link was
 * opened. The authoritative state is the invitation status in the database; the
 * session only remembers which invitation is being processed.
 */
class ProcessSession
{
    private const KEY = '_pactum_process';

    private const NOTICE_KEY = '_pactum_notice';

    private const OBJECTION_KEY = '_pactum_objection';

    public function bind(SessionInterface $session, int $invitationId, #[\SensitiveParameter] string $plainToken): void
    {
        $session->set(self::KEY, ['invitation' => $invitationId, 'token' => $plainToken, 'editing' => false]);
    }

    public function getInvitationId(SessionInterface $session): int|null
    {
        $data = $session->get(self::KEY);

        return \is_array($data) && isset($data['invitation']) ? (int) $data['invitation'] : null;
    }

    public function getToken(SessionInterface $session): string
    {
        $data = $session->get(self::KEY);

        return \is_array($data) ? (string) ($data['token'] ?? '') : '';
    }

    public function isEditing(SessionInterface $session): bool
    {
        $data = $session->get(self::KEY);

        return \is_array($data) && true === ($data['editing'] ?? false);
    }

    public function setEditing(SessionInterface $session, bool $editing): void
    {
        $data = $session->get(self::KEY);

        if (\is_array($data)) {
            $data['editing'] = $editing;
            $session->set(self::KEY, $data);
        }
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::KEY);
    }

    /**
     * Remembers the announcement recipient after the personal objection link was
     * opened (the token itself is not kept).
     */
    public function bindObjection(SessionInterface $session, int $recipientId): void
    {
        $session->set(self::OBJECTION_KEY, $recipientId);
    }

    public function getObjectionRecipientId(SessionInterface $session): int|null
    {
        $id = $session->get(self::OBJECTION_KEY);

        return \is_int($id) && $id > 0 ? $id : null;
    }

    public function clearObjection(SessionInterface $session): void
    {
        $session->remove(self::OBJECTION_KEY);
    }

    public function setNotice(SessionInterface $session, string $notice): void
    {
        $session->set(self::NOTICE_KEY, $notice);
    }

    public function pullNotice(SessionInterface $session): string|null
    {
        $notice = $session->get(self::NOTICE_KEY);
        $session->remove(self::NOTICE_KEY);

        return \is_string($notice) ? $notice : null;
    }
}
