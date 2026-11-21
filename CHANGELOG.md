# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `pactum:subprocessor:import` replaces the sub-processors of an annex with a
  YAML list (German or English keys, `--dry-run`, EU/third-country detection).
- Announcement of sub-processor changes (Art. 28(2) GDPR): "Announce change" on
  a draft version compares its sub-processors with the active version and mails
  all customers of the template a personal objection link. Objections (selection,
  name, reason) are recorded until the end of the period
  (`pactum.subprocessor_notice_days`, default 14) and forwarded to the
  contractor. New back end module "Sub-processor announcements", hint on the
  publish page, audit events and clean-up together with the agreement.

### Changed

- The import diff for sub-processors is shared with the announcement
  (`SubprocessorDiff`); behaviour of `pactum:subprocessor:import` is unchanged.
- Error messages in the back end are translated (German/English). Exceptions
  carry an optional translation key; the English message remains for logs and
  the console.

## [0.1.0] - unreleased

### Added

- Contractor profiles (several per installation) with logo, DPO, sender and BCC address.
- Contract templates with versions per language; publishing freezes the rendered
  text incl. annexes and stores its SHA-256 hash; previous versions are archived.
- Annexes (TOMs, sub-processors, custom) with snapshot on publish.
- Placeholders for contractor, version, customer and agreement data;
  extensible via `PlaceholderProviderInterface`.
- Invitations with expiry, token rotation on "send again", revoke and extend.
- Front end process as content element: customer data, double opt-in (Contao
  core opt-in tokens), contract with consent, confirmation and PDF download.
- PDF generation with dompdf, storage outside the web root, signed and
  session-bound downloads.
- Agreement evidence: version, document and PDF hashes, consent texts, time
  stamps, anonymized IP; append-only audit log; `pactum:verify` command and
  integrity check in the back end.
- Mails via Symfony Mailer (HTML + text) to customer and contractor.
- Daily clean-up (`pactum:cleanup`, cron): expiry, anonymization, IP removal,
  optional retention for agreements.
- Rate limiting of token lookups, form submissions and opt-in mails.
- Events: `InvitationCreatedEvent`, `OptInConfirmedEvent`,
  `AgreementCompletedEvent`, `VersionPublishedEvent`.
- Sample agreement text and TOM annex (DE/EN), imported as draft via
  `pactum:sample:import`. Not legally reviewed.
- Translations: German and English.

[Unreleased]: https://github.com/pdir/contao-pactum-bundle/compare/0.1.0...HEAD
[0.1.0]: https://github.com/pdir/contao-pactum-bundle/releases/tag/0.1.0
