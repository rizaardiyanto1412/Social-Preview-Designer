# Social Preview Designer

Design one Open Graph image template and automatically generate a branded, server-rendered social preview image for every WordPress post. Integrates with Rank Math.

The user-facing plugin readme (used by WordPress.org) is [readme.txt](readme.txt).

## Admin pages

All screens live under the top-level **Social Preview Designer** menu and share a
common app shell (brand + nav pills):

- **Dashboard** (`wp-remote-og`) — readiness checklist, generation-health counts,
  quick actions, and recently generated images.
- **Template Editor** (`wp-remote-og-editor`) — top action bar, Structure panel
  with undo/redo, elevated 1200×630 artboard, and a contextual inspector.
- **Templates** (`wp-remote-og-templates`) — gallery of built-in presets with
  category filters, live previews, Apply (with confirmation + backup), and
  Restore-previous-template.
- **Dynamic Fields** (`wp-remote-og-fields`) — token-to-post-data mapping.
- **Fonts** (`wp-remote-og-fonts`) — custom/Google font management.
- **Generation Tools** (`wp-remote-og-tools`) — bulk generate/regenerate/cleanup.
- **Diagnostics** (`wp-remote-og-diagnostics`) — environment and status report.

The Template Editor previously lived at `wp-remote-og`; that slug now shows the
Dashboard while the editor moved to `wp-remote-og-editor`.

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
