=== Social Preview Designer ===
Contributors: wpremotework
Tags: open graph, social image, dynamic image, og image, rank math
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design one Open Graph template and automatically generate a branded social preview image for every post. Integrates with Rank Math.

== Description ==

Social Preview Designer lets you design a single 1200×630 Open Graph image template in a visual editor and automatically generates a static PNG social preview image for every standard post — on publish, on update, or in bulk.

Instead of every post sharing the same generic social image, each post gets its own server-rendered image with dynamic, post-specific content over your consistent branded background.

= Features =

* **Visual template editor** — drag, resize, and style text, image, and line layers on a live 1200×630 canvas, with a safe-area guide and per-post preview.
* **Dynamic tokens** — pull in the post title, taxonomy terms, custom fields (including ACF), post meta, and author avatars with configurable fallbacks.
* **Server-side rendering** — images are generated as static PNG files with Imagick (GD fallback), so there is no runtime overhead when pages are served.
* **Custom fonts** — upload TTF/OTF/WOFF/WOFF2 files or add a Google Font by family name.
* **Automatic generation** — images regenerate when a post is published or updated; bulk tools regenerate all posts or only posts with missing images.
* **Housekeeping** — orphaned image cleanup, a generation log, and a diagnostics screen showing exactly what your server supports.
* **Rank Math integration** — generated images are automatically used for `og:image` and Twitter card tags when Rank Math is active, with a built-in fallback meta output when it is not.
* **Clean uninstall** — deleting the plugin removes all of its options, post meta, and generated files.

= External services =

This plugin connects to the Google Fonts service, but only when you use the optional "Add Google Font" feature on the Fonts screen:

* `https://fonts.google.com/metadata/fonts` — fetches the list of available font families so the font picker can offer suggestions.
* `https://fonts.googleapis.com/css2` — fetches the stylesheet for the font family you request.
* `https://fonts.gstatic.com` — downloads the actual font file, which is then stored locally in your uploads folder and used for rendering.

Only the font family name you enter is sent in these requests; no personal data, site content, or visitor data is transmitted. Requests happen server-side and only when an administrator adds a Google Font. Google's terms and privacy policy apply: [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).

If you never use the Google Fonts feature, the plugin makes no external requests at all.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Go to **Social Preview Designer → Template Editor** to design your template.
4. Configure tokens under **Dynamic Fields** and add fonts under **Fonts** if needed.
5. Use **Generation Tools** to generate images for existing posts. New and updated posts are handled automatically.

= Requirements =

* PHP Imagick extension recommended (GD is used as a fallback).
* Writable uploads directory (images are stored in `wp-content/uploads/wp-remote-og/`).

== Frequently Asked Questions ==

= Which post types are supported? =

Version 1.0 generates images for the standard `post` post type.

= Does it work without Rank Math? =

Yes. With Rank Math active, the generated image replaces the Open Graph and Twitter images Rank Math outputs. Without Rank Math, the plugin outputs its own `og:image` and Twitter card meta tags.

= Where are the images stored? =

In `wp-content/uploads/wp-remote-og/` as static PNG files. Old images are cleaned up automatically when a post's image is regenerated, and an orphan-cleanup tool is included.

= Does the plugin phone home or send my data anywhere? =

No. The only external requests are the optional, admin-initiated Google Fonts requests described in the "External services" section.

== Screenshots ==

1. Visual template editor with layers, canvas, and live preview.
2. Dynamic fields configuration.
3. Generated Open Graph image example.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Visual 1200×630 template editor with text, image, and line layers.
* Dynamic tokens for title, taxonomies, ACF fields, post meta, and author avatars.
* Imagick rendering with GD fallback.
* Custom font upload and Google Fonts support.
* Automatic generation on publish/update, bulk regeneration, and orphan cleanup.
* Rank Math integration plus standalone meta tag fallback.
* Uninstall cleanup of all options, post meta, and generated files.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
