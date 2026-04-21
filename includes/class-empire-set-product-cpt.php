<?php
/**
 * Register Set Product Custom Post Type
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Empire_Set_Product_CPT {

    /**
     * Initialize the class
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
        add_action( 'acf/save_post', [ __CLASS__, 'log_rest_api_format' ], 99 );
        // Also fire on standard save_post just in case ACF isn't hooked yet
        add_action( 'save_post_set_product', [ __CLASS__, 'log_rest_api_format_fallback' ], 99, 3 );
    }

    /**
     * Register the Set Product Custom Post Type
     */
    public static function register_post_type() {
        $labels = [
            'name'                  => _x( 'Set Products', 'Post type general name', 'empire-product-api' ),
            'singular_name'         => _x( 'Set Product', 'Post type singular name', 'empire-product-api' ),
            'menu_name'             => _x( 'Set Products', 'Admin Menu text', 'empire-product-api' ),
            'name_admin_bar'        => _x( 'Set Product', 'Add New on Toolbar', 'empire-product-api' ),
            'add_new'               => __( 'Add New', 'empire-product-api' ),
            'add_new_item'          => __( 'Add New Set Product', 'empire-product-api' ),
            'new_item'              => __( 'New Set Product', 'empire-product-api' ),
            'edit_item'             => __( 'Edit Set Product', 'empire-product-api' ),
            'view_item'             => __( 'View Set Product', 'empire-product-api' ),
            'all_items'             => __( 'All Set Products', 'empire-product-api' ),
            'search_items'          => __( 'Search Set Products', 'empire-product-api' ),
            'parent_item_colon'     => __( 'Parent Set Products:', 'empire-product-api' ),
            'not_found'             => __( 'No set products found.', 'empire-product-api' ),
            'not_found_in_trash'    => __( 'No set products found in Trash.', 'empire-product-api' ),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => [ 'slug' => 'set-product' ],
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 56, // Under WooCommerce Products typically
            'menu_icon'          => 'dashicons-products',
            // Removed 'editor' to provide a clean area for ACF fields, making it look structured like a product
            'supports'           => [ 'title', 'thumbnail', 'excerpt', 'custom-fields' ],
            'show_in_rest'       => false, // False forces the Classic Editor, which is typical for heavily ACF-driven product-like screens
        ];

        register_post_type( 'set_product', $args );
    }

    /**
     * Register Custom REST API routes for updating Set Products
     */
    public static function register_rest_routes() {
        register_rest_route( 'custom/v1', '/set-products(?:/(?P<slug>[a-zA-Z0-9_\-]+))?', array(
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'update_set_product_webhook' ],
            'permission_callback' => 'woocommerce_basic_permissions' // Using the existing WC REST auth check from main plugin
        ));
    }

    /**
     * Webhook callback to update or create the set product data
     */
    public static function update_set_product_webhook( $request ) {
        $slug = $request->get_param( 'slug' );
        $params = $request->get_json_params();
        
        $id = 0;
        if ( ! empty( $slug ) ) {
            // Find existing Set Product by custom field 'slug'
            $existing_posts = get_posts([
                'post_type'      => 'set_product',
                'meta_key'       => 'slug',
                'meta_value'     => $slug,
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids' // Only fetch ID for efficiency
            ]);
            if ( ! empty( $existing_posts ) ) {
                $id = $existing_posts[0];
            }
        }

        $is_creation = empty( $id );

        $post_args = [];
        if ( isset( $params['name'] ) || isset( $params['title'] ) ) {
            $post_args['post_title'] = sanitize_text_field( $params['name'] ?? $params['title'] );
        }
        if ( isset( $params['status'] ) ) {
            $post_args['post_status'] = sanitize_text_field( $params['status'] );
        }

        if ( $is_creation ) {
            // Creation Mode
            $post_args['post_type'] = 'set_product';
            if ( empty( $post_args['post_title'] ) ) {
                $post_args['post_title'] = ! empty( $slug ) ? 'Set Product ' . $slug : 'New Set Product API';
            }
            if ( empty( $post_args['post_status'] ) ) {
                $post_args['post_status'] = 'publish';
            }

            $id = wp_insert_post( $post_args );
            if ( is_wp_error( $id ) || $id === 0 ) {
                return new WP_Error( 'create_failed', 'Failed to create Set Product', [ 'status' => 500 ] );
            }
            
            // Save the slug as a custom field so we can look it up next time
            if ( ! empty( $slug ) ) {
                update_post_meta( $id, 'slug', sanitize_text_field( $slug ) );
            }
        } else {
            // Update Mode
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'set_product' ) {
                return new WP_Error( 'not_found', 'Set Product not found', [ 'status' => 404 ] );
            }

            $post_args['ID'] = $id;
            if ( count( $post_args ) > 1 ) {
                wp_update_post( $post_args );
            }
            
            // Re-save the slug meta just to be safe
            if ( ! empty( $slug ) ) {
                update_post_meta( $id, 'slug', sanitize_text_field( $slug ) );
            }
        }

        // 2. Update ACF fields or standard meta
        // E.g. payload: { "acf": { "set_price": "19.99", "items": "..." } }
        if ( isset( $params['acf'] ) && is_array( $params['acf'] ) ) {
            foreach ( $params['acf'] as $key => $value ) {
                // If it's an image field passed as a URL, we can use the existing helper to upload
                if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) && strpos( $key, 'image' ) !== false ) {
                    $attachment_id = Empire_Product_API::upload_from_ftp_path( $value, 'image' );
                    if ( $attachment_id ) {
                        $value = $attachment_id;
                    }
                }
                update_field( $key, $value, $id );
                $updated_keys[] = $key;
            }
        }

        // Catch-all for standard meta data (similar to WooCommerce REST API format)
        if ( isset( $params['meta_data'] ) && is_array( $params['meta_data'] ) ) {
            foreach ( $params['meta_data'] as $meta ) {
                if ( isset( $meta['key'], $meta['value'] ) ) {
                    update_post_meta( $id, sanitize_text_field( $meta['key'] ), $meta['value'] );
                    $updated_keys[] = $meta['key'];
                }
            }
        }

        return new WP_REST_Response( [
            'status'  => 'success',
            'message' => $is_creation ? 'Set Product created successfully' : 'Set Product updated',
            'id'      => $id, 
            'updated_fields' => $updated_keys
        ], $is_creation ? 201 : 200 );
    }

    /**
     * Log REST API format when ACF saves the post
     */
    public static function log_rest_api_format( $post_id ) {
        if ( get_post_type( $post_id ) !== 'set_product' ) {
            return;
        }
        self::generate_and_log_format( $post_id );
    }

    /**
     * Fallback log for non-ACF saves
     */
    public static function log_rest_api_format_fallback( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        // Avoid double-logging if ACF is active because ACF will fire the other hook
        if ( isset( $_POST['acf'] ) ) {
            return; 
        }
        self::generate_and_log_format( $post_id );
    }

    /**
     * Helper to generate the JSON and push it to debug.log
     */
    private static function generate_and_log_format( $post_id ) {
        $post = get_post( $post_id );
        
        $acf_data = new stdClass();
        if ( function_exists( 'get_fields' ) ) {
            $fields = get_fields( $post_id );
            if ( $fields ) {
                $acf_data = $fields;
            }
        }

        $payload = [
            'name'   => $post->post_title,
            'status' => $post->post_status,
            'acf'    => $acf_data
        ];

        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $slug_meta = get_post_meta( $post_id, 'slug', true );
            $identifier = ! empty( $slug_meta ) ? $slug_meta : $post_id;

            $log_message  = "=======================================================\n";
            $log_message .= "🚀 SET PRODUCT REST API PAYLOAD GENERATOR 🚀\n";
            $log_message .= "URL:    /wp-json/custom/v1/set-products/" . $identifier . "\n";
            $log_message .= "METHOD: POST\n";
            $log_message .= "HEADER: Authorization: Basic [base64 consumer_key:consumer_secret]\n";
            $log_message .= "PAYLOAD JSON:\n";
            $log_message .= wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
            $log_message .= "=======================================================\n";
            
            $logger->debug( $log_message, [ 'source' => 'set-product-rest-api' ] );
        } else {
            error_log( 'SET PRODUCT API PAYLOAD: ' . wp_json_encode( $payload ) );
        }
    }
}
