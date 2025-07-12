<?php
if (!defined('ABSPATH')) exit;

class BTW_Importer_Redirect_Log {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_redirect_log_menu']);
        add_action('admin_init', [$this, 'handle_clear_log']);
    }

    public function add_redirect_log_menu() {
        add_submenu_page(
            'btw-importer',
            'Redirect Log',
            'Redirect Log',
            'manage_options',
            'btw-redirect-log',
            [$this, 'render_redirect_log_page']
        );
    }

    public function handle_clear_log() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['btw_clear_log_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['btw_clear_log_nonce'])), 'btw_clear_log')) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_old_permalink'
            ));
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Redirect log cleared successfully.', 'btw-importer') . '</p></div>';
            });
        }
    }

    public function render_redirect_log_page() {
    global $wpdb;

    // Get and sanitize inputs
    $search   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $orderby  = isset($_GET['orderby']) ? sanitize_sql_orderby(wp_unslash($_GET['orderby'])) : 'p.post_date';
    $order    = (isset($_GET['order']) && strtoupper(wp_unslash($_GET['order'])) === 'ASC') ? 'ASC' : 'DESC';

    $allowed_orderby = ['p.post_date', 'p.post_type'];
    if (!in_array($orderby, $allowed_orderby, true)) $orderby = 'p.post_date';

    $per_page = 25;
    $offset   = ($paged - 1) * $per_page;

    echo '<div class="wrap">';
    echo '<h1>Redirect Log</h1>';
    echo '<p>This table shows old Blogger slugs and the new WordPress URLs that have been created as redirects.</p>';

    $clear_nonce = wp_create_nonce('btw_clear_log');

    // Search + clear form
    echo '<form method="get" style="margin-bottom:10px; display:inline-block; margin-right:10px;">
            <input type="hidden" name="page" value="btw-redirect-log" />
            <input type="search" name="s" placeholder="Search slug..." value="' . esc_attr($search) . '" />
            <input type="submit" class="button" value="Search" />
          </form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Are you sure you want to clear the entire redirect log?\');">
            <input type="hidden" name="btw_clear_log_nonce" value="' . esc_attr($clear_nonce) . '" />
            <input type="submit" class="button button-danger" value="Clear Log" />
          </form>';

    // Query
    $sql = "
        SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_type, p.post_date, pm.meta_value as old_slug
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = %s
    ";
    $params = ['_old_permalink'];

    if ($search) {
        $sql .= " AND pm.meta_value LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }

    $sql .= " ORDER BY $orderby $order LIMIT %d OFFSET %d";
    $params[] = $per_page;
    $params[] = $offset;

    $params = ['_old_permalink'];
    $wheres = ['pm.meta_key = %s'];
    
    if ($search) {
        $wheres[] = "pm.meta_value LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    
    $where_sql = implode(' AND ', $wheres);
    
    $query_template = "
        SELECT SQL_CALC_FOUND_ROWS p.ID, p.post_type, p.post_date, pm.meta_value as old_slug
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE $where_sql
        ORDER BY $orderby $order
        LIMIT %d OFFSET %d
    ";
    
    $results = $wpdb->get_results(
        $wpdb->prepare($query_template, array_merge($params, [$per_page, $offset]))
    );
    
    $total_items = (int) $wpdb->get_var("SELECT FOUND_ROWS()");


    if (!$results) {
        echo '<p>No redirects found.</p>';
    } else {
        // Sortable headers
        $base_url = admin_url('admin.php?page=btw-redirect-log');
        if ($search) $base_url = add_query_arg('s', urlencode($search), $base_url);

        $columns = [
            'p.post_date' => 'Date',
            'p.post_type' => 'Post Type'
        ];

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th width="45%">Old URL</th>';
        echo '<th>New URL</th>';
        foreach ($columns as $col => $label) {
            $new_order = ($orderby === $col && $order === 'ASC') ? 'DESC' : 'ASC';
            $link = add_query_arg(['orderby' => $col, 'order' => $new_order, 'paged' => 1], $base_url);
            $arrow = ($orderby === $col) ? ($order === 'ASC' ? '↑' : '↓') : '';
            echo '<th><a href="' . esc_url($link) . '">' . esc_html($label) . ' ' . esc_html($arrow) . '</a></th>';
        }
        echo '</tr></thead>';

        echo '<tbody>';
        foreach ($results as $row) {
            $old_url = esc_url(home_url($row->old_slug));
            $new_url = esc_url(get_permalink($row->ID));
            $date    = esc_html(gmdate('Y-m-d', strtotime($row->post_date)));
            $type    = esc_html($row->post_type);

            echo '<tr>';
            echo '<td><a href="' . esc_url($old_url) . '" target="_blank">' . esc_url($old_url) . '</a></td>';
            echo '<td><a href="' . esc_url($new_url) . '" target="_blank">' . esc_url($new_url) . '</a></td>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';

        // Pagination
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            $pagination = paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'add_args'  => [
                    's'       => $search,
                    'orderby' => $orderby,
                    'order'   => $order,
                ],
                'prev_text' => esc_html__('« Prev', 'btw-importer'),
                'next_text' => esc_html__('Next »', 'btw-importer'),
            ]);
            if ( $pagination ) {
                echo wp_kses_post( $pagination );
    }
            echo '</div></div>';
        }
    }

    echo '</div>';
}

}

new BTW_Importer_Redirect_Log();
