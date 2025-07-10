<?php
/*
Plugin Name:        BtW Importer
Plugin URI:         https://github.com/mnasikin/btw-importer
Description:        Simple yet powerful plugin to Migrate Blogger to WordPress in one click. Import .atom from Google Takeout and the plugin will scan & download first image, replace URLs, set featured image, show live progress.
Version:            1.1.1
Author:             Nasikin
License:            MIT
Domain Path:        /languages
Text Domain:        btw-importer
Network:            true
Requires PHP:       7.4
GitHub Plugin URI:  https://github.com/mnasikin/btw-importer
Primary Branch:     main
*/

if ( ! defined( 'ABSPATH' ) ) exit;

require 'updater/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/mnasikin/btw-importer/',
    __FILE__,
    'btw-importer'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

//Optional: If you're using a private repository, specify the access token like this:
$myUpdateChecker->setAuthentication('');

class BTW_Importer {
    private $downloaded_images = []; // cache to avoid duplicate downloads

    public function __construct() {
        add_action( 'admin_menu', [$this, 'add_menu'] );
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );
        add_action( 'wp_ajax_btw_prepare_import', [$this, 'ajax_prepare_import'] );
        add_action( 'wp_ajax_btw_import_single_post', [$this, 'ajax_import_single_post'] );
    }

    public function add_menu() {
        add_menu_page(
            'BtW Importer',
            'BtW Importer',
            'manage_options',
            'btw-importer',
            [$this, 'import_page'],
            'dashicons-upload'
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'toplevel_page_btw-importer' ) return;
        wp_enqueue_script( 'btw-importer', plugin_dir_url(__FILE__).'btw-importer.js', ['jquery'], '1.5', true );
        wp_localize_script( 'btw-importer', 'btwImporter', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'btw_importer_nonce' )
        ]);
    }

    public function import_page() {
        echo '<div class="wrap">
            <h1>BtW Import Blogger .atom</h1>
            <input type="file" id="atomFile" accept=".xml,.atom" />
            <button id="startImport" class="button button-primary">Start Import</button>
            <div id="progress" style="margin-top:20px; max-height:400px; overflow:auto; background:#fff; padding:10px; border:1px solid #ddd;"></div>
        </div>';
    }

    public function ajax_prepare_import() {
    check_ajax_referer( 'btw_importer_nonce', 'nonce' );
    $atom_content = isset( $_POST['atom_content'] ) ? wp_unslash( $_POST['atom_content'] ) : '';
    if ( empty( $atom_content ) ) { wp_send_json_error( 'No data received.' ); }

    $posts = [];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string( $atom_content );
    if (!$xml) {
        wp_send_json_error('Failed to parse XML.');
    }

    // Blogger feeds use namespace, so register it
    $ns = $xml->getNamespaces(true);

    foreach ( $xml->entry as $entry ) {
        // Default type
        $post_type = 'post';

        // Read <blogger:type> tag
        $bloggerType = (string) $entry->children('blogger', true)->type;
        if ( strtoupper($bloggerType) === 'PAGE' ) {
            $post_type = 'page';
        }

        // Title
        $title = sanitize_text_field( (string) $entry->title );

        // Content
        $content = (string) $entry->content;

        // Author
        $author = isset($entry->author->name) ? sanitize_text_field( (string) $entry->author->name ) : '';

        // Date (published)
        $date = isset($entry->published) ? sanitize_text_field( (string) $entry->published ) : current_time('mysql');

        $posts[] = [
            'title'     => $title,
            'content'   => $content,
            'date'      => $date,
            'author'    => $author,
            'post_type' => $post_type
        ];
    }

    wp_send_json_success( [ 'posts' => $posts ] );
}


    public function ajax_import_single_post() {
        check_ajax_referer( 'btw_importer_nonce', 'nonce' );
        $raw_post = isset($_POST['post']) ? wp_unslash($_POST['post']) : [];
        if ( empty($raw_post) ) wp_send_json_error('Missing post data.');
        $title     = sanitize_text_field($raw_post['title'] ?? '');
        $allowed_tags = wp_kses_allowed_html( 'post' );
        $allowed_tags['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'class'           => true,
            'youtube-src-id'  => true,
        );
        $content_raw = $raw_post['content'] ?? '';
        $content = wp_kses( $content_raw, $allowed_tags );

        $date      = sanitize_text_field($raw_post['date'] ?? '');
        $author    = sanitize_text_field($raw_post['author'] ?? '');
        $post_type = in_array($raw_post['post_type'], ['post','page']) ? $raw_post['post_type'] : 'post';

        $msgs = ['📄 Importing '.ucfirst($post_type).': '.$title];

        $author_id = 1;
        if ( $author ) {
            $user = get_user_by('login', sanitize_user($author, true));
            if ( $user ) $author_id = $user->ID;
        }

        // needed for media_handle_sideload
        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';

        // insert post first
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_date'    => $date,
            'post_author'  => $author_id,
            'post_type'    => $post_type
        ]);
        if ( is_wp_error($post_id) ) wp_send_json_error('❌ Failed to insert: '.$title);

        // find unique images
        preg_match_all('/https?:\/\/[^"\']+\.(jpg|jpeg|png|gif|webp|bmp|svg|tiff|avif|ico)/i', $content, $matches);
        $unique_urls = array_unique($matches[0]);

        $first_media_id = null;
        foreach ( $unique_urls as $img_url ) {
            if ( isset($this->downloaded_images[$img_url]) ) {
                $new_url = $this->downloaded_images[$img_url];
                $content = str_replace($img_url, $new_url, $content);
                $msgs[]='✅ Used cached: '.$new_url;
                continue;
            }
            $msgs[]='⏳ Downloading image: '.$img_url;
            $tmp = download_url($img_url);
            if ( is_wp_error($tmp) ) { $msgs[]='⚠ Failed to download'; continue; }
            $parsed = wp_parse_url($img_url);
            $desc = !empty($parsed['path']) ? basename($parsed['path']) : 'image.jpg';
            $file = ['name'=>$desc,'tmp_name'=>$tmp];
            $media_id = media_handle_sideload($file,$post_id);
            if ( is_wp_error($media_id) ) { wp_delete_file($tmp); $msgs[]='⚠ Failed to attach'; continue; }
            $new_url = wp_get_attachment_url($media_id);
            if ($new_url) {
                $this->downloaded_images[$img_url] = $new_url;
                $content = str_replace($img_url, $new_url, $content);
                $msgs[]='✅ Replaced: '.$img_url.' → '.$new_url;
                if (!$first_media_id) $first_media_id = $media_id;
            }
        }

        // update content and set featured image
        wp_update_post(['ID'=>$post_id,'post_content'=>$content]);
        if ( $first_media_id ) {
            set_post_thumbnail($post_id, $first_media_id);
            $msgs[]='⭐ First image set as featured';
        }

        $msgs[]='✅ Finished '.$post_type.': '.$title;
        wp_send_json_success($msgs);
    }
}

new BTW_Importer();
