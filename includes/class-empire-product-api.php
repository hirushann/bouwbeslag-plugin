<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Empire_Product_API {

    private static $instance = null;
    private static $upload_cache = [];
    private static $rest_acf_assets = [];
    private static $api_url = 'https://empire.dayzsolutions.nl/products-api';
    private static $api_token = '1969a86944e633bbac66bb64761a15b17ef9e34cee7461968a6a8e30d0afadc5';
    private static $ftp_conn = null;

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_page' ] );
        add_action( 'admin_post_empire_product_sync', [ __CLASS__, 'handle_sync' ] );
        add_action( 'wp_ajax_empire_sync_step', [ __CLASS__, 'ajax_sync_step' ] );
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'intercept_rest_request' ], 10, 3 );
        // add_filter('woocommerce_rest_product_object_query', [__CLASS__, 'log_rest_product_query'], 10, 2);
        add_filter('woocommerce_rest_pre_insert_product', [__CLASS__, 'log_rest_product_update'], 10, 3);
        add_filter('woocommerce_rest_pre_insert_product_object', [__CLASS__, 'log_rest_product_update'], 10, 3);
        add_action('woocommerce_rest_insert_product_object', [__CLASS__, 'after_rest_product_save'], 10, 3);
      	add_action( 'wp' , [ __CLASS__, 'remove_cart_item_via_url'] );
    }
  
    public static function remove_cart_item_via_url() {
        if ( ! isset( $_GET['remove-product'] ) ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $product_id = absint( $_GET['remove-product'] );

        if ( ! $product_id ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( (int) $cart_item['product_id'] === $product_id ) {
                WC()->cart->remove_cart_item( $cart_item_key );
            }
        }

        WC()->cart->calculate_totals();

        // Optional: redirect to avoid repeated removals on refresh
        //wp_safe_redirect( remove_query_arg( 'remove-product' ) );
        exit;
    }

    public static function add_admin_page() {
        add_menu_page(
            'Empire Product API',
            'Empire Product API',
            'manage_options',
            'empire-product-api',
            [ __CLASS__, 'admin_page_content' ],
            'dashicons-products',
            56
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
            <h1>Empire Product API Sync</h1>
            
            <div class="empire-card">
                <div class="empire-header">
                    <h2>Manual Sync</h2>
                    <button id="start-sync-btn" class="button button-primary button-hero-custom">Start Sync</button>
                    <button id="stop-sync-btn" class="button button-secondary button-hero-custom" style="display:none;">Stop</button>
                </div>

                <div class="empire-stats-grid">
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="stat-created">0</div>
                        <div class="empire-stat-label">Created</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="stat-updated">0</div>
                        <div class="empire-stat-label">Updated</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="stat-skipped">0</div>
                        <div class="empire-stat-label">Skipped</div>
                    </div>
                    <div class="empire-stat-box">
                        <div class="empire-stat-value" id="stat-page">0</div>
                        <div class="empire-stat-label">Current Page</div>
                    </div>
                </div>

                <div id="progress-container" class="empire-progress-container">
                    <div id="progress-bar" class="empire-progress-bar"></div>
                    <div id="progress-text" class="empire-progress-text">Initializing...</div>
                </div>

                <div id="log-window" class="empire-log-window"></div>
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
                const $logWindow = $('#log-window');
                $logWindow.append(`<div class="log-${type}">[${timestamp}] ${message}</div>`);
                $logWindow.scrollTop($logWindow[0].scrollHeight);
            }

            $('#start-sync-btn').on('click', function() {
                isSyncing = true;
                totalCreated = 0;
                totalUpdated = 0;
                totalSkipped = 0;
                
                $('#stat-created').text('0');
                $('#stat-updated').text('0');
                $('#stat-skipped').text('0');
                $('#stat-page').text('0');

                $(this).hide();
                $('#stop-sync-btn').show();
                
                $('#progress-container').show();
                $('#log-window').show().html('');
                $('#progress-bar').css('width', '5%');
                $('#progress-text').text('Starting sync...');

                log('Starting synchronization process...');
                processPage(1, 0);
            });

            $('#stop-sync-btn').on('click', function() {
                isSyncing = false;
                log('Stopping sync after current batch...', 'warning');
                $(this).text('Stopping...');
            });

            function processPage(page, index_offset = 0) {
                if (!isSyncing) {
                    finishSync("Stopped by user.");
                    return;
                }

                $('#stat-page').text(page);
                if(index_offset > 0) {
                     $('#progress-text').text('Processing Page ' + page + ' (Resuming from item ' + index_offset + ')...');
                } else {
                     $('#progress-text').text('Processing Page ' + page + '...');
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'empire_sync_step',
                        page: page,
                        index_offset: index_offset
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            totalCreated += data.created;
                            totalUpdated += data.updated;
                            totalSkipped += data.skipped;

                            $('#stat-created').text(totalCreated);
                            $('#stat-updated').text(totalUpdated);
                            $('#stat-skipped').text(totalSkipped);

                            log(`Processed ${data.processed} items (Page ${page}). Status: Created ${data.created}, Updated ${data.updated}`, 'success');

                            if (data.has_more) {
                                // Simulate progress bar movement for visual effect
                                const progress = Math.min((page * 2) + 5, 95); 
                                $('#progress-bar').css('width', progress + '%');
                processPage(data.next_page, data.next_index);
                            } else {
                                $('#progress-bar').css('width', '100%');
                                finishSync("Sync Completed Successfully!");
                            }
                        } else {
                            log('Error: ' + (response.data || 'Unknown error'), 'error');
                            finishSync("Sync failed.");
                        }
                    },
                    error: function(xhr, status, error) {
                        log('AJAX Error: ' + error, 'error');
                        finishSync("Sync failed due to server error.");
                    }
                });
            }

            function finishSync(message) {
                isSyncing = false;
                $('#start-sync-btn').show().text('Start Sync');
                $('#stop-sync-btn').hide().text('Stop');
                $('#progress-text').text(message);
                log('Process finished: ' + message, 'info');
                
                // Keep progress bar full if completed
                if (message.includes('Completed')) {
                    $('#progress-bar').css('width', '100%');
                }
            }
        });
        </script>
        <?php
    }

    public static function handle_sync() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); 
        }

        $page = 1;
        $index_offset = 0;
        
        $created_count = 0;
        $updated_count = 0;
        $skipped_count = 0;
        $total_processed = 0;

        do {
            $result = self::process_batch( $page, $index_offset );
            
            if ( is_wp_error( $result ) ) {
                break;
            }

            $created_count += $result['created'];
            $updated_count += $result['updated'];
            $skipped_count += $result['skipped'];
            $total_processed += $result['processed'];
            $has_more = $result['has_more'];


            if ( $has_more ) {
                if ( isset( $result['next_page'] ) ) {
                    $page = $result['next_page'];
                }
                
                if ( isset( $result['next_index'] ) ) {
                    $index_offset = $result['next_index'];
                } else {
                    $index_offset = 0;
                }
                
            } else {
                break;
            }

        } while ( true );

        self::close_ftp_connection();

        set_transient('empire_sync_results', [
            'created' => $created_count,
            'updated' => $updated_count,
            'skipped' => $skipped_count,
            'total'   => $total_processed,
        ], 60);

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'empire_product_sync' ) {
            wp_redirect( admin_url( 'admin.php?page=empire-product-api&synced=1' ) );
            exit;
        }
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
            self::close_ftp_connection();
            wp_send_json_error( $e->getMessage() );
            return;
        }

        self::close_ftp_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    private static function process_batch( $page, $start_index = 0 ) {
        $start_time = time();
        $max_execution_time = 5; // Very strict buffer (5 seconds) to handle large image payloads.

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . self::$api_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 60,
        ];

        $paged_url = self::$api_url . '?page=' . $page;
        
        $cache_key = 'empire_api_page_' . $page;
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

        // NEW: Pagination within the page batch
        // We iterate manually and stop if time runs out.
        
        if ( $count > 0 ) {
            for ( $i = $start_index; $i < $count; $i++ ) {
                $item = $items[$i];
                
                // Time Check
                if ( (time() - $start_time) > $max_execution_time ) {
                    self::close_ftp_connection();
                    return [
                        'processed' => $processed_in_batch,
                        'created'   => $created,
                        'updated'   => $updated,
                        'skipped'   => $skipped,
                        'has_more'  => true, // Force "more" so frontend calls again
                        'next_page' => $page, // Stay on same page
                        'next_index'=> $i,    // Resume from this index
                    ];
                }

                if ( ! isset( $item['fields'] ) || ! is_array( $item['fields'] ) ) {
                    $skipped++;
                    $processed_in_batch++;
                    continue;
                }

                $fields = $item['fields'];
                $crucial_data = ! empty( $fields['crucial'] ) ? $fields['crucial'] : $fields;

                $product_name = $crucial_data['product_name'] ?? $fields['product_name'] ?? $item['product_name'] ?? $item['name'] ?? null;
                $product_sku = $crucial_data['product_sku'] ?? null;

                if ( empty( $product_name ) || empty( $product_sku ) ) {
                    $skipped++;
                    $processed_in_batch++;
                    continue;
                }

                try {
                    $res = self::create_or_update_product( $item, $crucial_data );
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

        return [
            'processed' => $processed_in_batch,
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'has_more'  => ( $count > 0 ),
            'next_page' => $page + 1,
            'next_index'=> 0
        ];
    }

    private static function create_or_update_product( $item, $crucial_data = [] ) {

        if ( empty( $crucial_data ) ) {
            self::log( "Empire Sync: Skipping item because crucial/item data is empty." );
            return 'skipped';
        }

        $fields = $item['fields'] ?? [];

        $sku   = $crucial_data['product_sku'] ?? null;
        self::log( "Empire Sync: Processing product SKU: {$sku}" );
        $bouwbeslag_title = $item['fields']['description']['meta_data']['bouwbeslag_title'];
        $name  =  $bouwbeslag_title ?? $crucial_data['product_name'] ?? null;
        $price = $crucial_data['unit_price'] ?? 0;
        $stock = $crucial_data['own_stock'] ?? $crucial_data['total_stock'] ?? 0;
        $brand = $crucial_data['brand'] ?? $crucial_data['crucial_data_brand'] ?? '';
        $supplier = $crucial_data['supplier'] ?? '';

        if ( ! $sku || ! $name ) {
            return 'skipped';
        }

        $product_id = wc_get_product_id_by_sku( $sku );

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
        } else {
            $product = new WC_Product_Simple();
        }

        $margin_b2c = $crucial_data['b2b_and_b2c']['margin_b2c'] ?? 0;

        // Calculate price
        $product_regular_price = $crucial_data['b2b_and_b2c']['salesprice_b2c'] ?? 0;
        $formatted_price = round((float) $product_regular_price, 2);

        // Create the product first to ensure we have an ID
        $product->set_name( $name );
        $product->set_regular_price( $formatted_price );
        $product->set_sale_price( '' );
        $product->set_price( $formatted_price );
        $product->set_sku( $sku );
        $product->set_stock_quantity( $stock );
        $product->set_manage_stock( true );

        $product_id = $product->save(); // Save to generate ID

        // Update stock meta specifically (redundant but safe)
        wc_update_product_stock($product_id, $stock, 'set');
        update_post_meta($product_id, '_stock', $stock);
        update_post_meta($product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
        wc_delete_product_transients($product_id);

        $product = wc_get_product($product_id); // Reload to be safe

        update_post_meta( $product->get_id(), '_regular_price', $formatted_price );
        update_post_meta( $product->get_id(), '_price', $formatted_price );

        // We prioritize 'product_ean_code' as the source key from API/ACF data.
        $ean = $crucial_data['product_ean_code'];

        
        if ( ! empty( $ean ) ) {
            // Save to standard WordPress/WooCommerce EAN meta keys
            // update_post_meta( $product_id, '_ean', $ean );
            // update_post_meta( $product_id, '_wpm_gtin_code', $ean );
            update_post_meta( $product_id, '_global_unique_id', $ean );
        }

        // Now update ACF unit_price to match WooCommerce regular price
        update_field('unit_price', $formatted_price, $product->get_id());

        wc_delete_product_transients( $product->get_id() );
        wc_update_product_lookup_tables( $product->get_id() );

        $desc_data = $item['fields']['description'] ?? [];
        $long_desc = '';
        $short_desc = '';

        if (!empty($desc_data['description'])) {
            $all_descs = array_filter($desc_data['description']);
            $long_desc = implode("\n\n", $all_descs);
            $short_desc = reset($all_descs);
        }

        if (!empty($desc_data['usp'])) {
            $usps = array_filter($desc_data['usp']);
            $short_desc .= "\n\n" . implode(', ', $usps);
        }

        
        // if ( !empty($item['fields']['description']) ) {
        //     $desc_data = $item['fields']['description'];
        //     $desc_string = '';
        //     if (is_array($desc_data)) {
        //         $desc_string = json_encode($desc_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        //     } else {
        //         $desc_string = (string) $desc_data;
        //     }
        //     update_field('description', $desc_string, $product_id);
        // }

        //Save product Description(short and long)
        if ( ! empty( $desc_data['description']['description'] ) ) {
            $raw_desc = $desc_data['description']['description'];

            if ( is_array( $raw_desc ) ) {
                $desc_string = implode( "\n\n", array_map( 'wp_kses_post', $raw_desc ) );
            } else {
                $desc_string = wp_kses_post( (string) $raw_desc );
            }

            wp_update_post( [
                'ID'           => $product_id,
                'post_content' => $desc_string,
            ] );

            wp_update_post( [
                'ID'           => $product_id,
                'post_excerpt' => wp_trim_words( strip_tags( $desc_string ), 55 ),
            ] );
        }


        // update_post_meta( $product->get_id(), '_brand', $brand );
        // update_post_meta( $product->get_id(), '_supplier', $supplier );
        // update_post_meta( $product->get_id(), '_factory_sku', $crucial_data['product_factory_sku'] ?? '' );
        // update_post_meta( $product->get_id(), '_alternate_sku_1', $crucial_data['product_alternate_sku_1'] ?? '' );
        // update_post_meta( $product->get_id(), '_alternate_sku_2', $crucial_data['product_alternate_sku_2'] ?? '' );
        update_field('crucial_data', $crucial_data, $product->get_id());

        $product->save();

        $product_id = $product->get_id();
        // Fetch assets from either top-level or fields-level to handle API variations
        $assets = $item['assets'] ?? $item['fields']['assets'] ?? $fields['assets'] ?? [];




        $category_data = $crucial_data['category'] ?? '';

        //Fri feb 6 by #h
        if ( ! empty( $category_data ) ) {
            $taxonomy = 'product_cat';
            $categories = is_array( $category_data ) ? $category_data : [ $category_data ];
            $term_ids = [];

            foreach ( $categories as $cat ) {
                $cat = trim( (string) $cat );
                if ( empty( $cat ) ) {
                    continue;
                }

                // Check if category exists
                $term = term_exists( $cat, $taxonomy );

                if ( ! $term ) {
                    $term = wp_insert_term( $cat, $taxonomy );
                }

                if ( ! is_wp_error( $term ) ) {
                    $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                }
            }

            if ( ! empty( $term_ids ) ) {
                // Assign categories to product
                wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
            }
        }

        // Initialize ACF assets by merging with existing data to prevent data loss (e.g. if main_picture is missing in current payload but already set)
        $acf_assets = [];
        if ( $product_id && function_exists( 'get_field' ) ) {
            $existing_assets = get_field( 'assets', $product_id );
            if ( is_array( $existing_assets ) ) {
                $acf_assets = $existing_assets;
            }
        }

        /**
         * MAIN IMAGE → Featured image
         */
        if ( ! empty( $assets['main_picture'] ) ) {
            $main_path = is_array( $assets['main_picture'] ) ? reset( $assets['main_picture'] ) : $assets['main_picture'];
            $main_path = trim( reset( explode( ',', (string) $main_path ) ) );
            
            $main_id = self::upload_from_ftp_path( $main_path, 'image' );
            if ( $main_id ) {
                $main_id = (int) $main_id;
                set_post_thumbnail( $product_id, $main_id );
                $acf_assets['main_picture'] = $main_id;
            }
        }

        /**
         * SECONDARY IMAGES → Gallery
         */
        $secondary_paths = [];
        if ( ! empty( $assets['secondary_pictures'] ) ) {
            if ( is_array( $assets['secondary_pictures'] ) ) {
                $secondary_paths = array_merge( $secondary_paths, $assets['secondary_pictures'] );
            } else {
                $secondary_paths = array_merge( $secondary_paths, explode( ',', (string) $assets['secondary_pictures'] ) );
            }
        }
        // Also scan for indexed keys (secondary_pictures_1, assets_secondary_pictures_1, etc.)
        foreach ( $assets as $key => $val ) {
            if ( preg_match( '/^(assets_)?secondary_pictures_(\d+)$/', $key ) && ! empty( $val ) ) {
                if ( is_array( $val ) ) {
                    $secondary_paths = array_merge( $secondary_paths, $val );
                } else {
                    $secondary_paths = array_merge( $secondary_paths, explode( ',', (string) $val ) );
                }
            }
        }

        $gallery_ids = [];
        if ( ! empty( $secondary_paths ) ) {
            $unique_secondary_paths = array_filter( array_unique( $secondary_paths ) );
            self::log( "Empire Sync: Processing " . count($unique_secondary_paths) . " gallery images for SKU: {$sku}" );
            foreach ( $unique_secondary_paths as $path ) {
                $img_id = self::upload_from_ftp_path( trim( $path ), 'image' );
                if ( $img_id ) {
                    $gallery_ids[] = (int) $img_id;
                }
            }

            if ( ! empty( $gallery_ids ) ) {
                update_post_meta(
                    $product_id,
                    '_product_image_gallery',
                    implode( ',', $gallery_ids )
                );
                $acf_assets['secondary_pictures'] = $gallery_ids;
            }
        }

        /**
         * OTHER ASSETS (PDFs, drawings, etc.)
         */
        $file_fields = [
            'manual_pdf',
            'technical_drawing',
            'installation_guide',
            'product_certificate',
            'care_instructions',
        ];

        foreach ( $file_fields as $field ) {
            if ( ! empty( $assets[ $field ] ) ) {
                $file_id = self::upload_from_ftp_path( $assets[ $field ], 'file' );
                if ( $file_id ) {
                    $acf_assets[ $field ] = $file_id;
                }
            }
        }

        /**
         * Category Image (Stored as direct top-level field)
         */
        $cat_image_path = $assets['product_card_category_image'] ?? $assets['cat_image'] ?? null;
        if ( ! empty( $cat_image_path ) ) {
            $single_cat_path = is_array( $cat_image_path ) ? reset( $cat_image_path ) : $cat_image_path;
            $single_cat_path = trim( reset( explode( ',', (string) $single_cat_path ) ) );

            $cat_img_id = self::upload_from_ftp_path( $single_cat_path, 'image' );
            if ( $cat_img_id ) {
                $cat_img_id = (int) $cat_img_id;
                $acf_assets['cat_image'] = $cat_img_id;
                $acf_assets['product_card_category_image'] = $cat_img_id;
            }
        }

        /**
         * Ambiance images (multiple)
         */
        $ambiance_paths = [];
        if ( ! empty( $assets['ambiance_pictures'] ) ) {
            if ( is_array( $assets['ambiance_pictures'] ) ) {
                $ambiance_paths = array_merge( $ambiance_paths, $assets['ambiance_pictures'] );
            } else {
                $ambiance_paths = array_merge( $ambiance_paths, explode( ',', (string) $assets['ambiance_pictures'] ) );
            }
        }
        // Also scan for indexed keys (ambiance_pictures_1, assets_ambiance_pictures_1, etc.)
        foreach ( $assets as $key => $val ) {
            if ( preg_match( '/^(assets_)?ambiance_pictures_(\d+)$/', $key ) && ! empty( $val ) ) {
                if ( is_array( $val ) ) {
                    $ambiance_paths = array_merge( $ambiance_paths, $val );
                } else {
                    $ambiance_paths = array_merge( $ambiance_paths, explode( ',', (string) $val ) );
                }
            }
        }

        if ( ! empty( $ambiance_paths ) ) {
            $ids = [];
            foreach ( array_filter( array_unique( $ambiance_paths ) ) as $path ) {
                $id = self::upload_from_ftp_path( trim( $path ), 'image' );
                if ( $id ) $ids[] = (int) $id;
            }
            if ( ! empty( $ids ) ) {
                $acf_assets['ambiance_pictures'] = $ids;
            }
        }

        /**
         * Save into ACF assets group
         */
        if ( ! empty( $acf_assets ) ) {
            update_field( 'assets', $acf_assets, $product_id );
        }

        // Handle brand creation and assignment
        if ( ! empty( $brand ) ) {
            $brand_name = '';
            $image_url  = '';

            if ( is_array( $brand ) ) {
                $brand_name = isset( $brand['name'] ) ? trim( (string) $brand['name'] ) : '';
                $image_url  = isset( $brand['image_url'] ) ? esc_url_raw( $brand['image_url'] ) : '';
            } else {
                $brand_name = trim( (string) $brand );
            }

            if ( $brand_name !== '' ) {
                $taxonomy = 'product_brand';
                $term = term_exists( $brand_name, $taxonomy );

                if ( ! $term ) {
                    $term = wp_insert_term( $brand_name, $taxonomy );
                }

                if ( ! is_wp_error( $term ) ) {
                    $term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

                    // Assign brand to product (WooCommerce Default Brand)
                    $taxonomy_to_use = $taxonomy;
                    $taxonomies_to_check = [ 'product_brand', 'brand', 'pwb-brand', 'pa_brand' ];
                    
                    foreach ( $taxonomies_to_check as $tax ) {
                        if ( taxonomy_exists( $tax ) ) {
                            $taxonomy_to_use = $tax;
                            break;
                        }
                    }

                    // Check if we need to insert the term into the correct taxonomy if it's not product_brand
                    if ( $taxonomy_to_use !== $taxonomy ) {
                        $term = term_exists( $brand_name, $taxonomy_to_use );
                        if ( ! $term ) {
                            $term = wp_insert_term( $brand_name, $taxonomy_to_use );
                        }
                        if ( ! is_wp_error( $term ) ) {
                            $term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                        }
                    }

                    $res = wp_set_object_terms( $product_id, [ $term_id ], $taxonomy_to_use, false );
                    
                    if ( is_wp_error( $res ) ) {
                        error_log( "Empire Sync: Error assigning brand '{$brand_name}' to taxonomy '{$taxonomy_to_use}': " . $res->get_error_message() );
                    } else {
                        // Success - clear product transients to ensure UI updates
                        wc_delete_product_transients( $product_id );
                        wc_update_product_lookup_tables( $product_id );
                    }

                    // Fallback: Also try to find any other taxonomy linked to products that contains the word 'brand'
                    $product_taxonomies = get_object_taxonomies( 'product' );
                    foreach ( $product_taxonomies as $prod_tax ) {
                        if ( strpos( $prod_tax, 'brand' ) !== false && $prod_tax !== $taxonomy_to_use ) {
                            $brand_term = term_exists( $brand_name, $prod_tax );
                            if ( ! $brand_term ) {
                                $brand_term = wp_insert_term( $brand_name, $prod_tax );
                            }
                            if ( ! is_wp_error( $brand_term ) ) {
                                $bt_id = (int) ( is_array( $brand_term ) ? $brand_term['term_id'] : $brand_term );
                                wp_set_object_terms( $product_id, [ $bt_id ], $prod_tax, false );
                            }
                        }
                    }

                    // Update brand in ACF field
                    update_field( 'brand', $brand_name, $product_id );

                    // Handle brand image
                    if ( $image_url ) {

                        // First, check if the brand already has a valid image attached
                        $existing_thumb_id = get_term_meta( $term_id, 'thumbnail_id', true );

                        // If no meta exists, or if the attachment post was deleted (e.g. media library cleared), proceed
                        if ( ! $existing_thumb_id || ! get_post( $existing_thumb_id ) ) {
                            self::log( "Empire Sync: Brand image missing or invalid for '{$brand_name}'. Processing URL: {$image_url}" );

                            // Use centralized upload logic to prevent duplication for brands as well
                            $attachment_id = self::upload_from_ftp_path( $image_url, 'image' );

                            if ( $attachment_id ) {
                                self::log( "Empire Sync: Assigned attachment {$attachment_id} to brand '{$brand_name}'" );
                                update_term_meta( $term_id, 'thumbnail_id', $attachment_id );
                                update_post_meta( $attachment_id, '_empire_brand_img_url', $image_url ); // Tag it for brand-specific searches if needed
                            }
                        }
                    }
                }
            }
        }



        self::update_acf_fields( $product->get_id(), $item );

        return $product_id ? 'updated' : 'created';
    }

    private static function flatten_array_to_string( $value ) {
        if ( is_array( $value ) ) {
            $flat = [];
            foreach ( $value as $v ) {
                if ( is_array( $v ) ) {
                    $flat[] = self::flatten_array_to_string( $v );
                } else {
                    $flat[] = $v;
                }
            }
            return implode( ', ', array_filter( $flat ) );
        }
        return (string) $value;
    }

    private static function update_product_usps_from_api( $item ) {
        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $desc_data = $item['fields']['description'] ?? [];
        if ( empty( $desc_data['usp'] ) || ! is_array( $desc_data['usp'] ) ) {
            return false;
        }

        $usp_data = $desc_data['usp'];

        $description_group = get_field('description', $product_id);
        if ( ! is_array( $description_group ) ) {
            $description_group = [];
        }

        for ( $i = 1; $i <= 8; $i++ ) {
            $key = "usp_{$i}";
            $val = isset($usp_data[$key]) ? sanitize_text_field($usp_data[$key]) : '';
            $description_group[$key] = $val;
        }

        update_field('description', $description_group, $product_id);
        return true;
    }

    /**
     * Update product descriptions from API, mapping description fields into ACF group.
     */
    private static function update_product_descriptions_from_api( $item ) {
        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $desc_data = $item['fields']['description']['description'] ?? [];
        if ( empty( $desc_data ) || ! is_array( $desc_data ) ) {
            return false;
        }

        // Read existing description group (if any) to avoid overwriting other subfields
        $description_group = get_field('description', $product_id);
        if ( ! is_array( $description_group ) ) {
            $description_group = [];
        }

        $desc_keys = [ 'description', 'description_1', 'description_2', 'description_3', 'description_4', 'description_5' ];
        foreach ( $desc_keys as $key ) {
            $val = isset($desc_data[$key]) ? sanitize_textarea_field($desc_data[$key]) : '';
            $description_group[$key] = $val;
        }

        update_field('description', $description_group, $product_id);
        return true;
    }



    /**
     * Update product dimensions from API, mapping fields into ACF fields.
     */
    private static function update_product_dimensions_from_api( $item ) {
        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $dims = $item['fields']['dimensions'] ?? [];
        if ( empty( $dims ) ) {
            return false;
        }

        $product_dims = $dims['product'] ?? [];
        $package_dims = $dims['package'] ?? [];

        $fields_map = [
            'product_width'          => $product_dims['product_width'] ?? '',
            'product_width_unit'     => $product_dims['product_width_unit'] ?? '',
            'product_height'         => $product_dims['product_height'] ?? '',
            'product_height_unit'    => $product_dims['product_height_unit'] ?? '',
            'product_diameter'       => $product_dims['product_diameter'] ?? '',
            'product_diameter_unit'  => $product_dims['product_diameter_unit'] ?? '',
            'product_length'         => $product_dims['product_length'] ?? '',
            'product_length_unit'    => $product_dims['product_length_unit'] ?? '',
            'package_width'          => $package_dims['package_width'] ?? '',
            'package_width_unit'     => $package_dims['package_width_unit'] ?? '',
            'package_height'         => $package_dims['package_height'] ?? '',
            'package_height_unit'    => $package_dims['package_height_unit'] ?? '',
            'package_length'         => $package_dims['package_length'] ?? '',
            'package_length_unit'    => $package_dims['package_length_unit'] ?? '',
            'package_weight'         => $package_dims['package_weight'] ?? '',
            'package_weight_unit'    => $package_dims['package_weight_unit'] ?? '',
        ];

        // Group field key for "dimensions"
        $group_field = 'dimensions';

        $dimension_group = get_field($group_field, $product_id);
        if ( !is_array($dimension_group) ) {
            $dimension_group = [];
        }

        foreach ( $fields_map as $key => $value ) {
            $clean_val = is_numeric( $value ) ? floatval( $value ) : sanitize_text_field( $value );
            $dimension_group[$key] = $clean_val;
        }

        update_field($group_field, $dimension_group, $product_id);

        return true;
    }

    // private static function upload_media_from_url( $url, $type = 'image' ) {
    //     if ( empty( $url ) ) {
    //         return '';
    //     }

    //     $tmp = '';

    //     if ( strpos( $url, 'empire/' ) === 0 ) {
    //         $ftp_server = "u431887.your-storagebox.de";
    //         $ftp_user   = "u431887";
    //         $ftp_pass   = "B2swHwPKxSnWUEvc";

    //         $ftp_conn = ftp_ssl_connect( $ftp_server, 21, 10 );
    //         if ( $ftp_conn && ftp_login( $ftp_conn, $ftp_user, $ftp_pass ) ) {
    //             ftp_pasv( $ftp_conn, true );
    //             ftp_set_option($ftp_conn, FTP_TIMEOUT_SEC, 10);

    //             $tmp = wp_tempnam( basename( $url ) );
    //             $remote_path = '/' . ltrim( $url, '/' );

    //             $filename = basename($url);
    //             $existing = get_page_by_title($filename, OBJECT, 'attachment');
    //             if ($existing) {
    //                 ftp_close($ftp_conn);
    //                 return $existing->ID;
    //             }

    //             if ( ftp_get( $ftp_conn, $tmp, $remote_path, FTP_BINARY ) ) {
    //             } else {
    //                 ftp_close( $ftp_conn );
    //                 return '';
    //             }

    //             ftp_close( $ftp_conn );
    //         } else {
    //             return '';
    //         }
    //     } else {
    //         // Standard HTTP(S) download
    //         if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
    //             return '';
    //         }
    //         $tmp = download_url( $url );
    //         if ( is_wp_error( $tmp ) ) {
    //             return '';
    //         }
    //     }
    //     $file = [
    //         'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
    //         'type'     => mime_content_type( $tmp ),
    //         'tmp_name' => $tmp,
    //         'error'    => 0,
    //         'size'     => filesize( $tmp ),
    //     ];
    //     $overrides = [ 'test_form' => false ];
    //     $results = wp_handle_sideload( $file, $overrides );
    //     if ( isset( $results['error'] ) ) {
    //         @unlink( $tmp );
    //         return '';
    //     }
    //     $attachment = [
    //         'post_mime_type' => $results['type'],
    //         'post_title'     => sanitize_file_name( basename( $url ) ),
    //         'post_content'   => '',
    //         'post_status'    => 'inherit',
    //     ];
    //     $attach_id = wp_insert_attachment( $attachment, $results['file'] );
    //     require_once ABSPATH . 'wp-admin/includes/image.php';
    //     $attach_data = wp_generate_attachment_metadata( $attach_id, $results['file'] );
    //     wp_update_attachment_metadata( $attach_id, $attach_data );
    //     $uploaded_url = wp_get_attachment_url( $attach_id );
    //     return $attach_id;
    // }

    private static function get_ftp_connection() {
        if ( self::$ftp_conn ) {
            return self::$ftp_conn;
        }

        if ( defined( 'FTP_SERVER' ) && defined( 'FTP_USER' ) && defined( 'FTP_PASS' ) ) {
            $ftp_server = FTP_SERVER;
            $ftp_user   = FTP_USER;
            $ftp_pass   = FTP_PASS;
        } else {
            // Fallback default
            $ftp_server = "u431887.your-storagebox.de";
            $ftp_user   = "u431887";
            $ftp_pass   = "B2swHwPKxSnWUEvc";
        }

        if ( ! function_exists( 'ftp_ssl_connect' ) ) {
            return false;
        }

        // Reduced timeout to 10s
        $conn = ftp_ssl_connect( $ftp_server, 21, 10 );
        if ( ! $conn ) {
            return false;
        }

        if ( ! @ftp_login( $conn, $ftp_user, $ftp_pass ) ) {
            ftp_close( $conn );
            return false;
        }

        ftp_pasv( $conn, true );
        // Set network timeout to 10s
        ftp_set_option($conn, FTP_TIMEOUT_SEC, 10);
        
        self::$ftp_conn = $conn;
        return $conn;
    }

    private static function close_ftp_connection() {
        if ( self::$ftp_conn ) {
            @ftp_close( self::$ftp_conn );
            self::$ftp_conn = null;
        }
    }
    public static function upload_from_ftp_path( $ftp_path, $type = 'image', $provided_filename = '' ) {

        if ( empty( $ftp_path ) ) {
            return 0;
        }

        $ftp_path = trim( $ftp_path );
        $normalized_path = $ftp_path;
        if ( strpos( $normalized_path, '?' ) !== false ) {
            $normalized_path = explode( '?', $normalized_path )[0];
        }

        // 0. Check internal request cache to prevent duplicate processing in a single execution loop
        if ( isset( self::$upload_cache[$normalized_path] ) ) {
            return self::$upload_cache[$normalized_path];
        }

        self::log( "Empire Sync: Checking image path: {$ftp_path} (Type: {$type})" );

        // We must include core file functions to sideload images across AJAX/REST scopes
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Check if already uploaded
        $filename = !empty($provided_filename) ? $provided_filename : basename( $ftp_path );
        // Remove query strings if any (e.g. image.jpg?v=1)
        if ( strpos( $filename, '?' ) !== false ) {
            $filename = explode( '?', $filename )[0];
        }
        $sanitized_title = sanitize_file_name( $filename );
        
        global $wpdb;

        // 1a. If it's a URL to an existing image on THIS site, find it directly via WP native check
        if ( strpos( $ftp_path, 'http' ) === 0 ) {
            $existing_id = attachment_url_to_postid( $ftp_path );
            if ( $existing_id ) {
                self::log( "Empire Sync: Found existing ID {$existing_id} via attachment_url_to_postid for URL: {$ftp_path}" );
                self::$upload_cache[$ftp_path] = (int) $existing_id;
                return (int) $existing_id;
            }
        }

        // 1. Try to find by our exact custom mapped meta (for accurate future-proof exact matching)
        $existing_id = $wpdb->get_var( $wpdb->prepare( 
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_empire_source_path' AND meta_value = %s LIMIT 1", 
            $ftp_path
        ) );

        if ( $existing_id ) {
            self::log( "Empire Sync: Found existing ID {$existing_id} by exact source path: {$ftp_path}" );
        }

        if ( ! $existing_id ) {
            // 2. Fallback to finding by exact post_title (with or without extension)
            $title_no_ext = pathinfo( $filename, PATHINFO_FILENAME );
            $existing_id = $wpdb->get_var( $wpdb->prepare( 
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (post_title = %s OR post_title = %s) LIMIT 1", 
                $sanitized_title,
                $title_no_ext
            ) );
            
            if ( $existing_id ) {
                self::log( "Empire Sync: Found existing ID {$existing_id} by title: {$sanitized_title} or {$title_no_ext}" );
            }

            // 2b. Fallback: Search by attachment slug (post_name) which often matches the filename
            if ( ! $existing_id ) {
                $slug = sanitize_title( $title_no_ext );
                $existing_id = $wpdb->get_var( $wpdb->prepare( 
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name = %s LIMIT 1", 
                    $slug
                ) );

                if ( $existing_id ) {
                    self::log( "Empire Sync: Found existing ID {$existing_id} by slug check: {$slug}" );
                }
            }

            // 3. Fallback: Search by filename in _wp_attached_file meta
            if ( ! $existing_id ) {
                $existing_id = $wpdb->get_var( $wpdb->prepare( 
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value LIKE %s OR meta_value = %s) LIMIT 1", 
                    '%/' . $wpdb->esc_like( $filename ),
                    $filename
                ) );

                if ( $existing_id ) {
                    self::log( "Empire Sync: Found existing ID {$existing_id} by filename meta check: {$filename}" );
                }
            }
            
            if ( $existing_id ) {
                // Verify the attachment still exists before claiming it is valid
                if ( get_post( $existing_id ) ) {
                    // Update the source path meta so we find it by the first (fastest) check next time
                    update_post_meta( $existing_id, '_empire_source_path', $ftp_path );
                } else {
                    self::log( "Empire Sync: Attachment ID {$existing_id} was found but post record is missing. Proceeding to re-upload." );
                    $existing_id = 0;
                }
            }
        }

        if ( $existing_id && get_post( $existing_id ) ) {
            // Auto-heal missing metadata for existing/cached images to fix frontend broken tiles
            if ( $type === 'image' ) {
                $meta = wp_get_attachment_metadata( $existing_id );
                if ( empty($meta) ) {
                    $attached_file = get_attached_file( $existing_id );
                    if ( $attached_file && file_exists( $attached_file ) ) {
                        $attach_data = wp_generate_attachment_metadata( $existing_id, $attached_file );
                        wp_update_attachment_metadata( $existing_id, $attach_data );
                    }
                }
            }
            self::$upload_cache[$ftp_path] = (int) $existing_id;
            self::$upload_cache[$filename] = (int) $existing_id;
            return (int) $existing_id;
        }

        // Final check in cache before downloading (using normalized filename as key)
        if ( isset( self::$upload_cache[$filename] ) ) {
            return self::$upload_cache[$filename];
        }

        // Support HTTP(S) URLs directly
        if ( strpos( $ftp_path, 'http' ) === 0 ) {
            $tmp = download_url( $ftp_path );
            if ( is_wp_error( $tmp ) ) {
                self::log( "Empire Sync: Failed to download URL: " . $ftp_path );
                return 0;
            }
        } else {
            // Use persistent FTP connection
            $conn = self::get_ftp_connection();
            if ( ! $conn ) {
                return 0;
            }

            $tmp = wp_tempnam( $filename );
            if ( ! @ftp_get( $conn, $tmp, '/' . ltrim( $ftp_path, '/' ), FTP_BINARY ) ) {
                @unlink( $tmp );
                return 0;
            }
        }

        // 4. Content-based deduplication (MD5) - The most reliable way to prevent duplicates
        $md5 = hash_file( 'md5', $tmp );
        $existing_id = $wpdb->get_var( $wpdb->prepare( 
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_empire_image_md5' AND meta_value = %s LIMIT 1", 
            $md5
        ) );

        if ( $existing_id && get_post( $existing_id ) ) {
            self::log( "Empire Sync: Found existing ID {$existing_id} by content MD5: {$md5}" );
            @unlink( $tmp );
            update_post_meta( $existing_id, '_empire_source_path', $ftp_path );
            self::$upload_cache[$ftp_path] = (int) $existing_id;
            return (int) $existing_id;
        }

        // Upload to WP
        $file = [
            'name'     => $filename,
            'tmp_name' => $tmp,
            'size'     => filesize( $tmp ),
            'error'    => 0,
        ];

        $upload = wp_handle_sideload( $file, [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) {
            @unlink( $tmp );
            return 0;
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $upload['type'],
                'post_title'     => $sanitized_title,
                'post_status'    => 'inherit',
            ],
            $upload['file']
        );
        
        // FIX: Missing _wp_attached_file meta which causes duplicates & prevents image URLs from working.
        // Even if we skip resizing (metadata generation), we MUST set the attached file.
        update_attached_file( $attachment_id, $upload['file'] );

        // Tag the source URL so it NEVER duplicates again
        update_post_meta( $attachment_id, '_empire_source_path', $ftp_path );
        if ( ! empty( $md5 ) ) {
            update_post_meta( $attachment_id, '_empire_image_md5', $md5 );
        }
        self::log( "Empire Sync: Uploaded new attachment {$attachment_id} for {$filename} (MD5: {$md5})" );

        // Generating metadata is REQUIRED for images to show properly in WordPress grids, srcset, and other dimensions
        if ( $type === 'image' ) {
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata(
                $attachment_id,
                $attach_data
            );
        }

        if ( $attachment_id ) {
            self::$upload_cache[$normalized_path] = (int) $attachment_id;
            self::$upload_cache[$filename] = (int) $attachment_id;
        }

        return (int) $attachment_id;
    }

    private static function log( $message ) {
        // Log to system error log for immediate monitoring
        error_log( $message );

        // Log to WooCommerce for persistent history
        if ( class_exists( 'WC_Logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( $message, [ 'source' => 'empire-product-api' ] );
        }
    }
    private static function update_acf_fields( $post_id, $item ) {
        $fields = $item['fields'] ?? [];
      
      

        // Dimensions group (saved as-is)
        if ( isset( $fields['dimensions'] ) ) {
            update_field( 'dimensions', $fields['dimensions'], $post_id );
        }
      
      	//Warranty Field
      	if ( isset( $fields['crucial']['guarantee_period'] ) ) {
            update_field( 'guarantee_period', $fields['crucial']['guarantee_period'], $post_id );
        }

        // Discounts (flattened to individual fields)
        $discounts_data = $fields['crucial']['discounts'] ?? $fields['discounts'] ?? [];
        //  wc_get_logger()->debug( json_encode($discounts_data) );
        //  wc_get_logger()->debug( json_encode($post_id) );
        if ( is_array( $discounts_data ) ) {
            for ( $i = 1; $i <= 3; $i++ ) {
                $dq = isset( $discounts_data["discount_{$i}"]['discount_quantity'] ) ? floatval($discounts_data["discount_{$i}"]['discount_quantity']) : '';
                $dp = isset( $discounts_data["discount_{$i}"]['discount_percentage'] ) ? floatval($discounts_data["discount_{$i}"]['discount_percentage']) : '';
                update_field("crucial_data_discounts_discount_quantity_{$i}", $dq, $post_id);
                update_field("crucial_data_discounts_discount_percentage_{$i}", $dp, $post_id);
            }
        }

        // B2B and B2C (flattened to individual fields)
        $b2b_b2c_data = $fields['crucial']['b2b_and_b2c'] ?? $fields['b2b_and_b2c'] ?? [];
        if ( is_array( $b2b_b2c_data ) ) {
            $margin_b2c = floatval( $b2b_b2c_data['margin_b2c'] ?? 0 );
            $margin_b2b = floatval( $b2b_b2c_data['margin_b2b'] ?? 0 );

            $sales_price_b2c = round(floatval( $b2b_b2c_data['salesprice_b2c'] ?? 0 ), 2);
            $sales_price_b2b = round(floatval( $b2b_b2c_data['salesprice_b2b'] ?? 0 ), 2);
            // Calculate sales prices based on existing unit price (if any)
            $unit_price = floatval( get_field('unit_price', $post_id) ?? 0 );
            // $sales_price_b2c = $unit_price + ( $unit_price * ( $margin_b2c / 100 ) );
            // $sales_price_b2b = $unit_price + ( $unit_price * ( $margin_b2b / 100 ) );
            // $sales_price_b2c = round($unit_price + ( $unit_price * ( $margin_b2c / 100 ) ), 2);
            // $sales_price_b2b = round($unit_price + ( $unit_price * ( $margin_b2b / 100 ) ), 2);

            // update_field('crucial_data_b2b_and_b2c_margin_b2c', $margin_b2c, $post_id);
            // update_field('crucial_data_b2b_and_b2c_margin_b2b', $margin_b2b, $post_id);
            // update_field('crucial_data_b2b_and_b2c_sales_price_b2c', $sales_price_b2c, $post_id);
            // update_field('crucial_data_b2b_and_b2c_sales_price_b2b', $sales_price_b2b, $post_id);

            $crucial_group = get_field('crucial_data', $post_id);

            if ( ! is_array($crucial_group) ) {
                $crucial_group = [];
            }

            $crucial_group['b2b_and_b2c'] = [
                'margin_b2c'       => $margin_b2c,
                'margin_b2b'       => $margin_b2b,
                'sales_price_b2c'  => $sales_price_b2c,
                'sales_price_b2b'  => $sales_price_b2b,
            ];

            update_field('crucial_data', $crucial_group, $post_id);
        }

        //Update cheapest price option
        $cheapest_price_option = $fields['crucial']['cheapest_price_option'] ?? $fields['cheapest_price_option'] ?? false;
        update_field('crucial_data_cheapest_price_option', $cheapest_price_option, $post_id);

        //Update Maatwerk Field
        $maatwerk = $fields['crucial']['maatwerk'] ?? $fields['maatwerk'] ?? false;
        update_field('crucial_data_maatwerk', $maatwerk, $post_id);

        // Assets group uploading and attaching is now fully handled in `create_or_update_product` directly to prevent duplication and missing keys.

        // Related group
        if ( isset( $fields['related'] ) ) {
            update_field( 'related', $fields['related'], $post_id );
        }

        // BOL data group
        if ( isset( $fields['bol_data'] ) ) {
            update_field( 'bol_data', $fields['bol_data'], $post_id );
        }

        self::update_product_meta_data_from_api( $item );
        // Finally, merge USP fields into the Description group
        self::update_product_usps_from_api( $item );
        // Also update product descriptions from API
        self::update_product_descriptions_from_api( $item );
        // Also update FAQ fields from API
        self::update_product_faq_from_api( $item );
        // Also update product dimensions from API
        self::update_product_dimensions_from_api( $item );
        // Also update related fields from API
        self::update_product_related_from_api( $item );
        // Also update WooCommerce product attributes from API
        self::update_product_attributes_from_api( $item );
    }

    private static function update_product_meta_data_from_api($item ) {
        if (empty($item['fields']['description']['meta_data'])) {
            return false;
        }

        if ( empty( $item['fields']['description']['meta_data'] ) || ! is_array( $item['fields']['description']['meta_data'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $meta_data = $item['fields']['description']['meta_data'];
        $bouwbeslag_title  = $meta_data['bouwbeslag_title'] ?? '';
        $slug              = $meta_data['slug'] ?? '';
        $meta_title        = $meta_data['meta_title'] ?? '';
        $meta_description  = $meta_data['meta_description'] ?? '';

        $sanitized_slug = sanitize_title( $slug );

        if ( $sanitized_slug !== '' ) {
            // ✅ This is what actually updates the product slug in WP
            wp_update_post([
                'ID'        => $product_id,
                'post_name' => $sanitized_slug,
            ]);

            // (Optional) keep WC object consistent too
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $product->set_slug( $sanitized_slug );
                $product->save();
                $product_id = $product->get_id();
            }
        }

        // $product = wc_get_product( $product_id );
        // $sanitized_slug = sanitize_title( $slug );
        // $product->set_slug( $sanitized_slug );

      
        update_field( 'description_bouwbeslag_title', $bouwbeslag_title, $product_id );

        // IMPORTANT: store the sanitized slug so it matches the real product slug
        update_field( 'description_slug', $sanitized_slug, $product_id );

        update_field( 'description_meta_title', $meta_title, $product_id );
        update_field( 'description_meta_description', $meta_description, $product_id );
    }

    /**
     * Update WooCommerce product attributes and terms from API attributes.
     * This version writes directly to _product_attributes post meta, ensuring attributes appear in the Attributes tab.
     */
    // private static function update_product_attributes_from_api($item) {
    //     if (empty($item['fields']['crucial']['product_sku'])) {
    //         return false;
    //     }

    //     $sku = sanitize_text_field($item['fields']['crucial']['product_sku']);
    //     $product_id = wc_get_product_id_by_sku($sku);

    //     if (!$product_id) {
    //         return false;
    //     }

    //     $attributes = $item['fields']['attributes'] ?? [];
    //     if (empty($attributes) || !is_array($attributes)) {
    //         return false;
    //     }

    //     $product = wc_get_product($product_id);


    //     // Flatten the nested attributes array
    //     $flattened_attributes = [];
    //     $excluded_attributes = ['package_content'];

    //     foreach ($attributes as $category => $category_attributes) {
    //         foreach ($category_attributes as $key => $value) {
    //             // Skip if value is empty
    //             if ($value === null || $value === '' || (is_array($value) && empty(array_filter($value)))) {
    //                 continue;
    //             }

    //             // Skip excluded attributes
    //             if (in_array($key, $excluded_attributes)) {
    //                 continue;
    //             }

    //              // Handle cam_size array specially - check if it's not empty
    //             if ($key === 'cam_size' && is_array($value)) {
    //                 $filtered_array = array_filter($value, function($item) {
    //                     return $item !== null && $item !== '' && trim($item) !== '';
    //                 });
                    
    //                 if (empty($filtered_array)) {
    //                     continue; 
    //                 }
                    
    //                 $flattened_attributes[$key] = $value;
    //                 continue;
    //             }

    //              // Convert with_core_pulling_protection from 0/1 to no/yes
    //             if ($key === 'with_core_pulling_protection') {
    //                 if ($value === 0 || $value === '0') {
    //                     $value = 'no';
    //                 } elseif ($value === 1 || $value === '1') {
    //                     $value = 'yes';
    //                 }
    //             }
                
    //             // Add to flattened array
    //             $flattened_attributes[$key] = $value;
    //         }
    //     }

    //     // Now process the flattened attributes
    //     $attributes_data = [];
    //     $combined_fields = [
    //         'max_door_thickness' => 'max_door_thickness_unit',
    //         'min_door_thickness' => 'min_door_thickness_unit',
    //         // Add other combined field pairs here
    //     ];

    //     foreach ($flattened_attributes as $key => $value) {
    //         if (empty($key) || $value === null || $value === '' || (is_array($value) && empty(array_filter($value)))) {
    //             continue;
    //         }

    //         // Combine measurement with unit if applicable
    //         if (isset($combined_fields[$key]) && isset($flattened_attributes[$combined_fields[$key]])) {
    //             $unit = trim((string)$flattened_attributes[$combined_fields[$key]]);
    //             $value = trim((string)$value);

    //             // Skip if value is 0.00 (with or without unit)
    //             if ($value === '0.00' || $value === '0.0' || $value === '0') {
    //                 continue; 
    //             }

    //             if ($unit !== '') {
    //                 $value = "{$value} {$unit}";
    //             }
    //         } elseif (in_array($key, $combined_fields)) {
    //             // Skip unit fields since they are already processed
    //             continue;
    //         }

    //         // Normalize array values
    //         if (is_array($value)) {
    //             $value = implode(', ', array_filter($value));
    //         }

    //         $value = trim((string)$value);
    //         if ($value === '') continue;

    //         $tax_slug = sanitize_title($key);
    //         $tax_name = 'pa_' . $tax_slug;

    //         // Ensure attribute taxonomy exists
    //         if (!taxonomy_exists($tax_name)) {
    //             $attr_result = wc_create_attribute([
    //                 'slug' => $tax_slug,
    //                 'name' => ucwords(str_replace('_', ' ', $key)),
    //                 'type' => 'select',
    //                 'order_by' => 'menu_order',
    //                 'has_archives' => false,
    //             ]);
    //             if (is_wp_error($attr_result)) {
    //                 continue;
    //             }
    //             register_taxonomy(
    //                 $tax_name,
    //                 ['product'],
    //                 [
    //                     'hierarchical' => false,
    //                     'label' => ucwords(str_replace('_', ' ', $key)),
    //                     'query_var' => true,
    //                     'rewrite' => ['slug' => $tax_slug],
    //                     'show_admin_column' => true,
    //                 ]
    //             );
    //         }

    //         // Ensure term exists
    //         $term = term_exists($value, $tax_name);
    //         if (!$term) {
    //             $term = wp_insert_term($value, $tax_name);
    //             if (is_wp_error($term)) {
    //                 continue;
    //             }
    //         }

    //         // Assign term to product
    //         wp_set_object_terms($product_id, [$value], $tax_name, false);

    //         // Prepare attribute data for product object
    //         $attributes_data[$tax_name] = [
    //             'name' => $tax_name,
    //             'value' => $value,
    //             'is_visible' => 1,
    //             'is_variation' => 0,
    //             'is_taxonomy' => 1,
    //         ];
    //     }

    //     return true;
    // }

    private static function update_product_attributes_from_api( $item ) {

        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $attributes = $item['fields']['attributes'] ?? [];
        if ( empty( $attributes ) || ! is_array( $attributes ) ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $product_attributes_meta = [];
        $wc_attributes = [];

        foreach ( $attributes as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            foreach ( $group as $key => $raw_value ) {

                if ( $raw_value === null || $raw_value === '' ) {
                    continue;
                }

                // Normalize values
                if ( is_array( $raw_value ) ) {
                    $values = array_filter( array_map( 'trim', $raw_value ) );
                } else {
                    $values = array_filter( array_map( 'trim', explode( ',', (string) $raw_value ) ) );
                }

                if ( empty( $values ) ) {
                    continue;
                }

                // Boolean normalization
                if ( $key === 'with_core_pulling_protection' ) {
                    $values = [ in_array( '1', $values, true ) ? 'yes' : 'no' ];
                }

                $attr_slug = sanitize_title( $key );
                $taxonomy  = 'pa_' . $attr_slug;

                // Ensure attribute taxonomy exists
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    $attr_id = wc_create_attribute( [
                        'name' => ucwords( str_replace( '_', ' ', $key ) ),
                        'slug' => $attr_slug,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ] );

                    if ( is_wp_error( $attr_id ) ) {
                        continue;
                    }

                    register_taxonomy(
                        $taxonomy,
                        [ 'product' ],
                        [
                            'hierarchical' => false,
                            'label' => ucwords( str_replace( '_', ' ', $key ) ),
                            'show_ui' => true,
                            'query_var' => true,
                            'rewrite' => [ 'slug' => $attr_slug ],
                        ]
                    );
                }

                // Create / collect terms
                $term_ids = [];

                foreach ( $values as $value ) {
                    if ( $value === '' ) {
                        continue;
                    }

                    $term = term_exists( $value, $taxonomy );
                    if ( ! $term ) {
                        $term = wp_insert_term( $value, $taxonomy );
                    }

                    if ( is_wp_error( $term ) ) {
                        continue;
                    }

                    $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                }

                if ( empty( $term_ids ) ) {
                    continue;
                }

                // ✅ Assign terms to product
                wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );

                // ✅ Save attribute meta config
                $product_attributes_meta[ $taxonomy ] = [
                    'name'         => $taxonomy,
                    'value'        => '',
                    'position'     => 0,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                ];

                // ✅ THIS IS THE CRITICAL FIX
                $wc_attr = new WC_Product_Attribute();
                $wc_attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
                $wc_attr->set_name( $taxonomy );
                $wc_attr->set_options( $term_ids ); // 🔥 REQUIRED
                $wc_attr->set_visible( true );
                $wc_attr->set_variation( false );

                $wc_attributes[] = $wc_attr;
            }
        }

        if ( empty( $product_attributes_meta ) ) {
            return false;
        }

        // Persist attribute config
        update_post_meta( $product_id, '_product_attributes', $product_attributes_meta );

        // Persist WC object attributes (WITH VALUES)
        $product->set_attributes( $wc_attributes );
        $product->save();

        // Clear caches
        clean_object_term_cache( $product_id, 'product' );
        wc_delete_product_transients( $product_id );

        return true;
    }

    /**
     * Update product related fields (order_color, order_model, matching_* fields) from API.
     * Updates each related field individually using update_field().
     */
    private static function update_product_related_from_api( $item ) {
        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $related_sections = [
            'order_colors',
            'order_models',
            'matching_products',
            'matching_knobrose',
            'matching_keyrose',
            'matching_pcrose',
            'matching_toiletrose',
            'must_have_products',
        ];

        $related_data = $item['fields']['related'] ?? [];
        if ( empty( $related_data ) || ! is_array( $related_data ) ) {
            return false;
        }

        foreach ( $related_sections as $section ) {
            if ( ! empty( $related_data[$section] ) && is_array( $related_data[$section] ) ) {
                foreach ( $related_data[$section] as $key => $value ) {
                    $clean_val = sanitize_text_field( $value );
                    $acf_field = "related_{$key}";
                    update_field( $acf_field, $clean_val, $product_id );
                }
            }
        }
        return true;
    }

    /**
     * Update product FAQ fields from API, mapping into ACF group fields.
     */
    private static function update_product_faq_from_api( $item ) {
        if ( empty( $item['fields']['crucial']['product_sku'] ) ) {
            return false;
        }

        $sku = sanitize_text_field( $item['fields']['crucial']['product_sku'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            return false;
        }

        $faq_data = $item['fields']['description']['faq'] ?? [];
        if ( empty( $faq_data ) || ! is_array( $faq_data ) ) {
            return false;
        }

        $description_group = get_field('description', $product_id);
        if ( ! is_array( $description_group ) ) {
            $description_group = [];
        }

        for ( $i = 1; $i <= 8; $i++ ) {
            $q_key = "faq_{$i}_question";
            $a_key = "faq_{$i}_answer";
            $q_val = isset($faq_data[$q_key]) ? sanitize_text_field($faq_data[$q_key]) : '';
            $a_val = isset($faq_data[$a_key]) ? sanitize_textarea_field($faq_data[$a_key]) : '';
            $description_group[$q_key] = $q_val;
            $description_group[$a_key] = $a_val;
        }

        update_field('description', $description_group, $product_id);
        return true;
    }

    /**
     * Intercepts standard WooCommerce REST requests BEFORE they process images.
     * WooCommerce processes images arrays in prepare_item_for_database BEFORE our object injection hook.
     * This intercepts it before that, processes the images using our dedup system, and replaces 'src' with 'id'
     * so WooCommerce's native image downloader is totally bypassed.
     */
    public static function intercept_rest_request( $response, $handler, $request ) {
        $route = $request->get_route();
        
        // Target /wc/v1, /wc/v2, /wc/v3 product endpoints for POST/PUT
        if ( strpos( $route, '/wc/v' ) !== false && strpos( $route, '/products' ) !== false && in_array( $request->get_method(), [ 'POST', 'PUT' ] ) ) {
            $images = $request->get_param('images');
            if ( ! empty( $images ) && is_array( $images ) ) {
                $modified_images = [];
                foreach ( $images as $image ) {
                    if ( empty( $image['id'] ) && ! empty( $image['src'] ) ) {
                        self::log( "Empire Sync: Intercepting REST request to pre-upload image src: " . $image['src'] );
                        
                        // Check if payload provided a meaningful name to use as filename
                        $name = $image['name'] ?? '';
                        $provided_filename = '';
                        if ( !empty($name) && strpos($name, 'picture') === false && strpos($name, 'image') === false ) {
                            $provided_filename = $name;
                        }

                        $attach_id = self::upload_from_ftp_path( $image['src'], 'image', $provided_filename );
                        if ( $attach_id ) {
                            // Assign ID and remove src so WC doesn't download it
                            $image['id'] = $attach_id;
                            unset( $image['src'] );
                        }
                    }
                    $modified_images[] = $image;
                }
                // Override the overall request params so WC engine uses our IDs
                $request->set_param( 'images', $modified_images );
            }
        }
        
        return $response;
    }

    /**
     * Filter called for WooCommerce REST API Product object payload injections.
     * - Download images/files
     * - Map by name (main vs secondary)
     * - Replace URLs in images + meta_data with attachment IDs
     * - Save into ACF "assets" group and WooCommerce product object.
     */
    private static function process_rest_media_from_payload( $prepared_product, $request, $creating ) {
        // Only act when we have a WC_Product instance (for the *_object filter)
        if ( ! ( $prepared_product instanceof WC_Product ) ) {
            return $prepared_product;
        }

        $params = $request->get_json_params();
        if ( empty( $params ) || ! is_array( $params ) ) {
            return $prepared_product;
        }

        $product_id = $prepared_product->get_id();
        $sku        = $prepared_product->get_sku();


        // Start with existing ACF assets if present
        $acf_assets = [];
        if ( $product_id && function_exists( 'get_field' ) ) {
            $existing_assets = get_field( 'assets', $product_id );
            if ( is_array( $existing_assets ) ) {
                $acf_assets = $existing_assets;
            }
        }

        $featured_id = 0;
        $gallery_ids = [];

        // Detect if we should reset collections based on payload contents
        $has_images         = ! empty( $params['images'] ) && is_array( $params['images'] );
        $has_meta_secondary = false;
        $has_meta_ambiance  = false;

        if ( ! empty( $params['meta_data'] ) && is_array( $params['meta_data'] ) ) {
            foreach ( $params['meta_data'] as $m ) {
                if ( ! isset( $m['key'] ) ) {
                    continue;
                }
                if ( strpos( $m['key'], 'assets_secondary_pictures' ) === 0 ) {
                    $has_meta_secondary = true;
                }
                if ( strpos( $m['key'], 'assets_ambiance_pictures' ) === 0 ) {
                    $has_meta_ambiance = true;
                }
            }
        }

        if ( $has_images || $has_meta_secondary ) {
            $acf_assets['secondary_pictures'] = [];
            // gallery_ids is already empty here, so no need to reset
        }
        if ( $has_images || $has_meta_ambiance ) {
            $acf_assets['ambiance_pictures'] = [];
        }


        /**
         * 1) Process images array from REST payload
         *    - Use "name" field to distinguish main vs secondary images.
         */
        if ( ! empty( $params['images'] ) && is_array( $params['images'] ) ) {

            foreach ( $params['images'] as $image ) {
                $src = $image['src'] ?? '';
                $id  = $image['id'] ?? 0;
                $name = $image['name'] ?? '';

                $attach_id = 0;
                if ( $id > 0 && get_post( $id ) ) {
                    $attach_id = (int) $id;
                    self::log( "Empire Sync: Using attachment ID {$attach_id} directly from REST payload." );
                } else if ( ! empty( $src ) ) {
                    $attach_id = self::upload_from_ftp_path( $src, 'image' );
                }

                if ( ! $attach_id ) {
                    continue;
                }

                // Decide if this is main or gallery image based on name
                if ( strpos( $name, 'main_picture' ) !== false ) {
                    $featured_id                = $attach_id;
                    $acf_assets['main_picture'] = $attach_id;
                } elseif ( strpos( $name, 'secondary_picture' ) !== false ) {
                    $gallery_ids[] = $attach_id;
                    if ( ! isset( $acf_assets['secondary_pictures'] ) || ! is_array( $acf_assets['secondary_pictures'] ) ) {
                        $acf_assets['secondary_pictures'] = [];
                    }
                    $acf_assets['secondary_pictures'][] = (int) $attach_id;
                } elseif ( strpos( $name, 'ambiance_picture' ) !== false ) {
                    if ( ! isset( $acf_assets['ambiance_pictures'] ) || ! is_array( $acf_assets['ambiance_pictures'] ) ) {
                        $acf_assets['ambiance_pictures'] = [];
                    }
                    $acf_assets['ambiance_pictures'][] = (int) $attach_id;
                } elseif ( strpos( $name, 'cat_image' ) !== false || strpos( $name, 'product_card_category_image' ) !== false ) {
                    $acf_assets['cat_image'] = (int) $attach_id;
                    $acf_assets['product_card_category_image'] = (int) $attach_id;
                    $prepared_product->update_meta_data( 'assets_cat_image', $attach_id );
                    $prepared_product->update_meta_data( 'assets_product_card_category_image', $attach_id );
                } elseif ( strpos( $name, 'technical_drawing' ) !== false ) {
                    $acf_assets['technical_drawing'] = $attach_id;
                    $prepared_product->update_meta_data( 'assets_technical_drawing', $attach_id );
                } else {
                    // Fallback: if we already have a featured image, treat as gallery
                    if ( $featured_id ) {
                        $gallery_ids[] = $attach_id;
                        if ( ! isset( $acf_assets['secondary_pictures'] ) || ! is_array( $acf_assets['secondary_pictures'] ) ) {
                            $acf_assets['secondary_pictures'] = [];
                        }
                        $acf_assets['secondary_pictures'][] = $attach_id;
                    } else {
                        $featured_id                = $attach_id;
                        $acf_assets['main_picture'] = $attach_id;
                    }
                }
            }
        }

        /**
         * 2) Process meta_data assets:
         *    - Upload URLs and replace values with attachment IDs / ID arrays
         *    - Also mirror into ACF "assets" group for easier usage.
         */
        if ( ! empty( $params['meta_data'] ) && is_array( $params['meta_data'] ) ) {
            $asset_meta_keys_single = [
                'assets_product_card_category_image' => 'product_card_category_image',
                'assets_cat_image'                   => 'cat_image',
                'assets_main_picture'                => 'main_picture',
                'assets_manual_pdf'                  => 'manual_pdf',
                'assets_technical_drawing'           => 'technical_drawing',
                'assets_installation_guide'          => 'installation_guide',
                'assets_product_certificate'         => 'product_certificate',
                'assets_care_instructions'           => 'care_instructions',
            ];

            $asset_meta_keys_multi = [
                'assets_secondary_pictures' => 'secondary_pictures',
                'assets_ambiance_pictures'  => 'ambiance_pictures',
            ];

            $new_meta_data = [];

            foreach ( $params['meta_data'] as $meta_entry ) {
                if ( ! isset( $meta_entry['key'] ) ) {
                    continue;
                }

                $key   = $meta_entry['key'];
                $value = $meta_entry['value'] ?? '';

                // Single asset fields (one file/image)
                if ( isset( $asset_meta_keys_single[ $key ] ) ) {
                    if ( ! is_string( $value ) || $value === '' ) {
                        $new_meta_data[] = $meta_entry;
                        continue;
                    }

                    $paths = array_filter( array_map( 'trim', explode( ',', $value ) ) );
                    if ( empty( $paths ) ) {
                        $new_meta_data[] = $meta_entry;
                        continue;
                    }

                    $first = reset( $paths );
                    
                    // Determine type (image vs file) for meta fields
                    $is_image_field = in_array( $key, [ 'assets_main_picture', 'assets_cat_image', 'assets_product_card_category_image' ] );
                    $attach_id = self::upload_from_ftp_path( $first, $is_image_field ? 'image' : 'file' );

                    if ( $attach_id ) {
                        $acf_key = $asset_meta_keys_single[ $key ];
                        
                        // If it's a main picture from meta, and we don't have a featured image yet, set it
                        if ( $key === 'assets_main_picture' && ! $featured_id ) {
                            $featured_id = $attach_id;
                        }

                        if ( $acf_key === 'product_card_category_image' || $acf_key === 'cat_image' ) {
                            $acf_assets['cat_image'] = (int) $attach_id;
                            $acf_assets['product_card_category_image'] = (int) $attach_id;
                        } else {
                            $acf_assets[ $acf_key ]  = (int) $attach_id;
                        }

                        $new_meta_data[] = [
                            'key'   => $key,
                            'value' => $attach_id,
                        ];
                        $prepared_product->update_meta_data( $key, $attach_id );
                    } else {
                        $new_meta_data[] = $meta_entry;
                    }

                // NEW: Handle indexed keys like assets_secondary_pictures_1, assets_ambiance_pictures_1
                } elseif ( preg_match( '/^assets_(secondary_pictures|ambiance_pictures)_(\d+)$/', $key, $matches ) ) {
                    $acf_key = $matches[1]; // secondary_pictures or ambiance_pictures

                    if ( ! is_string( $value ) || $value === '' ) {
                        $new_meta_data[] = $meta_entry;
                        continue;
                    }

                    $attach_id = self::upload_from_ftp_path( $value, 'image' );
                    if ( $attach_id ) {
                        if ( ! isset( $acf_assets[ $acf_key ] ) || ! is_array( $acf_assets[ $acf_key ] ) ) {
                            $acf_assets[ $acf_key ] = [];
                        }
                        $acf_assets[ $acf_key ][] = $attach_id;

                        // If it's secondary pictures, also add to WooCommerce gallery
                        if ( $acf_key === 'secondary_pictures' ) {
                            $gallery_ids[] = $attach_id;
                        }

                        $new_meta_data[] = [
                            'key'   => $key,
                            'value' => $attach_id,
                        ];
                        $prepared_product->update_meta_data( $key, $attach_id );
                    } else {
                        $new_meta_data[] = $meta_entry;
                    }

                // Multi-asset fields (comma-separated list of URLs/paths)
                } elseif ( isset( $asset_meta_keys_multi[ $key ] ) ) {
                    if ( ! is_string( $value ) || $value === '' ) {
                        $new_meta_data[] = $meta_entry;
                        continue;
                    }

                    $paths = array_filter( array_map( 'trim', explode( ',', $value ) ) );
                    if ( empty( $paths ) ) {
                        $new_meta_data[] = $meta_entry;
                        continue;
                    }

                    $uploaded_ids = [];
                    foreach ( $paths as $path ) {
                        $id = self::upload_from_ftp_path( $path, 'image' );
                        if ( $id ) {
                            $uploaded_ids[] = $id;
                            // Add to gallery if it's secondary pictures from meta
                            if ( $key === 'assets_secondary_pictures' ) {
                                $gallery_ids[] = $id;
                            }
                        }
                    }

                    if ( ! empty( $uploaded_ids ) ) {
                        $acf_key                = $asset_meta_keys_multi[ $key ];
                        $acf_assets[ $acf_key ] = $uploaded_ids;
                        $new_meta_data[]        = [
                            'key'   => $key,
                            'value' => implode(',', $uploaded_ids),
                        ];
                        $prepared_product->update_meta_data( $key, implode(',', $uploaded_ids) );
                    } else {
                        $new_meta_data[] = $meta_entry;
                    }

                // Non-asset meta fields: keep as-is
                } else {
                    $new_meta_data[] = $meta_entry;
                }
            }

            // Reflect adjusted meta_data back into the request
            $request->set_param( 'meta_data', $new_meta_data );
        }

        /**
         * 3) Apply final images to the product object and request params
         *    This ensures WooCommerce correctly links the images and doesn't clear them if the images array was empty.
         */
        if ( $featured_id ) {
            $prepared_product->set_image_id( $featured_id );
            if ( $product_id ) {
                set_post_thumbnail( $product_id, $featured_id );
            }
        }

        if ( ! empty( $gallery_ids ) ) {
            $gallery_ids = array_values( array_unique( $gallery_ids ) );
            $prepared_product->set_gallery_image_ids( $gallery_ids );
            if ( $product_id ) {
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
            }
        }

        // We must store important info to be retrieved in the after-save hook
        // Use SKU as key because product_id might be 0 for new products
        $storage_key = !empty($sku) ? $sku : (string)$product_id;
        self::$rest_acf_assets[$storage_key] = $acf_assets;

        $final_request_images = [];
        if ( $featured_id ) {
            $final_request_images[] = [ 'id' => (int)$featured_id ];
        }
        $gallery_ids = array_unique($gallery_ids);
        foreach ( $gallery_ids as $gid ) {
            if ( $gid != $featured_id ) {
                $final_request_images[] = [ 'id' => (int)$gid ];
            }
        }
        
        // IMPORTANT: Always set the images param if we processed the payload, 
        // even if empty, to prevent WooCommerce native sideloading from kicking in.
        if ( $has_images ) {
            $request->set_param( 'images', $final_request_images );
            self::log( "Empire Sync: Overriding REST images parameter with " . count($final_request_images) . " IDs for SKU: {$sku}" );
        }

        return $prepared_product;
    }

    /**
     * Finalize ACF asset synchronization after the product has been saved to the DB.
     * This ensures the product ID is persistent and available for all ACF update calls.
     */
    public static function after_rest_product_save( $product, $request, $creating ) {
        if ( ! ( $product instanceof WC_Product ) ) {
            return;
        }

        $product_id = $product->get_id();
        $sku        = $product->get_sku();
        self::log( "Empire Sync: Finalizing REST product save for SKU: {$sku} (ID: {$product_id})" );

        if ( ! function_exists( 'update_field' ) ) {
            return;
        }

        $acf_assets = [];
        // Load existing to avoid clearing fields not present in this specific request
        // (Though usually we want the REST payload to be the source of truth)
        $existing = get_field( 'assets', $product_id );
        if ( is_array( $existing ) ) {
            $acf_assets = $existing;
        }

        $single_map = [
            'assets_main_picture'                => 'main_picture',
            'assets_manual_pdf'                  => 'manual_pdf',
            'assets_technical_drawing'           => 'technical_drawing',
            'assets_installation_guide'          => 'installation_guide',
            'assets_product_certificate'         => 'product_certificate',
            'assets_care_instructions'           => 'care_instructions',
            'assets_cat_image'                   => 'cat_image',
            'assets_product_card_category_image' => 'cat_image',
            'cat_image'                          => 'cat_image',
            'product_card_category_image'        => 'cat_image',
        ];

        $secondary_ids = [];
        $ambiance_ids  = [];
        $has_asset_meta = false;

        // Iterate over meta data to collect finalized attachment IDs
        foreach ( $product->get_meta_data() as $meta ) {
            $key = $meta->key;
            $val = $meta->value;

            if ( isset( $single_map[ $key ] ) ) {
                $raw_val = (is_array($val) && !empty($val)) ? reset($val) : $val;
                $val_id  = is_numeric($raw_val) ? (int) $raw_val : 0;
                
                if ( $val_id > 0 ) {
                    $acf_assets[ $single_map[ $key ] ] = $val_id;
                    
                    // Fallback for category image naming variations
                    if ( $key === 'assets_cat_image' || $key === 'assets_product_card_category_image' || $key === 'cat_image' || $key === 'product_card_category_image' ) {
                        $acf_assets['cat_image'] = $val_id;
                        $acf_assets['product_card_category_image'] = $val_id;
                    }
                    $has_asset_meta = true;
                }
            } elseif ( preg_match( '/^assets_secondary_pictures(_\d+)?$/', $key ) ) {
                if ( ! empty( $val ) ) {
                    if ( is_array( $val ) ) {
                        $secondary_ids = array_merge( $secondary_ids, $val );
                    } elseif ( strpos( $val, ',' ) !== false ) {
                        $secondary_ids = array_merge( $secondary_ids, explode( ',', $val ) );
                    } else {
                        $secondary_ids[] = $val;
                    }
                    $has_asset_meta = true;
                }
            } elseif ( preg_match( '/^assets_ambiance_pictures(_\d+)?$/', $key ) ) {
                if ( ! empty( $val ) ) {
                    if ( is_array( $val ) ) {
                        $ambiance_ids = array_merge( $ambiance_ids, $val );
                    } elseif ( strpos( $val, ',' ) !== false ) {
                        $ambiance_ids = array_merge( $ambiance_ids, explode( ',', $val ) );
                    } else {
                        $ambiance_ids[] = $val;
                    }
                    $has_asset_meta = true;
                }
            }
        }

        if ( ! empty( $secondary_ids ) ) {
            $acf_assets['secondary_pictures'] = array_values( array_unique( array_filter( $secondary_ids ) ) );
        }
        if ( ! empty( $ambiance_ids ) ) {
            $acf_assets['ambiance_pictures'] = array_values( array_unique( array_filter( $ambiance_ids ) ) );
        }

        if ( $has_asset_meta ) {
            $saved = update_field( 'assets', $acf_assets, $product_id );
        } else {
        }

    }

    // public static function log_rest_product_query($args, $request) {
    //     if (class_exists('WC_Logger')) {
    //         $logger = wc_get_logger();
    //         $logger->info(
    //             "REST PRODUCT QUERY DATA: " . json_encode($request->get_params(), JSON_UNESCAPED_UNICODE),
    //             ['source' => 'empire-product-api']
    //         );
    //         $logger->info("Request Body: " . $request->get_body(), ['source'=>'empire-product-api']);
    //         $logger->info("JSON Params: " . json_encode($request->get_json_params(), JSON_UNESCAPED_UNICODE), ['source'=>'empire-product-api']);
    //     }
    //     return $args;
    // }

    public static function log_rest_product_update($prepared_product, $request, $creating) {
        $params = $request->get_json_params() ?: $request->get_params();
        $sku = ( $prepared_product instanceof WC_Product ) ? $prepared_product->get_sku() : 'Unknown';
        $name = ( $prepared_product instanceof WC_Product ) ? $prepared_product->get_name() : 'Unknown';

        // Build a structured summary
        $summary = [];
        $summary[] = "--- REST API PRODUCT DEBUG ---";
        $summary[] = "Action: " . ($creating ? 'Create' : 'Update');
        $summary[] = "SKU: " . $sku;
        $summary[] = "Name: " . $name;
        
        if (isset($params['regular_price'])) $summary[] = "Price: " . $params['regular_price'];
        if (isset($params['status'])) $summary[] = "Status: " . $params['status'];
        if (isset($params['stock_quantity'])) $summary[] = "Stock: " . $params['stock_quantity'];

        // Categories
        if (!empty($params['categories']) && is_array($params['categories'])) {
            $cat_names = array_map(function($cat) {
                return ($cat['name'] ?? 'ID:' . ($cat['id'] ?? '?'));
            }, $params['categories']);
            $summary[] = "Categories: " . implode(', ', $cat_names);
        }

        // Images
        if (!empty($params['images']) && is_array($params['images'])) {
            $image_urls = array_filter(array_column($params['images'], 'src'));
            if (!empty($image_urls)) {
                $summary[] = "Images: " . implode(', ', $image_urls);
            }
        }

        // Attributes
        if (!empty($params['attributes']) && is_array($params['attributes'])) {
            $summary[] = "Attributes:";
            foreach ($params['attributes'] as $attr) {
                $label = $attr['name'] ?? 'Unknown';
                $opts  = is_array($attr['options'] ?? '') ? implode(', ', $attr['options']) : ($attr['options'] ?? '');
                $summary[] = "  - {$label}: {$opts}";
            }
        }

        // Meta Data (Key Preview)
        if (!empty($params['meta_data']) && is_array($params['meta_data'])) {
            $summary[] = "Meta Data:";
            foreach ($params['meta_data'] as $meta) {
                $key = $meta['key'] ?? 'unknown';
                $val = is_array($meta['value'] ?? '') ? json_encode($meta['value']) : ($meta['value'] ?? '');
                // Truncate very long values in the summary
                if (strlen($val) > 120) $val = substr($val, 0, 117) . "...";
                $summary[] = "  [{$key}] => {$val}";
            }
        }
        $summary[] = "--- END DEBUG ---";

        $log_content = implode("\n", $summary);

        // 1. System Error Log (Individually per line for easy reading)
        foreach ($summary as $line) {
            error_log($line);
        }
        error_log("Full Payload: " . json_encode($params, JSON_UNESCAPED_UNICODE));

        // 2. WooCommerce Logger
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info($log_content . "\nFull Payload: " . json_encode($params, JSON_UNESCAPED_UNICODE), ['source' => 'empire-rest-debug']);
        }

        // Process media (images + meta_data URLs) into attachment IDs and ACF before WooCommerce saves
        $prepared_product = self::process_rest_media_from_payload( $prepared_product, $request, $creating );

        return $prepared_product;
    }
}
    

