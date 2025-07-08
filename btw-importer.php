<?php
/*
Plugin Name:        BtW Importer
Plugin URI:         https://github.com/mnasikin/btw-importer
Description:        BtW Importer (Blogger/Blogspot to WordPress Importer) is a simple but powerful blogger importer allow you to Import Blogger .atom file from Google takeout, scan & download image, replace URLs, set featured image based on firs image, show live progress.
Version:            1.0.0
Author:             Nasikin
License:            MIT
Domain Path:        /languages
Text Domain:        btw-importer
Network:            true
Requires PHP:       7.2
GitHub Plugin URI:  https://github.com/mnasikin/btw-importer
Primary Branch:     main
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class BTW_Importer {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_btw_prepare_import', array( $this, 'ajax_prepare_import' ) );
        add_action( 'wp_ajax_btw_import_single_post', array( $this, 'ajax_import_single_post' ) );
    }

    public function add_menu() {
        add_menu_page(
            'BtW Importer',
            'BtW Importer',
            'manage_options',
            'btw-importer',
            array( $this, 'import_page' ),
            'dashicons-upload'
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'toplevel_page_btw-importer' ) return;
        wp_enqueue_script( 'btw-importer', plugin_dir_url( __FILE__ ) . 'btw-importer.js', array( 'jquery' ), '1.0', true );
        wp_localize_script( 'btw-importer', 'btwImporter', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'btw_importer_nonce' )
        ));
    }

    public function import_page() {
        echo '<div class="wrap">
            <h1>BtW Importer</h1>
            <p>Supported file: .atom, .xml</p>
            <input type="file" id="atomFile" accept=".xml,.atom" />
            <button id="startImport" class="button button-primary">Start Import</button>
            <div id="progress" style="margin-top:20px; max-height:400px; overflow:auto; background:#fff; padding:10px; border:1px solid #ddd;"></div>
        </div>';
    }

    public function ajax_prepare_import() {
        check_ajax_referer( 'btw_importer_nonce', 'nonce' );
        $atom_content = isset( $_POST['atom_content'] ) ? wp_unslash( $_POST['atom_content'] ) : '';
        if ( empty( $atom_content ) ) { wp_send_json_error( 'No data received.' ); }

        if ( ! class_exists( 'SimplePie' ) ) {
            require_once ABSPATH . WPINC . '/class-simplepie.php';
        }

        $feed = new SimplePie();
        $feed->set_raw_data( $atom_content );
        $feed->enable_cache( false );
        $feed->init();

        $items = $feed->get_items() ?: array();

        $posts = array();
        foreach ( $items as $item ) {
            $posts[] = array(
                'title'   => $item->get_title(),
                'content' => $item->get_content(),
                'date'    => $item->get_date( 'Y-m-d H:i:s' ),
                'author'  => $item->get_author() ? $item->get_author()->get_name() : ''
            );
        }
        wp_send_json_success( array( 'posts' => $posts ) );
    }

    public function ajax_import_single_post() {
        check_ajax_referer( 'btw_importer_nonce', 'nonce' );

        $post = $_POST['post'] ?? array();
        if ( empty( $post ) ) wp_send_json_error( 'Missing post data.' );

        $title = sanitize_text_field( $post['title'] );
        $content_raw = $post['content'];
        $content = html_entity_decode( $content_raw );
        $date = sanitize_text_field( $post['date'] );
        $author_name = sanitize_text_field( $post['author'] );

        $msgs = array( '📄 Importing post: '.esc_html($title) );

        $author_id = 1;
        if ( $author_name ) {
            $user = get_user_by( 'login', sanitize_user( $author_name, true ) );
            if ( $user ) $author_id = $user->ID;
        }

        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';

        // Step 1: insert post first with raw content
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_date'    => $date,
            'post_author'  => $author_id
        ]);

        if ( is_wp_error( $post_id ) ) wp_send_json_error('❌ Failed to insert: '.$title);

        // Step 2: find unique image URLs by common extensions
        preg_match_all('/https?:\/\/[^"\']+\.(jpg|jpeg|png|gif|webp|bmp|svg|tiff|avif|ico)/i', $content, $matches);
        $unique_urls = array_unique($matches[0]);

        if ( ! empty( $unique_urls ) ) {
            $first_url = $unique_urls[0];
            $msgs[] = '⏳ Downloading first image: '.$first_url;
            $tmp = download_url( $first_url );
            if ( is_wp_error($tmp) ) {
                $msgs[]='⚠ Failed to download first image';
            } else {
                $desc = basename( parse_url( $first_url, PHP_URL_PATH ) ) ?: 'image.jpg';
                $file = ['name'=>$desc,'tmp_name'=>$tmp];
                $media_id = media_handle_sideload($file,$post_id); // attach to post

                if ( is_wp_error($media_id) ) {
                    @unlink($tmp);
                    $msgs[]='⚠ Failed to attach first image';
                } else {
                    $new_url = wp_get_attachment_url($media_id);
                    if ($new_url) {
                        foreach($unique_urls as $old_url) {
                            $content = str_replace($old_url, $new_url, $content);
                            $msgs[] = '✅ Replaced: '.$old_url.' → '.$new_url;
                        }
                        set_post_thumbnail($post_id,$media_id);
                        $msgs[]='⭐ First image set as featured';
                    }
                }
            }
        } else {
            $msgs[]='⚠ No image URLs found in content';
        }

        // Step 3: update post content if changed
        wp_update_post(['ID'=>$post_id,'post_content'=>$content]);

        $msgs[]='✅ Finished post: '.$title;
        wp_send_json_success($msgs);
    }
}
new BTW_Importer();
