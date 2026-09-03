# Development

Atomic WP Social Sync is maintained by Bernard Coubeaux (`bernard@atomic-design.be`) and published by Atomic Design Belgium at https://atomic-design.be/.

## Requirements

- WordPress 6.0+
- PHP 8.1+ with OpenSSL
- Node is required only if the small JavaScript files are later moved to a compiled workflow; version 0.1.0 ships dependency-free browser JavaScript.
- Composer is optional for WordPress Coding Standards checks.

No production Composer package or paid dependency is used. The plugin has a small PSR-4-style autoloader scoped to `AtomicWPSocialSync`.

## Local checks

Lint all PHP files with the site's PHP binary. In this Local environment, the disposable integration script can be run with `ATOMIC_SOCIAL_RUN_TESTS=1`:

```text
php-cgi -q -c <Local PHP config directory> -f tests/wp-integration.php
```

It verifies registration, idempotent import, remote edits, local/remote conflicts, detach, two-stage missing handling, permission-error safety, and local feed rendering. It creates only fixture-provider Social Posts and removes them in `finally`, restoring plugin settings and Connections.

Optional coding-standard setup:

```text
composer install
composer phpcs
```

## Coding conventions

- PHP follows WordPress spacing/escaping/i18n conventions with PHP 8 types where WordPress compatibility permits.
- Important WordPress hooks are registered centrally in `Plugin` and point to named methods.
- Settings and meta keys are centralized in `PluginSettings` and `MetaKeys`.
- Errors crossing the provider boundary use categorized `ProviderException` objects and must not contain secrets.
- Comments explain ownership, security, or provider quirks rather than restating syntax.

## Release checklist

1. Review the current LinkedIn Marketing API version, migration notes, scopes, role rules, and tier limits.
2. Update `LinkedInClient::API_VERSION` only after testing the selected version.
3. Run PHP syntax, integration, PHPCS, secret search, and browser accessibility/responsive checks.
4. Test activation/deactivation and both single-page settings, including rewrite behavior.
5. Test OAuth with Development-tier credentials, then with the intended production tier.
6. Verify Client Secret/access/refresh/auth codes are absent from HTML, REST, logs, fixtures, and commits.
7. Keep `atomic-wp-social-sync.php`, `composer.json`, `README.md`, `readme.txt`, and changelog version/attribution aligned.

## Known test boundary

Automated fixtures exercise core business rules without a LinkedIn account. Live OAuth consent, Page selection, signed media URLs, and real endpoint quotas require an approved LinkedIn Developer App and administrator interaction.
