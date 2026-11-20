# Security Policy

Pactum processes personal data and stores legal evidence. We take security issues seriously.

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | ✅ (pre-release) |

## Reporting a vulnerability

**Please do not report security issues in public GitHub issues, discussions or pull requests.**

Report vulnerabilities privately via one of these channels:

- GitHub: **Security → Advisories → "Report a vulnerability"** in this repository
  (<https://github.com/pdir/contao-pactum-bundle/security/advisories/new>),
- e-mail: **support@pdir.dev** (please use "Security: Pactum" in the subject).

Please include:

- affected version(s) and Contao/PHP version,
- a description of the issue and its impact,
- steps to reproduce (a proof of concept without real personal data).

We will acknowledge your report within 5 working days, keep you informed about the
progress and credit you in the advisory if you wish. Please give us reasonable time
to fix the issue before disclosing it.

## Scope

In scope are, among others: the invitation and opt-in tokens, access to agreements
and PDFs, the integrity of the evidence (hashes, audit log), back end permissions,
injection (HTML, insert tags, SQL) and leaks of personal data.

Out of scope: the legal content of contract texts (Pactum ships no legally reviewed texts).
