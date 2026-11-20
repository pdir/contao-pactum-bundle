# Contributing

Thank you for helping to improve Pactum! Contributions in English or German are welcome.

## Before you start

- **Security issues** must not be reported in public issues – see [SECURITY.md](SECURITY.md).
- For larger changes, please open an issue first so we can discuss the approach.
- Pactum handles personal data and legal evidence. Changes to tokens, hashes,
  status transitions or stored evidence need particularly careful review and tests.

## Development setup

```bash
git clone https://github.com/pdir/contao-pactum-bundle.git
cd contao-pactum-bundle
composer install
```

To test the bundle in a Contao installation, add it as a path repository:

```json
"repositories": [{ "type": "path", "url": "../contao-pactum-bundle" }]
```

## Checks

All checks must pass before a pull request can be merged:

```bash
composer cs-fix      # ECS (contao/easy-coding-standard), fixes automatically
composer phpstan     # PHPStan level 8
composer test        # PHPUnit unit tests
```

Functional tests need a MySQL/MariaDB database (all `tl_pactum_*` tables in it are
dropped and recreated):

```bash
PACTUM_TEST_DATABASE_URL=mysql://user:pass@127.0.0.1:3306/pactum_test composer test-functional
```

## Guidelines

- `declare(strict_types=1);` and the license header in every PHP file.
- Services via autowiring/autoconfiguration, controllers with attributes.
- No logic in templates, no static helpers.
- Translations as XLIFF in `translations/` (German and English).
- Every class with business logic gets unit tests; security-relevant logic is mandatory.
- **Never** write personal data to logs, URLs or exception messages.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:` …).
- Mark untested or security-relevant code clearly in the pull request.
- Add an entry to `CHANGELOG.md` under "Unreleased".

## License

By contributing, you agree that your contributions are licensed under the
LGPL-3.0-or-later.
