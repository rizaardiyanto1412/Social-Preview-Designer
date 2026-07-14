# PLAN.md — WP Remote Work Dynamic OG Image Plugin

Implement this plan milestone by milestone. For each milestone:

1. Implement the required functionality.
2. Create or update tests for that milestone.
3. Run the relevant automated tests.
4. Verify admin/frontend behavior with Playwright interactive where applicable.
5. Compare against `/reference-screens/` when relevant.
6. Record progress, test results, blockers, and deviations in `IMPLEMENTATION_LOG.md`.

Do not skip milestones. Do not silently alter architecture decisions from `SPEC.md`.

---

## Milestone 1 — Plugin Foundation

### Goals

- Create the plugin structure.
- Add admin menu/pages.
- Add settings storage.
- Create uploads directory management.
- Add diagnostics panel.

### Implementation Tasks

- Create plugin bootstrap file.
- Register admin menu.
- Add settings/admin pages:
  - Template Editor
  - Dynamic Fields
  - Fonts
  - Generation Tools
  - Diagnostics
- Create helper for plugin upload directory:

```text
/wp-content/uploads/wp-remote-og/
```

- Add diagnostics for:
  - Imagick availability
  - GD availability
  - Rank Math availability
  - ACF availability
  - uploads directory writable status
- Add plugin activation checks.
- Add basic admin notices for missing/limited dependencies.

### Tests

- Plugin activates without fatal errors.
- Admin pages are registered.
- Upload directory is created or reported unavailable.
- Diagnostics correctly report Imagick/GD/Rank Math/ACF availability.
- Non-admin users cannot access plugin admin screens.

### Playwright Verification

- Visit plugin admin page.
- Confirm all admin sections are visible.
- Confirm diagnostics panel renders without console errors.

---

## Milestone 2 — Dynamic Field Mapping

### Goals

- Allow admin to configure available dynamic fields.
- Support post title, custom taxonomies, and ACF fields.

### Implementation Tasks

- Add Dynamic Fields settings UI.
- Support tokens:

```text
{post_title}
{taxonomy:taxonomy_name}
{acf:field_name}
```

- Store enabled fields in plugin settings.
- Add resolver service that receives a post ID and token and returns display text.
- Handle missing fields safely.
- Add labels/friendly names for mapped fields.
- Add optional fallback value per mapped field if feasible.

### Tests

- `{post_title}` resolves correctly.
- Taxonomy tokens resolve correctly.
- ACF tokens resolve correctly when ACF exists.
- Missing ACF fields return fallback/empty value without fatal error.
- Missing taxonomy terms return fallback/empty value without fatal error.
- Invalid token formats are rejected or ignored safely.

### Playwright Verification

- Open Dynamic Fields page.
- Add/edit/remove mapped fields.
- Save settings.
- Reload page and confirm settings persist.

---

## Milestone 3 — Template Editor UI

### Goals

- Build the focused 1200×630 visual template editor.
- Support background image and dynamic text layers.

### Implementation Tasks

- Add fixed 1200×630 canvas.
- Add background image selector/uploader.
- Add draggable text layers.
- Add resizable text layer boxes.
- Add layer list panel.
- Add sidebar controls:
  - token/content
  - font family
  - font size
  - min font size
  - color
  - alignment
  - line height
  - max lines
  - x position
  - y position
  - width
  - height
- Add safe-area guides.
- Save template JSON to plugin settings.
- Sanitize and validate template JSON.

### Tests

- Template JSON saves correctly.
- Invalid template JSON is rejected/sanitized.
- Layers persist after reload.
- Background image setting persists.
- Required layer properties are validated.
- Coordinates/dimensions are clamped to safe numeric values.

### Playwright Verification

- Open Template Editor.
- Add a text layer.
- Drag and resize it.
- Change typography settings.
- Save template.
- Reload and confirm layout persists.

---

## Milestone 4 — Live Preview With Real Post

### Goals

- Preview template using selected real post data.

### Implementation Tasks

- Add post selector to Template Editor.
- Fetch dynamic values for selected post.
- Render preview with resolved post title, taxonomy values, and ACF values.
- Show missing-field warnings where applicable.
- Ensure preview endpoint requires authorization and nonce validation.

### Tests

- Preview endpoint returns resolved values for a selected post.
- Missing dynamic fields do not break preview.
- Preview only works for authorized users.
- Preview endpoint rejects invalid/nonexistent post IDs.

### Playwright Verification

- Select an existing post.
- Confirm preview updates with that post’s title/fields.
- Confirm long titles display according to layer rules.

---

## Milestone 5 — Font Management

### Goals

- Support custom uploaded fonts.

### Implementation Tasks

- Add Fonts admin page.
- Allow upload of TTF/OTF and WOFF/WOFF2 if feasible.
- Validate file types and MIME types.
- Store font metadata.
- Make uploaded fonts available to editor preview and server rendering.
- Show warning if font cannot be used by rendering engine.
- Prevent public listing of font management data.

### Tests

- Valid font upload succeeds.
- Invalid file upload is rejected.
- Uploaded font appears in editor font selector.
- Font metadata persists.
- Non-admin users cannot upload/manage fonts.

### Playwright Verification

- Upload/select a font.
- Apply it to a text layer.
- Confirm editor preview updates.

---

## Milestone 6 — Server-Side PNG Rendering

### Goals

- Generate PNG images server-side.
- Prefer Imagick and fallback to GD.

### Implementation Tasks

- Build rendering service.
- Implement Imagick renderer.
- Implement GD fallback renderer.
- Support:
  - 1200×630 output
  - background image
  - text layers
  - custom fonts where supported
  - text color
  - alignment
  - line height
  - wrapping
  - shrink-to-fit
  - ellipsis fallback
- Generate PNG output.
- Return detailed errors without exposing sensitive paths to public visitors.

### Tests

- Renderer creates valid PNG.
- Rendered PNG is exactly 1200×630.
- Renderer handles missing background.
- Renderer handles long text using wrap → shrink → ellipsis.
- Renderer handles missing dynamic fields.
- Imagick path works when available.
- GD fallback path works when Imagick is unavailable or mocked unavailable.
- Invalid image/font paths are rejected safely.

### Playwright Verification

- Generate preview image from admin.
- Confirm generated image displays in browser.
- Confirm no console errors.

---

## Milestone 7 — Image Storage and Versioning

### Goals

- Store generated images in uploads.
- Use versioned active filenames.
- Delete old versions.

### Implementation Tasks

- Save generated files to:

```text
/wp-content/uploads/wp-remote-og/
```

- Use filenames like:

```text
post-123-og-a8f91c.png
```

- Store active image metadata in post meta:

```text
_wp_remote_og_image_url
_wp_remote_og_image_path
_wp_remote_og_image_hash
_wp_remote_og_generated_at
_wp_remote_og_template_version
```

- Delete previous generated image for the post after successful regeneration.
- Never delete files outside plugin upload directory.
- Use safe path checks before deletion.

### Tests

- Generated image file exists.
- Post meta is updated.
- Regeneration creates new versioned filename.
- Previous generated file is deleted.
- File deletion is restricted to plugin upload directory.
- Failed regeneration keeps previous valid image.

---

## Milestone 8 — Generation Workflows

### Goals

- Automatically generate images.
- Add manual and bulk regeneration.

### Implementation Tasks

- Generate image when a post is published or updated.
- Avoid infinite save/regeneration loops.
- Add post-level regenerate button/meta box.
- Add bulk regenerate all.
- Add regenerate missing only.
- Use batching/AJAX/WP-Cron to avoid timeouts.
- Show progress and error reporting.

### Tests

- Publishing a post generates an image.
- Updating a post regenerates an image.
- Manual regenerate works.
- Bulk regenerate processes posts in batches.
- Failed generation keeps previous valid image where possible.
- Auto-generation does not run for non-post post types.

### Playwright Verification

- Open post edit screen.
- Click Regenerate OG Image.
- Confirm preview and metadata update.
- Run bulk regenerate from Generation Tools page.
- Confirm progress UI updates.

---

## Milestone 9 — Template Change Regeneration Prompt

### Goals

- Prompt admin to regenerate images after template changes.

### Implementation Tasks

- Track template version/hash.
- When template changes, show notice:

```text
Template changed. Regenerate all existing OG images now?
```

- Add confirmation button.
- Trigger bulk regeneration only after confirmation.
- Avoid showing the notice repeatedly once regeneration is complete.

### Tests

- Template change updates template version/hash.
- Notice appears after template change.
- Notice does not trigger regeneration automatically.
- Confirmation starts bulk regeneration.
- Notice clears after regeneration completes.

### Playwright Verification

- Change template.
- Save.
- Confirm regeneration notice appears.
- Click confirmation.
- Confirm bulk regeneration starts.

---

## Milestone 10 — Rank Math Integration and Fallback Meta

### Goals

- Let Rank Math output the generated OG image.
- Avoid duplicate OG tags.
- Output fallback tags only when Rank Math is inactive.

### Implementation Tasks

- Hook generated image URL into Rank Math OpenGraph image filters supported by the installed Rank Math version.
- Support Facebook/OpenGraph image.
- Support Twitter image.
- Do not print plugin OG tags when Rank Math is active.
- If Rank Math is inactive, print fallback OG/Twitter tags on singular posts only.
- Fallback should use generated image if available.
- Ensure fallback does not run on archives, admin pages, feeds, or REST endpoints.

### Tests

- Rank Math active: generated image URL is provided through Rank Math hooks.
- Rank Math active: plugin does not output duplicate tags.
- Rank Math inactive: plugin outputs fallback `og:image`.
- Non-singular pages do not output fallback tags.
- Posts without generated images fall back gracefully.

### Playwright Verification

- Visit a single post frontend.
- Inspect page head.
- Confirm generated image URL appears.
- Confirm duplicate OG tags are not present.

---

## Milestone 11 — Cleanup Tools

### Goals

- Prevent storage flooding.

### Implementation Tasks

- Delete generated image when post is permanently deleted.
- Do not delete image merely when post is moved to trash.
- Add orphan cleanup tool.
- Detect generated files not linked to existing post meta.
- Delete only safe orphaned files inside plugin upload directory.
- Show cleanup result summary.

### Tests

- Permanent post deletion removes active generated image.
- Moving post to trash does not immediately delete generated image.
- Orphan cleanup detects orphaned files.
- Orphan cleanup deletes only plugin-generated files.
- Orphan cleanup cannot delete arbitrary files outside plugin directory.

### Playwright Verification

- Run orphan cleanup from admin.
- Confirm cleanup result summary appears.

---

## Milestone 12 — Final QA

### Goals

- Verify the full v1 product.

### Implementation Tasks

- Test end-to-end flow:
  - configure fields
  - upload font
  - design template
  - preview real post
  - generate image
  - check Rank Math output
  - regenerate image
  - bulk regenerate
  - cleanup orphaned files
- Check error handling.
- Check admin permissions.
- Check non-admin access restrictions.
- Capture final screenshots where helpful.

### Tests

- Full automated test suite passes.
- No fatal errors on plugin activation/deactivation.
- No duplicate OG tags.
- Generated images are valid 1200×630 PNGs.
- Storage cleanup works.
- Long titles render acceptably.
- Plugin does not break when ACF is inactive.
- Plugin does not break when Rank Math is inactive.
- Plugin does not break when Imagick is unavailable.

### Playwright Verification

- Complete admin workflow from setup to generated frontend OG tag.
- Capture final reference screenshots:
  - Template Editor
  - Dynamic Fields page
  - Font page
  - Generation Tools page
  - Post-level regenerate panel
  - Frontend post head verification
