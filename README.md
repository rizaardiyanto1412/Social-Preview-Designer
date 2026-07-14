# Social Preview Designer

Design one Open Graph image template and automatically generate a branded, server-rendered social preview image for every WordPress post. Integrates with Rank Math.

The user-facing plugin readme (used by WordPress.org) is [readme.txt](readme.txt).

## Development

- `wp-remote-og-plugins.php` — the entire plugin (admin pages, renderer, fonts, SEO integration).
- `assets/` — admin editor JS/CSS.
- `tests/run-tests.php` — WP-CLI integration test harness: `wp eval-file tests/run-tests.php`.
- `docs/` — original planning documents (spec, plan, acceptance checklist).

### Building a release zip

Files excluded from distribution are listed in `.distignore`. Build with:

```bash
wp dist-archive . ./dist
```

### Checking wp.org compliance

```bash
wp plugin install plugin-check --activate
wp plugin check wp-remote-og-plugins
```

Note: the local development folder is named `wp-remote-og-plugins`; for WordPress.org submission the distributed folder/slug should be renamed (e.g. `social-preview-designer`) because new wp.org slugs may not contain the terms `wp` or `plugin`. Internal code identifiers (`wp_remote_og_*` prefixes, text domain) intentionally keep the original naming.
