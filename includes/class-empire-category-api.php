<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Empire_Category_API {

    private static $api_url = 'https://empire.dayzsolutions.nl/category-api';
    private static $api_token = '1969a86944e633bbac66bb64761a15b17ef9e34cee7461968a6a8e30d0afadc5';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
        add_action( 'wp_ajax_empire_category_sync_step', [ __CLASS__, 'ajax_sync_step' ] );
        add_action( 'admin_post_empire_category_export', [ __CLASS__, 'handle_export' ] );
        add_action( 'admin_post_empire_category_import', [ __CLASS__, 'handle_import' ] );
    }

    public static function add_admin_page() {
        add_submenu_page(
            'empire-product-api',
            'Category API',
            'Category Sync',
            'manage_options',
            'empire-category-api',
            [ __CLASS__, 'admin_page_content' ]
        );
    }

    public static function admin_page_content() {
        ?>
        <style>
            .empire-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); max-width: 800px; }
            .empire-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 15px; }
            .empire-header h2 { margin: 0; }
            .empire-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
            .empire-stat-box { background: #f8f9fa; padding: 15px; text-align: center; border-radius: 4px; border: 1px solid #ddd; }
            .empire-stat-value { font-size: 24px; font-weight: bold; color: #2271b1; }
            .empire-stat-label { font-size: 12px; color: #666; text-transform: uppercase; margin-top: 5px; }
            
            .empire-progress-container { background: #f1f1f1; border-radius: 4px; height: 30px; margin-bottom: 20px; display: none; overflow: hidden; position: relative; }
            .empire-progress-bar { height: 100%; background: #2271b1; width: 0%; transition: width 0.5s ease; }
            .empire-progress-text { position: absolute; width: 100%; text-align: center; line-height: 30px; color: #fff; font-weight: bold; text-shadow: 0 0 2px rgba(0,0,0,0.5); font-size: 12px; top: 0; }

            .empire-log-window { background: #1d2327; color: #50fa7b; font-family: 'Consolas', 'Monaco', monospace; padding: 15px; height: 300px; overflow-y: auto; border-radius: 4px; font-size: 12px; line-height: 1.5; white-space: pre-wrap; display: none; }
            .empire-log-window .log-info { color: #8be9fd; }
            .empire-log-window .log-success { color: #50fa7b; }
            .empire-log-window .log-warning { color: #ffb86c; }
            .empire-log-window .log-error { color: #ff5555; }
            
            .button-hero-custom { padding: 10px 30px !important; font-size: 16px !important; height: auto !important; }
        </style>

        <div class="wrap">
            <h1>Empire Category API Sync</h1>
            
            <div class="empire-card">
                <div class="empire-header">
                    <h2>Manual Sync</h2>
                    <div class="header-actions">
                        <button id="cat-start-sync-btn" class="button button-primary button-hero-custom">Start Sync</button>
                        <button id="cat-stop-sync-btn" class="button button-secondary button-hero-custom" style="display:none;">Stop</button>
                    </div>
                </div>

                <div class="empire-stats-grid">
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="cat-stat-created">0</div>
                        <div class="empire-stat-label">Created</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="cat-stat-updated">0</div>
                        <div class="empire-stat-label">Updated</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="cat-stat-skipped">0</div>
                        <div class="empire-stat-label">Skipped</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="cat-stat-page">0</div>
                        <div class="empire-stat-label">Current Page</div>
                    </div>
                </div>

                <div id="cat-progress-container" class="empire-progress-container">
                    <div id="cat-progress-bar" class="empire-progress-bar"></div>
                    <div id="cat-progress-text" class="empire-progress-text">Initializing...</div>
                </div>

                <div id="cat-log-window" class="empire-log-window"></div>
            </div>

            <div class="empire-card">
                <div class="empire-header">
                    <h2>Export & Import</h2>
                </div>
                <div style="display: flex; gap: 20px; align-items: flex-start;">
                    <div style="flex: 1; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3>Export Categories</h3>
                        <p>Download all categories (with ACF data) as a CSV file.</p>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                            <input type="hidden" name="action" value="empire_category_export">
                            <?php wp_nonce_field('empire_cat_export', 'empire_cat_export_nonce'); ?>
                            <button type="submit" class="button button-secondary">Download CSV Export</button>
                        </form>
                    </div>

                    <div style="flex: 1; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3>Import Categories</h3>
                        <p>Upload a CSV file to update or create categories.</p>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="empire_category_import">
                            <?php wp_nonce_field('empire_cat_import', 'empire_cat_import_nonce'); ?>
                            <input type="file" name="import_csv" accept=".csv" required style="margin-bottom: 10px; display: block;">
                            <button type="submit" class="button button-primary">Upload & Import CSV</button>
                        </form>
                    </div>
                </div>
                <?php if (isset($_GET['import_done'])): ?>
                    <div class="notice notice-success is-dismissible" style="margin-top: 20px;">
                        <p>Import processed: <?php echo absint($_GET['created']); ?> created, <?php echo absint($_GET['updated']); ?> updated.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let isSyncing = false;
            let totalCreated = 0;
            let totalUpdated = 0;
            let totalSkipped = 0;

            function log(message, type = 'info') {
                const timestamp = new Date().toLocaleTimeString();
                const $logWindow = $('#cat-log-window');
                $logWindow.append(`<div class="log-${type}">[${timestamp}] ${message}</div>`);
                $logWindow.scrollTop($logWindow[0].scrollHeight);
            }

            $('#cat-start-sync-btn').on('click', function() {
                isSyncing = true;
                totalCreated = 0;
                totalUpdated = 0;
                totalSkipped = 0;
                
                $('#cat-stat-created').text('0');
                $('#cat-stat-updated').text('0');
                $('#cat-stat-skipped').text('0');
                $('#cat-stat-page').text('0');

                $(this).hide();
                $('#cat-stop-sync-btn').show();
                
                $('#cat-progress-container').show();
                $('#cat-log-window').show().html('');
                $('#cat-progress-bar').css('width', '5%');
                $('#cat-progress-text').text('Starting category sync...');

                log('Starting category synchronization process...');
                processPage(1, 0);
            });

            $('#cat-stop-sync-btn').on('click', function() {
                isSyncing = false;
                $(this).hide();
                $('#cat-start-sync-btn').show();
                $('#cat-progress-text').text('Sync stopped manually');
                $('#cat-progress-bar').css('background', '#dc3232');
                log('Sync stopped by user.', 'warning');
            });

            function processPage(page, indexOffset) {
                if (!isSyncing) return;

                $('#cat-stat-page').text(page);
                $('#cat-progress-text').text(`Fetching page ${page} (offset: ${indexOffset})...`);
                log(`Processing page ${page}, starting at index ${indexOffset}...`);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'empire_category_sync_step',
                        page: page,
                        index_offset: indexOffset
                    },
                    success: function(response) {
                        if (!isSyncing) return;

                        if (response.success) {
                            const data = response.data;
                            
                            totalCreated += data.created || 0;
                            totalUpdated += data.updated || 0;
                            totalSkipped += data.skipped || 0;

                            $('#cat-stat-created').text(totalCreated);
                            $('#cat-stat-updated').text(totalUpdated);
                            $('#cat-stat-skipped').text(totalSkipped);

                            log(`Batch processed: ${data.processed} items (New: ${data.created}, Updated: ${data.updated}, Skipped: ${data.skipped})`, 'info');

                            if (data.has_more) {
                                processPage(data.next_page, data.next_index);
                            } else {
                                $('#cat-progress-bar').css('width', '100%').css('background', '#46b450');
                                $('#cat-progress-text').text('Sync Completed Successfully!');
                                log('Sync process completed!', 'success');
                                
                                $('#cat-stop-sync-btn').hide();
                                $('#cat-start-sync-btn').show().text('Run Sync Again');
                                isSyncing = false;
                            }
                        } else {
                            const errorMsg = response.data || 'Unknown error occurred';
                            $('#cat-progress-bar').css('background', '#dc3232');
                            $('#cat-progress-text').text('Error: ' + errorMsg);
                            log('AJAX Error: ' + errorMsg, 'error');
                            $('#cat-stop-sync-btn').click();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#cat-progress-bar').css('background', '#dc3232');
                        $('#cat-progress-text').text('Network Error: ' + error);
                        log('Network Error: ' + error, 'error');
                        $('#cat-stop-sync-btn').click();
                    }
                });
            }
        });
        </script>
        <?php
    }

    public static function ajax_sync_step() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $index_offset = isset( $_POST['index_offset'] ) ? absint( $_POST['index_offset'] ) : 0;
        
        try {
            $result = self::process_batch( $page, $index_offset );
        } catch (Exception $e) {
            wp_send_json_error( $e->getMessage() );
            return;
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    private static function process_batch( $page, $start_index = 0 ) {
        $start_time = time();
        $max_execution_time = 15; 

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . self::$api_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 60,
        ];

        $paged_url = self::$api_url . '?page=' . $page;
        
        $cache_key = 'empire_category_api_page_' . $page;
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            $response = wp_remote_get( $paged_url, $args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            
            if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                set_transient( $cache_key, $data, 10 * MINUTE_IN_SECONDS );
            }
        }

        $items = [];
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            $items = $data['data'];
        }

        $count = count( $items );
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed_in_batch = 0;
        
        if ( $count > 0 ) {
            for ( $i = $start_index; $i < $count; $i++ ) {
                $item = $items[$i];
                
                if ( (time() - $start_time) > $max_execution_time ) {
                    return [
                        'processed' => $processed_in_batch,
                        'created'   => $created,
                        'updated'   => $updated,
                        'skipped'   => $skipped,
                        'has_more'  => true,
                        'next_page' => $page,
                        'next_index'=> $i,
                    ];
                }

                if ( empty( $item['slug'] ) || empty( $item['name'] ) ) {
                    $skipped++;
                    $processed_in_batch++;
                    continue;
                }

                try {
                    $res = self::create_or_update_category( $item );
                    if ( $res === 'created' ) {
                        $created++;
                    } elseif ( $res === 'updated' ) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch ( Exception $e ) {
                    $skipped++;
                }
                $processed_in_batch++;
            }
        }

        // Check if there are more pages based on meta.last_page
        $last_page = isset( $data['meta']['last_page'] ) ? intval( $data['meta']['last_page'] ) : 1;
        $has_more = ( $page < $last_page );

        return [
            'processed' => $processed_in_batch,
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'has_more'  => $has_more,
            'next_page' => $has_more ? $page + 1 : $page,
            'next_index'=> 0
        ];
    }

    private static function create_or_update_category( $item ) {
        // self::log( "Syncing category: " . ($item['slug'] ?? 'unknown'), 'info' );
        // self::log( "Full payload data: " . json_encode($item, JSON_PRETTY_PRINT), 'debug' );

        $slug = sanitize_title( $item['slug'] );
        $name = sanitize_text_field( $item['name'] );
        $description = ! empty( $item['description'] ) ? wp_kses_post( $item['description'] ) : '';
        
        // Fallback to category_meta_description if main description is empty
        if ( empty( $description ) && ! empty( $item['acf']['category_meta_description'] ) ) {
            $description = wp_kses_post( $item['acf']['category_meta_description'] );
        }

        $term = get_term_by( 'slug', $slug, 'product_cat' );

        $term_args = [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
        ];

        // Ensure parent is set if provided and exists
        if ( isset( $item['parent'] ) && $item['parent'] > 0 ) {
            // Find parent by Empire API ID
            $parent_id = $item['parent'];
            global $wpdb;
            $parent_term_id = $wpdb->get_var( $wpdb->prepare( "
                SELECT term_id FROM {$wpdb->termmeta}
                WHERE meta_key = '_empire_api_id' AND meta_value = %s
                LIMIT 1
            ", $parent_id ) );

            if ( $parent_term_id ) {
                $term_args['parent'] = intval( $parent_term_id );
            }
        } else if ( isset( $item['parent'] ) && $item['parent'] == 0 ) {
            $term_args['parent'] = 0;
        }

        $status = 'skipped';
        if ( ! $term ) {
            $term_data = wp_insert_term( $name, 'product_cat', $term_args );
            if ( ! is_wp_error( $term_data ) ) {
                $term_id = $term_data['term_id'];
                $status = 'created';
            } else {
                return 'skipped';
            }
        } else {
            $term_id = $term->term_id;
            $term_data = wp_update_term( $term_id, 'product_cat', $term_args );
            
            // Explicitly update description if update_term was subtle
            if ( ! empty( $description ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->term_taxonomy,
                    [ 'description' => $description ],
                    [ 'term_id' => $term_id, 'taxonomy' => 'product_cat' ]
                );
                clean_term_cache( $term_id, 'product_cat' );
            }

            if ( ! is_wp_error( $term_data ) ) {
                $status = 'updated';
            } else {
                return 'skipped';
            }
        }

        // Save Empire API ID for later match
        update_term_meta( $term_id, '_empire_api_id', $item['id'] );

        // Update ACF Fields
        if ( isset( $item['acf'] ) && is_array( $item['acf'] ) ) {
            foreach ( $item['acf'] as $key => $value ) {
                // For boolean fields
                if ( is_bool( $value ) ) {
                    $value = $value ? 1 : 0;
                }
                update_field( $key, $value, 'product_cat_' . $term_id );
            }
        }

        // Handle Image
        if ( ! empty( $item['image']['src'] ) ) {
            $image_url = $item['image']['src'];
            $attachment_id = self::get_or_sideload_image( $image_url );
            if ( $attachment_id ) {
                update_term_meta( $term_id, 'thumbnail_id', $attachment_id );
            }
        }

        return $status;
    }

    private static function get_or_sideload_image( $url ) {
        if ( empty( $url ) ) {
            return false;
        }

        // Use the centralized helper which handles both HTTP and FTP
        $attachment_id = Empire_Product_API::upload_from_ftp_path( $url, 'image' );
        
        return $attachment_id ? $attachment_id : false;
    }

    /**
     * Helper for logging to WooCommerce logs.
     * Accessible under WooCommerce > Status > Logs.
     */
    // private static function log( $message, $level = 'info' ) {
    //     if ( ! function_exists( 'wc_get_logger' ) ) {
    //         return;
    //     }

    //     $logger = wc_get_logger();
    //     $context = [ 'source' => 'empire-category-api' ];
        
    //     if ( is_array( $message ) || is_object( $message ) ) {
    //         $message = print_r( $message, true );
    //     }

    //     $logger->log( $level, $message, $context );
    // }
    /**
     * Handle CSV Export
     */
    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Unauthorized');
        check_admin_referer('empire_cat_export', 'empire_cat_export_nonce');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=empire-categories-export-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // Headers
        $headers = ['id', 'name', 'slug', 'parent_empire_id', 'description', 'image_src'];
        
        // Find all ACF fields used for categories to include them
        $acf_fields = [];
        if ( function_exists('acf_get_field_groups') ) {
            $groups = acf_get_field_groups(['taxonomy' => 'product_cat']);
            foreach ($groups as $group) {
                $fields = acf_get_fields($group['key']);
                if ($fields) {
                    foreach ($fields as $field) {
                        $acf_fields[] = $field['name'];
                    }
                }
            }
        }
        $headers = array_merge($headers, array_unique($acf_fields));
        fputcsv($output, $headers);

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        foreach ($terms as $term) {
            $empire_id = get_term_meta($term->term_id, '_empire_api_id', true);
            $parent_empire_id = 0;
            if ($term->parent) {
                $parent_empire_id = get_term_meta($term->parent, '_empire_api_id', true);
            }

            $img_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            $image_src = $img_id ? wp_get_attachment_url($img_id) : '';

            $row = [
                $empire_id ? $empire_id : 'wp_' . $term->term_id,
                $term->name,
                $term->slug,
                $parent_empire_id,
                $term->description,
                $image_src
            ];

            foreach ($acf_fields as $acf_field) {
                $val = get_field($acf_field, 'product_cat_' . $term->term_id);
                if (is_array($val)) $val = json_encode($val);
                $row[] = (string)$val;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Handle CSV Import
     */
    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Unauthorized');
        check_admin_referer('empire_cat_import', 'empire_cat_import_nonce');

        if ( empty($_FILES['import_csv']['tmp_name']) ) {
            wp_redirect(admin_url('admin.php?page=empire-category-api&error=no_file'));
            exit;
        }

        $file = $_FILES['import_csv']['tmp_name'];
        $handle = fopen($file, "r");
        $headers = fgetcsv($handle);

        $created = 0;
        $updated = 0;

        while (($data = fgetcsv($handle)) !== FALSE) {
            $row = array_combine($headers, $data);
            
            $item = [
                'id'          => $row['id'] ?? null,
                'name'        => $row['name'] ?? '',
                'slug'        => $row['slug'] ?? '',
                'parent'      => $row['parent_empire_id'] ?? 0,
                'description' => $row['description'] ?? '',
                'image'       => ['src' => $row['image_src'] ?? ''],
                'acf'         => []
            ];

            // Map ACF fields from remaining columns
            foreach ($row as $key => $value) {
                if (!in_array($key, ['id', 'name', 'slug', 'parent_empire_id', 'description', 'image_src'])) {
                    // Try to decode json if it looks like one
                    if (strpos($value, '[') === 0 || strpos($value, '{') === 0) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $decoded;
                        }
                    }
                    $item['acf'][$key] = $value;
                }
            }

            $res = self::create_or_update_category($item);
            if ($res === 'created') $created++;
            if ($res === 'updated') $updated++;
        }

        fclose($handle);
        wp_redirect(admin_url('admin.php?page=empire-category-api&import_done=1&created=' . $created . '&updated=' . $updated));
        exit;
    }
}
