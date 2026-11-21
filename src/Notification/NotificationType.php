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

enum NotificationType: string
{
    case Invitation = 'invitation';
    case OptIn = 'optin';
    case AgreementCustomer = 'agreement_customer';
    case AgreementContractor = 'agreement_contractor';
    case SubprocessorAnnouncement = 'subprocessor_announcement';
    case SubprocessorObjection = 'subprocessor_objection';
}
