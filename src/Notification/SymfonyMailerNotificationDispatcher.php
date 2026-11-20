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
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Sends notifications with Twig templates (HTML + text):
 * "@Contao/pactum/mail/<type>.html.twig" and
 * "@Contao/pactum/mail/text/<type>.txt.twig". Contao identifies templates without
 * their file extension, so the text variants live in a separate directory.
 */
#[AsAlias(NotificationDispatcherInterface::class)]
class SymfonyMailerNotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function dispatch(Notification $notification): void
    {
        $email = $this->createEmail($notification);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Never include the recipient address in the message
            throw new NotificationFailedException(\sprintf('Could not send the %s notification.', $notification->type->value), 0, $e);
        }
    }

    public function createEmail(Notification $notification): Email
    {
        $contractor = $notification->contractor;

        $variables = [
            ...$notification->context,
            'contractor' => $contractor,
            'locale' => $notification->locale,
        ];

        $subject = $this->translator->trans(
            'mail.'.$notification->type->value.'.subject',
            ['%contractor%' => $contractor->name, '%company%' => (string) ($notification->context['company'] ?? '')],
            'pactum',
            $notification->locale,
        );

        $type = $notification->type->value;

        $email = (new Email())
            ->from(new Address($contractor->senderEmail, $contractor->senderName ?: $contractor->name))
            ->to($notification->recipient)
            ->subject($subject)
            ->text($this->twig->render('@Contao/pactum/mail/text/'.$type.'.txt.twig', $variables))
            ->html($this->twig->render('@Contao/pactum/mail/'.$type.'.html.twig', $variables))
        ;

        if ('' !== $contractor->contactEmail) {
            $email->replyTo($contractor->contactEmail);
        }

        foreach ($notification->attachments as $attachment) {
            $email->attachFromPath($attachment['path'], $attachment['name'], $attachment['type']);
        }

        return $email;
    }
}
