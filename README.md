# Pactum – AVV/DPA for Contao

[Deutsch](README.de.md)

Pactum lets you conclude **data processing agreements** (DPA, German "AVV",
Art. 28 GDPR) with your customers online, directly in Contao:

1. Create a contract template and publish a version (frozen with a SHA-256 hash).
2. Create an invitation and send the personal link to the customer.
3. The customer enters the company data, confirms the e-mail address (double
   opt-in), reads the contract and concludes it.
4. Both parties receive the agreement as PDF. The evidence (hashes, audit log,
   time stamps, consent texts) is stored in the back end and can be verified at
   any time.

> **Legal notice:** Pactum provides the technical process only. It does **not**
> provide legally reviewed contract texts and does not constitute legal advice.
> Whether a DPA is required and whether the electronic form is sufficient in your
> case must be assessed by you or your legal advisor. The included sample text is
> clearly marked as a sample and must be reviewed and adapted before use.

**Status:** 0.1.0 (unreleased) – MVP, not yet used in production.

## Requirements

- PHP 8.3+ (Contao 6 requires PHP 8.4+)
- Contao 5.7 or 6.0
- MySQL 8 / MariaDB 10.6+ (InnoDB)
- A working mailer (`MAILER_DSN`)

## Installation

```bash
composer require pdir/contao-pactum-bundle
vendor/bin/contao-console contao:migrate
```

or install it via the Contao Manager and run the database update afterwards.

After installing or updating via Composer, clear the cache (the Contao Manager
does this automatically):

```bash
vendor/bin/contao-console cache:clear
```

Otherwise Contao may use an outdated template list, e.g. the error "Tried to
list template options for the modern fragment type
"content_element/pactum_agreement" but could not find any template" when
editing the content element.

### Setup

1. **Settings (contractor):** create a contractor profile (your company). Name,
   address, representative, DPO, contact and sender e-mail are required before an
   invitation link can be generated. You can create several profiles.
2. **Target page:** create a page (e.g. `/avv`) with the content element
   **"Pactum: conclude agreement"**. Recommended: exclude it from the navigation
   and the sitemap. Pactum sends `X-Robots-Tag: noindex` and `no-store` for this
   page by itself.
3. **Contract template:** create a template, assign contractor and target page,
   add a version (language, text, annexes) and publish it.
   Optional: `vendor/bin/contao-console pactum:sample:import <template-id>`
   imports the sample text (DE/EN) as a **draft**.
4. **Invitation:** create an invitation (company, e-mail, language), then use
   "Generate link" to send it by e-mail or copy the link.
5. **Cron:** make sure the Contao cron runs (`contao:cron` or the web cron). It
   sends queued mails and runs the daily clean-up.

### Permissions

Access is granted per back end module in the user/user group settings:
*Contract templates*, *Annexes*, *Invitations*, *Agreements*, *Settings*, *Sub-processor announcements*. For
example, sales staff can get *Invitations* only, while only administrators can
publish templates.

## Configuration

All options are optional (`config/config.yaml`):

```yaml
pactum:
    invitation_ttl_days: 30          # default lifetime of an invitation (per template overridable)
    optin_ttl_hours: 24              # lifetime of the opt-in link
    ip_mode: anonymized              # none | anonymized | full
    store_user_agent: false          # store the browser user agent on conclusion
    ip_retention_days: 90            # remove IP / user agent from agreements after N days
    cleanup_after_days: 30           # anonymize expired/revoked invitations after N days
    agreement_retention_days: ~      # delete agreements after N days (default: never)
    storage_dir: '%kernel.project_dir%/var/pactum'   # PDF storage, never public
    download_ttl_minutes: 15         # lifetime of signed frontend download links
    subprocessor_notice_days: 14     # objection period for sub-processor changes
```

Rate limits (`pactum_token`, `pactum_submit`, `pactum_optin_mail`) are prepended
to `framework.rate_limiter` and can be overridden there.

**Deployment:** `storage_dir` contains the signed PDFs (evidence). With
release-based deployments (e.g. Deployer) add `var/pactum` to the shared
directories and include it in your backups.

## How it works

### Process and states

`open` → (customer data + opt-in) → `opted_in` → (consent) → `completed`.
Invitations can also become `expired` or `revoked`. Every transition is checked
by a state machine and executed as a conditional database update, so concurrent
requests cannot complete an invitation twice (additionally secured by a unique
index on the agreement table).

### Security

- The link token has 256 bits of entropy; only its SHA-256 hash is stored.
  "Send again" creates a new token, the previous link becomes invalid.
- After the link has been opened, the token is moved to the session and the
  browser is redirected to the URL without the token.
- The opt-in confirmation requires both the link token and the opt-in identifier.
- Forms are CSRF protected (Contao request token); the public endpoints are rate
  limited.
- PDFs are stored outside the web root. Downloads require a short-lived signed URL
  **and** the session that went through the process.
- Contract HTML is sanitized (allow list) on publish; customer input is escaped
  and insert tag braces are neutralized.
- No personal data is written to logs, URLs or exception messages.

### Evidence

On publish the rendered version text is frozen and hashed. On conclusion Pactum
stores the final document HTML, its hash, the PDF and its hash, the consent texts
as displayed, the time stamps of opt-in and consent and (depending on the
configuration) the anonymized IP address. All steps are written to an
append-only audit log.

Verify an agreement:

```bash
vendor/bin/contao-console pactum:verify <agreement-id>
```

The same check is shown in the back end detail view of an agreement.

### Mails

Mails are sent via Symfony Mailer with the contractor's sender address. In
Contao 5.7/6.0 mails are dispatched **asynchronously** via the Messenger
(`contao_prio_high`); the status "sent" therefore means "handed over to the
mailer". The confirmation to the contractor is sent to the BCC address of the
profile (fallback: contact address).

Override the mail templates in your project, e.g.
`templates/pactum/mail/invitation.html.twig` and
`templates/pactum/mail/text/invitation.txt.twig`.

### Privacy

- The user agent is not stored by default; the IP address is anonymized by
  default and removed after `ip_retention_days`.
- Invitations that were not completed are anonymized after `cleanup_after_days`.
- Agreements are kept until `agreement_retention_days` (default: forever) –
  define a retention period that matches your legal obligations.
- Clean-up runs daily via the Contao cron or manually:
  `vendor/bin/contao-console pactum:cleanup`.

### Import sub-processors from YAML

The list of a sub-processor annex can be taken from a YAML file, e.g. if the
list is maintained outside Contao:

```bash
vendor/bin/contao-console pactum:subprocessor:import <annex-id> subprocessors.yaml --dry-run
vendor/bin/contao-console pactum:subprocessor:import <annex-id> subprocessors.yaml
```

A sample list with fictitious providers is available in
`samples/subprocessors.en.yaml` (German keys: `samples/subprocessors.de.yaml`).

```yaml
subprocessors:             # or: subunternehmer, or a top-level list
  - name: "Hosting Ltd."
    address:               # or: anschrift (string or list of lines)
      - "1 Server Road"
      - "Dublin"
    service: "Web hosting" # or: leistung
    country: "Ireland"     # or: land
    transfer_basis: ""     # or: hinweis, required for third countries
```

- "EU/EEA" or "third country" is derived from the country (can be overridden
  with `location: eu | third_country`). The country is added as last address line.
- The current list of the annex is replaced completely (in one transaction).
  `--dry-run` only shows the changes.
- Published versions are not changed. Publish a new version so that new
  agreements contain the list. New sub-processors usually have to be announced to
  existing customers in advance (see below).

### Announce a change of sub-processors

Art. 28(2) GDPR requires that customers are informed about new or replaced
sub-processors in advance and can object.

1. Create a new draft version of the template and assign the changed
   sub-processor annex (e.g. after `pactum:subprocessor:import`).
2. In the version list choose **Announce change** on the draft. Pactum compares
   the sub-processors of the draft with the active version of the same language
   and shows new, changed and removed entries and the number of recipients.
3. After confirming, every customer of the template (one recipient per e-mail
   address, the newest agreement each) receives a mail with the list of changes,
   the end of the objection period (`subprocessor_notice_days`, default 14 days)
   and a personal link.
4. The link opens the target page of the template with an objection form:
   selection of the affected new/changed sub-processors, name and reason
   (required). Each recipient can object once and only until the end of the
   period. The contractor is notified via the BCC or contact address.
5. Status, mail delivery and objections are listed in the module
   **Sub-processor announcements**; every step is written to the audit log. When
   publishing the draft, Pactum shows the end of the period and the number of
   objections but does not block. How to handle objections is up to you.

Like invitation tokens, only the SHA-256 hash of the token is stored. Recipients
are deleted together with their agreement (`agreement_retention_days`).

## Extending

- **Events:** `InvitationCreatedEvent`, `OptInConfirmedEvent`,
  `AgreementCompletedEvent`, `VersionPublishedEvent` (namespace
  `Pdir\PactumBundle\Event`).
- **Placeholders:** implement
  `Pdir\PactumBundle\Rendering\PlaceholderProviderInterface` (autoconfigured).
- **Notifications:** replace the alias of
  `Pdir\PactumBundle\Notification\NotificationDispatcherInterface`, e.g. to send
  mails via Notification Center.
- **Templates:** all front end, mail and PDF templates are Twig templates in the
  `@Contao` namespace (`pactum/…`) and can be overridden.

## Deviations from the original concept

- Back end permissions use the regular module permissions instead of a separate
  permission field.
- Back end modules and DCAs are registered via `$GLOBALS['BE_MOD']` /
  `$GLOBALS['TL_DCA']`, because Contao 5.7/6.0 offers no alternative for
  DC_Table modules.
- The Contao core opt-in service is used for tokens, but the opt-in mail is sent
  by Pactum (HTML + text, contractor as sender) instead of the core's `send()`.

## Roadmap

**1.1 (prepared via events/interfaces, not implemented yet)**

- Re-consent: detect customers on an older version and re-invite them in bulk;
  diff view old/new in the front end.
- Automatic reminders for open invitations (cron).
- Optional Notification Center integration (`terminal42/notification_center`).
- Several agreement types per customer and customer master data as own entity.

**Later**

- Customer portal with an overview of all own agreements (member login).
- Webhooks on conclusion (signed requests, retry).
- REST/JSON API to create invitations from CRM or invoicing software.
- Optional qualified electronic signature via external providers (separate
  add-on bundle).
- Export (CSV/XLSX) of agreements for audits.

## Screenshots

_Screenshots will be added before the first release (back end: templates,
invitations, agreement detail; front end: data, opt-in, contract, done)._

## Development

```bash
composer install
composer cs          # ECS
composer phpstan     # PHPStan level 8
composer test        # unit tests
PACTUM_TEST_DATABASE_URL=mysql://user:pass@127.0.0.1:3306/pactum_test composer test-functional
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

LGPL-3.0-or-later, see [LICENSE](LICENSE). © pdir GmbH
