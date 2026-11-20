<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Domain;

/**
 * Events written to the append-only audit log. The value is stored in the
 * database, so existing values must never be renamed.
 */
enum AuditEvent: string
{
    case InvitationCreated = 'invitation_created';
    case InvitationLinkGenerated = 'invitation_link_generated';
    case InvitationSent = 'invitation_sent';
    case InvitationOpened = 'invitation_opened';
    case InvitationExtended = 'invitation_extended';
    case InvitationRevoked = 'invitation_revoked';
    case InvitationExpired = 'invitation_expired';
    case InvitationAnonymized = 'invitation_anonymized';
    case CustomerDataSubmitted = 'customer_data_submitted';
    case CustomerDataReopened = 'customer_data_reopened';
    case OptInRequested = 'optin_requested';
    case OptInConfirmed = 'optin_confirmed';
    case AgreementCompleted = 'agreement_completed';
    case AgreementMailSent = 'agreement_mail_sent';
    case AgreementMailFailed = 'agreement_mail_failed';
    case AgreementPersonalDataPurged = 'agreement_personal_data_purged';
    case AgreementDeleted = 'agreement_deleted';
    case VersionPublished = 'version_published';
    case VersionArchived = 'version_archived';
    case MailFailed = 'mail_failed';
}
