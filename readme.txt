=== n8n Form Integration ===
Contributors: Jason Cox
Tags: n8n, forms, shortcode, embed, iframe
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage multiple n8n form embeds and generate shortcodes to place them anywhere.

== Description ==

n8n Form Integration lets site administrators save multiple n8n form URLs and embed them with the `[n8n_form]` shortcode.

Each saved form can define default iframe dimensions, loading behavior, and referrer policy. Shortcode attributes can override width and height settings for individual placements.

== Installation ==

1. Upload the `n8n-form-integration` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > n8n Form Integration to add form URLs.

== Frequently Asked Questions ==

= What shortcode should I use? =

Use `[n8n_form id="your-form-slug"]`.

= Can I override dimensions in a shortcode? =

Yes. Use attributes such as `[n8n_form id="your-form-slug" maxwidth="1000px" minheight="70vh" width="100%"]`.

== Changelog ==

= 1.0.8 =
* Updated Plugin Update Checker to 5.7.
* Added plugin-specific GitHub token support via `N8N_FORM_INTEGRATION_GITHUB_TOKEN`, with `PLUGIN_UPDATE_GITHUB_TOKEN` retained as a fallback.
* Hardened saved and legacy form data normalization and limited iframe URLs to http and https.
* Added invalid URL feedback on the settings form.

= 1.0.7 =
* Updated plugin metadata for WordPress compatibility through 7.0.

= 1.0.6 =
* Restored compatibility for the previous settings page URL and saved form option data.

= 1.0.5 =
* Removed legacy organization-specific references from plugin internals and metadata.

= 1.0.4 =
* Hardened request handling, option reads, and shortcode CSS value validation.

= 1.0.3 =
* Added GitHub-based automatic update checks with Plugin Update Checker.
* Added branch-only update detection for GitHub main branch updates.
* Added optional GitHub token support through the `PLUGIN_UPDATE_GITHUB_TOKEN` constant or environment variable.
* Updated plugin metadata for WordPress compatibility through 6.9.4.

= 1.0.2 =
* Previous release.
