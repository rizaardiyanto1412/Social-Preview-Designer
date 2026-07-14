# ACCEPTANCE_CHECKLIST.md — WP Remote OG Plugin v1

Use this checklist before considering v1 complete.

## Core Plugin

- [ ] Plugin activates without fatal errors.
- [ ] Plugin deactivates without fatal errors.
- [ ] Admin pages are visible to authorized users.
- [ ] Admin pages are blocked for unauthorized users.
- [ ] Upload directory is created or clear error is shown.
- [ ] Diagnostics show Imagick status.
- [ ] Diagnostics show GD status.
- [ ] Diagnostics show Rank Math status.
- [ ] Diagnostics show ACF status.
- [ ] Diagnostics show upload directory writable status.

## Template Editor

- [ ] Editor uses fixed 1200×630 canvas.
- [ ] Background image can be selected/uploaded.
- [ ] Text layers can be added.
- [ ] Text layers can be dragged.
- [ ] Text layers can be resized.
- [ ] Layer list works.
- [ ] Typography controls work.
- [ ] Text color control works.
- [ ] Alignment control works.
- [ ] Line height control works.
- [ ] Layer position and size controls work.
- [ ] Safe-area guides are visible.
- [ ] Template saves correctly.
- [ ] Template persists after reload.

## Dynamic Fields

- [ ] `{post_title}` works.
- [ ] Custom taxonomy tokens work.
- [ ] ACF field tokens work.
- [ ] Missing ACF fields do not break rendering.
- [ ] Missing taxonomy terms do not break rendering.
- [ ] Dynamic field settings persist after reload.

## Preview

- [ ] Admin can select a real post for preview.
- [ ] Preview updates with selected post title.
- [ ] Preview updates with selected taxonomy/ACF fields.
- [ ] Long title preview behaves acceptably.
- [ ] Missing fields show warnings or safe fallback.

## Fonts

- [ ] Valid font upload succeeds.
- [ ] Invalid font upload is rejected.
- [ ] Uploaded font appears in editor.
- [ ] Uploaded font can be used in server-rendered PNG where supported.
- [ ] Font rendering limitation warnings appear when needed.

## Rendering

- [ ] PNG generation works with Imagick when available.
- [ ] PNG generation works with GD fallback when Imagick is unavailable or mocked unavailable.
- [ ] Generated PNG is exactly 1200×630.
- [ ] Background image renders.
- [ ] Text layers render.
- [ ] Text color renders.
- [ ] Text alignment renders.
- [ ] Text wrapping works.
- [ ] Shrink-to-fit works.
- [ ] Ellipsis fallback works.
- [ ] Missing dynamic values do not cause fatal errors.

## Storage and Versioning

- [ ] Generated images are stored in `/wp-content/uploads/wp-remote-og/`.
- [ ] Filenames are versioned, for example `post-123-og-a8f91c.png`.
- [ ] Post meta stores active image URL/path/hash/date/template version.
- [ ] Regeneration creates a new filename.
- [ ] Previous generated file is deleted after successful regeneration.
- [ ] Failed regeneration keeps previous valid image.
- [ ] Deletion logic cannot delete files outside plugin upload directory.

## Generation Workflows

- [ ] Publishing a post generates an OG image.
- [ ] Updating a post regenerates an OG image.
- [ ] Post-level regenerate button works.
- [ ] Bulk regenerate all works.
- [ ] Regenerate missing only works.
- [ ] Bulk process shows progress.
- [ ] Bulk process reports errors.
- [ ] Auto-generation does not run for non-post post types.

## Template Change Regeneration

- [ ] Template version/hash changes when template changes.
- [ ] Notice appears after template change.
- [ ] Notice asks admin to regenerate all existing images.
- [ ] Bulk regeneration does not start without confirmation.
- [ ] Confirmation starts bulk regeneration.
- [ ] Notice clears after regeneration completes.

## Rank Math and OG Meta

- [ ] Rank Math active: generated image appears as OG image.
- [ ] Rank Math active: generated image appears as Twitter image where applicable.
- [ ] Rank Math active: plugin does not output duplicate OG tags.
- [ ] Rank Math inactive: plugin outputs fallback `og:image` on singular posts.
- [ ] Rank Math inactive: plugin outputs fallback Twitter image on singular posts.
- [ ] Fallback tags do not appear on archives.
- [ ] Fallback tags do not appear in admin.
- [ ] Posts without generated images fall back gracefully.

## Cleanup

- [ ] Moving post to trash does not immediately delete generated image.
- [ ] Permanently deleting post deletes active generated image.
- [ ] Orphan cleanup detects orphaned generated files.
- [ ] Orphan cleanup deletes only safe files inside plugin directory.
- [ ] Orphan cleanup shows result summary.

## Security

- [ ] Admin actions use nonces.
- [ ] Settings are sanitized.
- [ ] Template JSON is sanitized/validated.
- [ ] Uploaded images are validated.
- [ ] Uploaded fonts are validated.
- [ ] Admin output is escaped.
- [ ] AJAX/REST endpoints are protected.
- [ ] Unauthorized users cannot generate images.
- [ ] Unauthorized users cannot upload fonts.
- [ ] Unauthorized users cannot change templates.

## Final E2E

- [ ] Configure dynamic fields.
- [ ] Upload font.
- [ ] Design template.
- [ ] Preview with real post.
- [ ] Generate image.
- [ ] Confirm frontend OG tag.
- [ ] Regenerate image.
- [ ] Confirm URL cache-busting changed.
- [ ] Bulk regenerate.
- [ ] Cleanup orphans.
- [ ] Confirm no fatal errors or console errors.
