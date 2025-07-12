<?php
/*
Plugin Name:        BtW Importer
Plugin URI:         https://github.com/mnasikin/btw-importer
Description:        Simple yet powerful plugin to Migrate Blogger to WordPress in one click. Import .atom from Google Takeout and the plugin will scan & download first image, replace URLs, set featured image, show live progress.
Version:            2.0.0
Author:             Nasikin
Author URI:         https://github.com/mnasikin/
License:            MIT
Domain Path:        /languages
Text Domain:        btw-importer
Network:            false
Requires PHP:       7.4
GitHub Plugin URI:  https://github.com/mnasikin/btw-importer
Primary Branch:     main
*/

// updater
if ( ! defined( 'ABSPATH' ) ) exit;

require 'updater/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/mnasikin/btw-importer/',
    __FILE__,
    'btw-importer'
);
$myUpdateChecker->getVcsApi()->enableReleaseAssets();
$myUpdateChecker->setAuthentication('');
// end updater

class BTW_Importer {
    private $downloaded_images = []; // cache

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_btw_prepare_import', [$this, 'ajax_prepare_import']);
        add_action('wp_ajax_btw_import_single_post', [$this, 'ajax_import_single_post']);
    }

    public function add_menu() {
        add_menu_page(
            'BtW Importer', 'BtW Importer', 'manage_options',
            'btw-importer', [$this, 'import_page'], 'dashicons-upload'
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_btw-importer') return;
        wp_enqueue_script('btw-importer', plugin_dir_url(__FILE__).'btw-importer.js', ['jquery'], '1.2.2', true);
        wp_localize_script('btw-importer', 'btwImporter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('btw_importer_nonce')
        ]);
    }

    public function import_page() {
        echo '<div class="wrap">
            <h1>BtW Importer</h1>
            <p>A powerful yet simple migration tool, BtW Importer helps you seamlessly transfer posts, images, and formatting from Blogger (Blogspot) to WordPress. Don&apos;t forget to share this plugin if you found it&apos;s usefull</p>
            <div id="importNotice" style="margin:20px;">
            <h2>⚠️ Please Read Before Importing ⚠️</h2>
            <ul>
                <li>🛑 ️This plugin doesn&apos;t overwrite existing posts with the same name. If you&apos;ve previously used an importer, it&apos;s recommended to manually delete the previously imported content.</li>
                <li>🛑 301 redirects only work if you previously used a custom domain on Blogspot and you&apos;re moving that domain to WordPress.</li>
                <li>🛑 Make sure not to leave this page while the process is underway, or the import will stop, and you&apos;ll need to start from the beginning.</li>
                <li>🛑 301 redirects work if this plugin is active and you have already run the importer.</li>
                <li>🛑 Only image from Google/Blogspot will be downloaded.</li>
                <li>🛑 Be sure to manually check your content after the import process is complete.</li>
            </ul>
              <input type="checkbox" id="agreeNotice">
              <label for="agreeNotice">
                I&apos;ve read all of them and I want to start the importer.
              </label>
            </div>
            <input type="file" id="atomFile" accept=".xml,.atom" />
            <button id="startImport" class="button button-primary" disabled>Start Import</button><br>
            <label for="atomFile">Accepted File: .xml,.atom</label>
            <hr>
            <div id="importOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); color: #fff; font-size: 20px; z-index: 9999; text-align: center; padding-top: 20%;">
                ⚠ Import in progress... Please don’t close, reload, or navigate away.
            </div>
            <div id="progress" style="margin-top:20px; max-height:100vh; max-width;100%; overflow:auto; background:#fff; padding:10px; border:1px solid #ddd;"></div>
        </div>';
    }

    public function ajax_prepare_import() {
        check_ajax_referer('btw_importer_nonce', 'nonce');
        $atom_content = isset($_POST['atom_content']) ? wp_unslash($_POST['atom_content']) : '';
        if (!$atom_content) wp_send_json_error('No data received.');

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($atom_content);
        if (!$xml) wp_send_json_error('Failed to parse XML.');

        $posts = [];
        foreach ($xml->entry as $entry) {
            $bloggerType = strtolower((string)$entry->children('blogger', true)->type);
            $post_type = ($bloggerType === 'page') ? 'page' : 'post';

            $title = sanitize_text_field((string)$entry->title);
            $content = (string)$entry->content;
            $author = isset($entry->author->name) ? sanitize_text_field((string)$entry->author->name) : '';

            $published_raw = (string)$entry->published;
            $date_gmt = gmdate('Y-m-d H:i:s', strtotime($published_raw));
            $date_local = get_date_from_gmt($date_gmt, 'Y-m-d H:i:s');

            // get categories
            $categories = [];
            foreach ($entry->category as $cat) {
                $term = (string)$cat['term'];
                if ($term && strpos($term, '#') !== 0) {
                    $categories[] = sanitize_text_field($term);
                }
            }

            // get old permalink from <blogger:filename>
            $filename = (string)$entry->children('blogger', true)->filename;
            $filename = trim($filename);

            $posts[] = [
                'title'      => $title,
                'content'    => $content,
                'author'     => $author,
                'post_type'  => $post_type,
                'date'       => $date_local,
                'date_gmt'   => $date_gmt,
                'categories' => $categories,
                'filename'   => $filename
            ];
        }

        wp_send_json_success(['posts' => $posts]);
    }

    public function ajax_import_single_post() {
        check_ajax_referer('btw_importer_nonce', 'nonce');
        $raw_post = isset($_POST['post']) ? $_POST['post'] : [];
        if (!$raw_post) wp_send_json_error('Missing post data.');

        $title = sanitize_text_field($raw_post['title'] ?? '');
        $author = sanitize_text_field($raw_post['author'] ?? '');
        $post_type = in_array($raw_post['post_type'], ['post','page']) ? $raw_post['post_type'] : 'post';
        $date = sanitize_text_field($raw_post['date'] ?? '');
        $date_gmt = sanitize_text_field($raw_post['date_gmt'] ?? '');
        $categories = $raw_post['categories'] ?? [];
        $filename = sanitize_text_field($raw_post['filename'] ?? '');
        $allowed_tags = wp_kses_allowed_html('post');
        $allowed_tags['iframe'] = ['src'=>true,'width'=>true,'height'=>true,'frameborder'=>true,'allowfullscreen'=>true,'class'=>true,'youtube-src-id'=>true];
        $content = wp_kses($raw_post['content'] ?? '', $allowed_tags);

        $msgs = [];

        $author_id = 1;
        if ($author) {
            $user = get_user_by('login', sanitize_user($author, true));
            if ($user) $author_id = $user->ID;
        }

        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';

        $post_id = wp_insert_post([
            'post_title'    => $title,
            'post_content'  => $content,
            'post_status'   => 'publish',
            'post_date'     => $date,
            'post_date_gmt' => $date_gmt,
            'post_author'   => $author_id,
            'post_type'     => $post_type
        ]);

        if (is_wp_error($post_id)) wp_send_json_error('❌ Failed to insert: '.$title);

        // add redirect meta & log redirect creation
        if ($filename) {
            if ($filename[0] !== '/') $filename = '/' . $filename;
            add_post_meta($post_id, '_old_permalink', $filename, true);
            $new_url = get_permalink($post_id);
            $msgs[] = '✅ Finished create 301 redirect: '.$filename.' → '.$new_url;
        }

        // create categories
        if (!empty($categories) && $post_type === 'post') {
            $cat_ids = [];
            foreach ($categories as $cat_name) {
                $term = term_exists($cat_name, 'category');
                if (!$term) {
                    $new_term = wp_create_category($cat_name);
                    if (!is_wp_error($new_term)) {
                        $cat_ids[] = $new_term;
                        $msgs[] = '✅ Created category: '.$cat_name;
                    }
                } else {
                    $cat_ids[] = $term['term_id'];
                    $msgs[] = '✅ Using category: '.$cat_name;
                }
            }
            if (!empty($cat_ids)) wp_set_post_categories($post_id, $cat_ids);
        }

        // find unique blogger/googleusercontent images by basename (after /sXXX/)
        preg_match_all('/https?:\/\/[^"\']+\.(jpg|jpeg|png|gif|webp|bmp|svg)/i', $content, $matches);
        $image_by_basename = [];
        foreach (array_unique($matches[0]) as $img_url) {
            if (!preg_match('/(blogspot|googleusercontent)/i', $img_url)) continue;

            if (preg_match('#/s\d+/(.+)$#', $img_url, $m)) {
                $basename = $m[1];
            } else {
                $basename = basename(parse_url($img_url, PHP_URL_PATH));
            }

            if (!isset($image_by_basename[$basename])) {
                $image_by_basename[$basename] = $img_url;
            } else {
                // prefer bigger /sXXX/ number
                if (preg_match('#/s(\d+)/#', $img_url, $m1) && preg_match('#/s(\d+)/#', $image_by_basename[$basename], $m2)) {
                    if ((int)$m1[1] > (int)$m2[1]) {
                        $image_by_basename[$basename] = $img_url;
                    }
                }
            }
        }

        $first_media_id = null;
        foreach ($image_by_basename as $img_url) {
            if (isset($this->downloaded_images[$img_url])) {
                $new_url = $this->downloaded_images[$img_url];
                $content = str_replace($img_url, $new_url, $content);
                $msgs[]='✅ Used cached: '.$new_url;
                continue;
            }

            $msgs[]='⏳ Downloading: '.$img_url;
            $tmp = download_url($img_url);
            if (is_wp_error($tmp)) { $msgs[]='⚠ Failed to download'; continue; }

            $file = ['name'=>basename(parse_url($img_url, PHP_URL_PATH)),'tmp_name'=>$tmp];
            $media_id = media_handle_sideload($file,$post_id);
            if (is_wp_error($media_id)) { wp_delete_file($tmp); $msgs[]='⚠ Failed to attach'; continue; }

            $new_url = wp_get_attachment_url($media_id);
            if ($new_url) {
                $this->downloaded_images[$img_url] = $new_url;
                $content = str_replace($img_url, $new_url, $content);
                $msgs[]='✅ Replaced: '.$img_url.' → '.$new_url;
                if (!$first_media_id) $first_media_id = $media_id;
            }
        }

        wp_update_post(['ID'=>$post_id,'post_content'=>$content]);
        if ($first_media_id) {
            set_post_thumbnail($post_id, $first_media_id);
            $msgs[]='⭐ Successfully Set featured image';
        }

        $msgs[]='✅ Finished '.$post_type.': '.$title;
        wp_send_json_success($msgs);
    }
}

new BTW_Importer();
require_once plugin_dir_path(__FILE__) . 'redirect.php';
require_once plugin_dir_path(__FILE__) . 'redirect-log.php';
