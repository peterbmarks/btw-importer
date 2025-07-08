
# BtW Importer

**BtW Importer (Blogger/Blogspot to WordPress Importer)** is a fast, intuitive migration tool that lets you move from Blogger to WordPress with a single click. Simply upload your `.atom` file from Google Takeout, and the importer will take care of the rest. automatically scanning and downloading images, replacing outdated URLs, setting featured images from the first post image, and showing live progress every step of the way.

[![Download Plugin](https://img.shields.io/badge/download_plugin-000?style=for-the-badge&logo=download&logoColor=white)](https://github.com/mnasikin/btw-importer/archive/refs/heads/main.zip)

## Requirements
Before you begin, make sure you’ve downloaded your Blogger `.atom` file via Google Takeout:

Blogger → Settings → Back Up → Click Download (This redirects to Google Takeout where you’ll get the `.atom` file)

## Usage
1. Activate the Plugin Once installed, go to your WordPress dashboard and activate BtW Importer.
2. A new menu item called BtW Importer will appear, click it to begin.
3. Choose Your .atom File Select the Blogger .atom file from your local storage.
4. Hit the Start Import button and let the plugin do its work.
5. Track Progress in Real Time The tool displays live progress so you can monitor the migration status.
6. Done! Once the process completes, your Blogger content will be fully migrated to WordPress.

## Changelog

#### 1.0.0 / 2025-07-08
1. Initial release of BtW Importer
2. wp_parse_url() used instead of parse_url()
3. wp_delete_file() used instead unlink()
4. input properly wp_unslash() and sanitized
5. content sanitized with wp_kses_post()