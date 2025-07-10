=== BtW Importer ===
Contributors: silversh  
Donate link: https://paypal.me/StoreDot2  
Tags: blogger, blogspot, blogger importer, blogspot importer, import blogspot  
Requires at least: 6.8  
Tested up to: 6.8  
Stable tag: 1.1.1  
Requires PHP: 7.4  
License: MIT  
License URI: https://github.com/mnasikin/btw-importer/blob/main/LICENSE  

BtW Importer migrates your Blogger/Blogspot content to WordPress with a single click using your .atom file.

== Description ==
BtW Importer is a powerful yet simple migration tool that helps you seamlessly transfer your content from Blogger (Blogspot) to WordPress with minimal effort. Whether you're a casual blogger or managing a large archive, this plugin handles the complex parts so you don’t have to.

With just one click, BtW Importer lets you upload your .atom file from Google Takeout and automatically imports your posts—images, links, formatting, and more. It also enhances your content by downloading embedded images, replacing Blogger URLs with WordPress-friendly links, and setting featured images based on the first image in each post. Plus, you’ll get real-time progress feedback so you can watch the migration unfold with confidence.

Designed to be fast, reliable, and compatible with WordPress 6.8+, this plugin streamlines the process and saves you hours of manual work.

* Scans and downloads embedded images  
* Replaces outdated URLs  
* Sets featured images from the first post image  
* Shows live progress during import  

Supports image formats: jpg, jpeg, png, gif, webp, bmp, svg, tiff, avif, ico.

To get your `.atom` file:
Blogger → Settings → Back Up → Download → Redirects to Google Takeout

== Requirements ==
* PHP 7.2 or later  
* cURL PHP Extension  
* `allow_url_fopen` enabled  
* Writable `wp-content/uploads` folder (default setting already meets this)

== Installation ==
1. Upload the plugin files to `/wp-content/plugins/btw-importer`, or install via the WordPress plugin screen directly.  
2. Activate the plugin via the **Plugins** screen in WordPress.  
3. Open the **BtW Importer** menu from your dashboard.

== Screenshots ==
1. Preview of the import process interface

== Usage ==
1. Download your Blogger `.atom` file from Google Takeout  
2. Open the **BtW Importer** menu in WordPress  
3. Upload the `.atom` file from your local storage  
4. Click **Start Import**  
5. Monitor the live progress  
6. Done! Your Blogger content is now available in WordPress

== Changelog ==
= 1.1.1 =
* Add Updater, so you won't miss an update
* Fix embed content or iframe not imported
= 1.1.0 – 2025-07-10 =
* Fix Pages imported as Posts. Should now correctly import pages as WordPress Pages
= 1.0.0 – 2025-07-08 =
* Initial release  
* Replaced `parse_url()` with `wp_parse_url()`  
* Used `wp_delete_file()` instead of `unlink()`  
* Sanitized input using `wp_unslash()`  
* Sanitized content with `wp_kses_post()`

== Upgrade Notice ==
= 1.1.1 =
Add Updater, so you won't miss an update and Fix embed content or iframe not imported
= 1.1.0 =
Fix Pages imported as Posts. Should now correctly import pages as WordPress Pages
= 1.0.0 =
Initial release of BtW Importer with basic Blogger migration features.
