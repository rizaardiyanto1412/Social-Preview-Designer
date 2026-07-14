# SPEC.md — WP Remote Work Dynamic OG Image Plugin

## 1. Product Summary

Build a custom WordPress plugin for **wpremotework.com** that generates dynamic Open Graph social preview images for every standard WordPress post.

The site currently uses the standard WordPress `post` post type for job posts. All posts are job posts.

Current problem:

- All posts currently use the same default Open Graph image.
- Social previews do not reflect individual job post content.

Desired outcome:

- Each post has its own generated `og:image`.
- The visual background/template can remain consistent.
- Dynamic text/content changes per post.
- The site owner has editor control over layout, typography, and dynamic fields.

The plugin should be WordPress-first, server-rendered, editor-friendly, storage-efficient, and integrated with Rank Math SEO.

---

## 2. Core Product Goal

Create a custom WordPress plugin that allows admins/editors to design one global Open Graph image template and automatically generate static PNG images for all posts.

Generated images should use:

- a consistent branded background/template
- dynamic post-specific text
- selected custom taxonomies
- selected ACF fields
- custom uploaded fonts
- server-side image generation
- Rank Math integration for social meta output

The plugin should not be a full Slider Revolution clone. It should be a focused static image template editor optimized specifically for social preview images.

---

## 3. Locked v1 Architecture Decisions

### 3.1 Plugin Type

Build this as a **custom WordPress plugin**.

Do not use an external SaaS rendering service for v1 unless server-side WordPress rendering proves impossible.

### 3.2 Post Type

The plugin targets the standard WordPress post type:

```text
post
```

All posts should be treated as job posts.

No custom post type is required for v1.

### 3.3 Image Format and Size

Generate static PNG images.

Canvas size must be fixed:

```text
1200 × 630
```

Do not make the canvas size configurable in v1.

### 3.4 Rendering Engine

Use server-side rendering.

Preferred rendering engine order:

1. **Imagick / ImageMagick**, if available
2. **GD fallback**, if Imagick is unavailable

If Imagick is unavailable, show an admin notice explaining that GD fallback is being used and that some rendering quality/features may be limited.

### 3.5 Image Generation Timing

Use a hybrid generation workflow:

1. Automatically generate or regenerate an OG image when a post is published or updated.
2. Add a post-level manual button: **Generate / Regenerate OG Image**.
3. Add a bulk admin tool: **Regenerate OG Images for All Posts**.
4. Add a bulk admin tool: **Regenerate Missing OG Images Only**.
5. When the global template changes, show a confirmation prompt/button:

```text
Template changed. Regenerate all existing OG images now?
```

Do not automatically regenerate all posts immediately after a template change without confirmation.

### 3.6 Storage Strategy

Generated images should be stored as physical PNG files in the WordPress uploads directory.

Suggested directory:

```text
/wp-content/uploads/wp-remote-og/
```

Use versioned active filenames for cache-busting while preventing storage bloat.

Example:

```text
post-123-og-a8f91c.png
```

When a post’s OG image is regenerated:

1. Generate a new versioned filename.
2. Update the active image path/URL stored for that post.
3. Delete the previous generated image for that post after successful generation.

This gives social platforms a fresh image URL after regeneration while still keeping only one active generated OG image per post.

### 3.7 Storage Cleanup

Implement cleanup rules:

1. Delete the generated OG image when a post is permanently deleted.
2. Do not delete the image merely when a post is moved to trash, so restored posts can keep their image.
3. Add an admin utility: **Delete Orphaned OG Images**.
4. Orphaned images are generated files whose related post no longer exists or is no longer linked to that image.
5. Avoid uncontrolled version buildup.
6. Never delete files outside the plugin’s generated uploads directory.

---

## 4. Rank Math SEO Integration

Rank Math currently controls Open Graph output on the site.

The plugin must integrate with Rank Math instead of printing duplicate OG tags when Rank Math is active.

Desired behavior:

1. Plugin generates and stores the PNG.
2. Plugin provides the generated image URL to Rank Math.
3. Rank Math outputs the final `og:image` and Twitter image tags.
4. The plugin must not output duplicate OG tags while Rank Math is active.
5. If Rank Math is inactive, the plugin may output fallback OG/Twitter meta tags itself.

Implementation notes:

- Use Rank Math OpenGraph/Twitter image filters compatible with the installed Rank Math version.
- Expected hook pattern may include network-specific OpenGraph image filters, but the final implementation should verify the exact current hooks in the installed plugin/docs.
- Avoid duplicate tags at all costs.
- Fallback meta output should only happen when Rank Math is inactive.
- Fallback meta output should only run on singular `post` pages.

---

## 5. Dynamic Field System

The template editor should support dynamic fields from:

1. Post title
2. Selected custom taxonomies
3. Selected ACF fields

Implement manual field mapping in plugin settings.

The admin should be able to enable and label available dynamic fields.

Example dynamic tokens:

```text
{post_title}
{taxonomy:job_location}
{taxonomy:job_type}
{acf:company_name}
{acf:salary_range}
```

The exact taxonomy and ACF field names must be configurable by the admin.

Do not hardcode only one company/location/salary structure.

### 5.1 Missing Dynamic Field Behavior

If a dynamic field is missing:

- use an empty string, placeholder, or configured fallback
- do not break image generation
- optionally show missing-field warnings in preview/admin tools

---

## 6. Template System

Use one global OG image template for v1.

Do not build multiple templates or assignment rules in v1.

Future expansion may include:

- multiple templates
- template assignment by taxonomy
- template assignment by category
- featured-job templates
- per-post template overrides

These are out of scope for v1.

---

## 7. Visual Template Editor

Build a focused visual editor, similar in spirit to a lightweight Canva/Figma-style static image editor.

The editor should include:

- fixed 1200×630 canvas
- background image selector/uploader
- draggable text layers
- resizable layer boxes
- layer list panel
- typography sidebar controls
- dynamic field insertion
- custom font selection
- text color control
- font size control
- font weight/style controls if supported
- text alignment
- line height control
- layer position controls
- layer width/height controls
- safe-area guides
- live preview using a selected real post

Do not include:

- animations
- timelines
- sliders
- responsive breakpoints
- multi-slide support
- full page-builder functionality

The goal is static image composition only.

---

## 8. Preview Workflow

The editor must support previewing the template with an existing real post.

Admin should be able to select a post and see the template populated with that post’s dynamic values.

Preview should help test:

- long job titles
- missing ACF values
- long taxonomy labels
- real-world spacing
- visual overflow

---

## 9. Long Text Handling

Use a hybrid text fitting strategy:

1. Wrap text to multiple lines inside the layer box.
2. Shrink font size down to a configured minimum if needed.
3. If the text still does not fit, truncate with ellipsis.

This should apply especially to long post titles/job titles.

Each text layer should ideally have configurable settings:

- max lines
- starting font size
- minimum font size
- line height
- overflow behavior

Default overflow behavior:

```text
wrap → shrink → ellipsis
```

---

## 10. Font Handling

Support custom font uploads in v1.

Admins should be able to upload/select font files for use in generated OG images.

Preferred formats:

- TTF
- OTF
- WOFF/WOFF2 if feasible

The rendering layer must be able to use uploaded fonts server-side.

If a font cannot be rendered by the available engine, show a clear admin warning.

Do not expose or distribute font files outside the WordPress uploads/admin context except as necessary for preview rendering.

---

## 11. Admin UX

Create a plugin admin area with sections similar to the following.

### 11.1 Template Editor

Contains the visual editor.

Features:

- background image selection
- canvas editor
- text layer creation
- dynamic field insertion
- layer list
- styling controls
- real post preview selector
- save template button

### 11.2 Dynamic Fields

Allows admin to configure available dynamic fields.

Support:

- post title
- selected taxonomies
- selected ACF fields

### 11.3 Fonts

Allows admin to upload/manage custom fonts.

### 11.4 Generation Tools

Contains:

- regenerate all images
- regenerate missing images only
- delete orphaned images
- view generation status/logs

### 11.5 Settings / Diagnostics

Show:

- Imagick availability
- GD availability
- uploads directory status
- Rank Math status
- ACF status
- last bulk generation result
- number of generated images
- number of missing images
- number of orphaned images

---

## 12. Post-Level UX

On individual post edit screens, add a small plugin meta box or panel showing:

- current generated OG image preview
- image generation status
- generated image URL
- **Regenerate OG Image** button
- timestamp of last generation
- warning if required dynamic fields are missing

This should not replace the main template editor.

---

## 13. Fallback Behavior

### 13.1 Image Generation Failure

If an image cannot be generated:

- keep the previous valid generated image if one exists
- otherwise fall back to the default Rank Math/social image behavior
- log the failure in the admin diagnostics area

### 13.2 Rank Math Inactive

If Rank Math is inactive:

- output fallback OG/Twitter meta tags from the plugin
- only output fallback tags on singular post pages
- avoid duplicate tags if another SEO plugin is detected where feasible

---

## 14. Bulk Regeneration

Bulk regeneration should be admin-triggered.

It should support:

- regenerate all post OG images
- regenerate only missing images
- cleanup orphaned images

For large post counts, avoid blocking or timeout-prone behavior.

Use one of these approaches:

- queued/background processing
- batched AJAX regeneration
- WP-Cron batches

The UI should show progress and errors.

---

## 15. Security and Permissions

Only authorized admins/editors should manage templates and regeneration tools.

Use appropriate WordPress capabilities, nonces, and sanitization.

At minimum:

- protect all admin actions with nonces
- sanitize template JSON/settings
- validate uploaded image/font files
- avoid arbitrary file deletion
- only delete files inside the plugin’s generated uploads directory
- escape all admin output
- restrict preview/generation endpoints to authorized users

---

## 16. Data Storage

Store template configuration in WordPress options or a custom settings structure.

Store generated image metadata per post, likely as post meta.

Suggested post meta:

```text
_wp_remote_og_image_url
_wp_remote_og_image_path
_wp_remote_og_image_hash
_wp_remote_og_generated_at
_wp_remote_og_template_version
```

The exact names can vary, but the system must track the active image and safely delete previous versions.

Suggested option keys:

```text
wp_remote_og_template
wp_remote_og_dynamic_fields
wp_remote_og_fonts
wp_remote_og_settings
wp_remote_og_template_version
```

---

## 17. Acceptance Criteria

The v1 plugin is complete when:

1. Admin can create/edit one global 1200×630 OG image template.
2. Admin can upload/select a background image.
3. Admin can add draggable/resizable dynamic text layers.
4. Admin can map post title, custom taxonomies, and ACF fields as dynamic fields.
5. Admin can preview the image using a selected real post.
6. Plugin generates a PNG server-side.
7. Plugin uses Imagick when available and GD fallback otherwise.
8. All posts automatically receive generated OG images on publish/update.
9. A post-level regenerate button exists.
10. Bulk regenerate tools exist.
11. Template changes prompt admin to regenerate all existing images.
12. Images are stored in uploads with versioned active filenames.
13. Old versions are deleted when regenerating.
14. Generated images are deleted when posts are permanently deleted.
15. An orphan cleanup tool exists.
16. Rank Math outputs the generated image as `og:image` when Rank Math is active.
17. Plugin does not output duplicate OG tags when Rank Math is active.
18. Plugin outputs fallback OG tags only if Rank Math is inactive.
19. Long titles wrap, shrink, and then truncate with ellipsis if necessary.
20. Admin diagnostics show rendering engine, Rank Math, ACF, and storage status.

---

## 18. Out of Scope for v1

Do not build these in v1:

- multiple templates
- taxonomy-based template assignment
- full Slider Revolution clone
- animation/timeline system
- external SaaS rendering
- dynamic rendering on every social crawler request
- custom post type migration
- user-facing frontend editor
- advanced image effects beyond what is needed for static OG composition
- per-post template customization unless simple regeneration controls require it

---

## 19. Final Product Direction

The plugin should feel like a focused branded OG image generator for a WordPress job board.

It should give the site owner visual control over layout and styling while keeping generation reliable, automatic, storage-efficient, and SEO-compatible.
