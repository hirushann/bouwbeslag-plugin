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
        add_action( 'save_post_set_product', [ __CLASS__, 'log_rest_api_format_fallback' ], 99, 3 );
    }

    public static function register_post_type() {
        $labels = [
            'name'                  => _x( 'Set Products', 'Post type general name', 'empire-product-api' ),
            'singular_name'         => _x( 'Set Product', 'Post type singular name', 'empire-product-api' ),
            'menu_name'             => _x( 'Set Products', 'Admin Menu text', 'empire-product-api' ),
            'supports'           => [ 'title', 'thumbnail', 'excerpt', 'custom-fields' ],
            'taxonomies'         => [ 'product_cat', 'product_tag' ],
            'public'             => true,
            'show_ui'            => true,
            'menu_icon'          => 'dashicons-products',
            'show_in_rest'       => false,
        ];
        register_post_type( 'set_product', [ 'labels' => $labels, 'public' => true, 'supports' => $labels['supports'], 'taxonomies' => $labels['taxonomies'], 'show_in_rest' => false, 'menu_icon' => 'dashicons-products' ] );
    }

    public static function register_rest_routes() {
        register_rest_route( 'custom/v1', '/set-products(?:/(?P<slug>[a-zA-Z0-9_\-]+))?', array(
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'update_set_product_webhook' ],
            'permission_callback' => 'woocommerce_basic_permissions'
        ));
    }

    public static function update_set_product_webhook( $request ) {
        $slug = $request->get_param( 'slug' );
        $params = $request->get_json_params();
        $logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

        if ( empty( $params ) ) {
            return new WP_Error( 'no_data', 'No data provided', [ 'status' => 400 ] );
        }

        // Use slug from payload if not in URL
        if ( empty( $slug ) && ! empty( $params['slug'] ) ) {
            $slug = $params['slug'];
        }

        $id = 0;
        if ( ! empty( $slug ) ) {
            if ( is_numeric( $slug ) ) {
                $check_post = get_post( intval( $slug ) );
                if ( $check_post && $check_post->post_type === 'set_product' ) $id = $check_post->ID;
            }
            if ( ! $id ) {
                $existing = get_posts([ 
                    'post_type' => 'set_product', 
                    'meta_key' => 'slug', 
                    'meta_value' => $slug, 
                    'posts_per_page' => 1, 
                    'post_status' => 'any', 
                    'fields' => 'ids' 
                ]);
                if ( ! empty( $existing ) ) $id = $existing[0];
            }
            if ( ! $id ) {
                $existing = get_posts([
                    'post_type' => 'set_product',
                    'name' => sanitize_title($slug),
                    'posts_per_page' => 1,
                    'post_status' => 'any',
                    'fields' => 'ids'
                ]);
                if ( ! empty( $existing ) ) $id = $existing[0];
            }
        }

        // Try by SKU if still not found
        if ( ! $id && ! empty( $params['sku'] ) ) {
             $existing = get_posts([ 
                'post_type' => 'set_product', 
                'meta_key' => '_sku', 
                'meta_value' => sanitize_text_field( $params['sku'] ), 
                'posts_per_page' => 1, 
                'post_status' => 'any', 
                'fields' => 'ids' 
            ]);
            if ( ! empty( $existing ) ) $id = $existing[0];
        }

        $is_creation = empty( $id );
        $post_args = [
            'post_type' => 'set_product',
        ];

        if ( isset( $params['name'] ) ) $post_args['post_title'] = sanitize_text_field( $params['name'] );
        if ( isset( $params['status'] ) ) $post_args['post_status'] = sanitize_text_field( $params['status'] );
        if ( isset( $params['description'] ) ) $post_args['post_content'] = wp_kses_post( $params['description'] );
        if ( isset( $params['short_description'] ) ) $post_args['post_excerpt'] = wp_kses_post( $params['short_description'] );
        if ( isset( $params['slug'] ) ) $post_args['post_name'] = sanitize_title( $params['slug'] );

        if ( $is_creation ) {
            if ( empty( $post_args['post_title'] ) ) $post_args['post_title'] = $slug ?: 'New Set Product';
            $id = wp_insert_post( $post_args );
            if ( ! empty( $slug ) ) update_post_meta( $id, 'slug', $slug );
        } else {
            $post_args['ID'] = $id;
            wp_update_post( $post_args );
        }

        if ( $logger ) {
             $logger->debug( "Processing Set Product ID: " . $id . " for slug: " . $slug, [ 'source' => 'set-product-webhook' ] );
        }

        // Standard WooCommerce / Meta Fields
        if ( isset( $params['sku'] ) ) update_post_meta( $id, '_sku', sanitize_text_field( $params['sku'] ) );
        if ( isset( $params['regular_price'] ) ) {
            update_post_meta( $id, '_regular_price', sanitize_text_field( $params['regular_price'] ) );
            update_post_meta( $id, '_price', sanitize_text_field( $params['regular_price'] ) );
        }
        if ( isset( $params['manage_stock'] ) ) update_post_meta( $id, '_manage_stock', $params['manage_stock'] ? 'yes' : 'no' );
        if ( isset( $params['stock_quantity'] ) ) update_post_meta( $id, '_stock', (int)$params['stock_quantity'] );
        if ( isset( $params['stock_status'] ) ) update_post_meta( $id, '_stock_status', sanitize_text_field( $params['stock_status'] ) );
        if ( isset( $params['catalog_visibility'] ) ) update_post_meta( $id, '_visibility', sanitize_text_field( $params['catalog_visibility'] ) );
        if ( isset( $params['type'] ) ) update_post_meta( $id, '_product_type', sanitize_text_field( $params['type'] ) );

        // Categories Handling: Convert to lowercase and match
        if ( ! empty( $params['categories'] ) && is_array( $params['categories'] ) ) {
            $category_ids = [];
            foreach ( $params['categories'] as $cat ) {
                $cat_name = isset( $cat['name'] ) ? $cat['name'] : '';
                if ( empty( $cat_name ) ) continue;

                $search_name = strtolower( trim( $cat_name ) );
                
                // Try matching by name directly (usually case-insensitive in WP)
                $term = get_term_by( 'name', $cat_name, 'product_cat' );
                
                if ( ! $term ) {
                    // Try matching by slug (which is lowercase)
                    $term = get_term_by( 'slug', sanitize_title( $search_name ), 'product_cat' );
                }

                if ( ! $term ) {
                    // Manual search in case term name matching is strict
                    $all_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
                    if ( ! is_wp_error( $all_cats ) ) {
                        foreach ( $all_cats as $c ) {
                            if ( strtolower( trim( $c->name ) ) === $search_name || $c->slug === sanitize_title( $search_name ) ) {
                                $term = $c;
                                break;
                            }
                        }
                    }
                }

                if ( $term ) {
                    $category_ids[] = (int) $term->term_id;
                }
            }
            if ( ! empty( $category_ids ) ) {
                wp_set_object_terms( $id, array_unique( $category_ids ), 'product_cat' );
            }
        }

        // Product Set (ACF Repeater) Handling
        $product_set_data = null;
        if ( isset( $params['product_set'] ) && is_array( $params['product_set'] ) ) {
            $product_set_data = $params['product_set'];
        } elseif ( isset( $params['acf']['crucial_data']['product_set'] ) ) {
            $product_set_data = $params['acf']['crucial_data']['product_set'];
        } elseif ( isset( $params['acf']['product_set'] ) ) {
            $product_set_data = $params['acf']['product_set'];
        }

        if ( is_array( $product_set_data ) ) {
            $repeater_rows = [];
            foreach ( $product_set_data as $index => $row ) {
                $found_product_id = 0;
                if ( ! empty( $row['sku'] ) ) {
                    $found_product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $row['sku'] ) : 0;
                }
                if ( ! $found_product_id && ! empty( $row['bouwbeslag_id'] ) ) {
                    $lookup = get_posts([ 
                        'post_type' => 'product', 
                        'meta_key' => 'bouwbeslag_id', 
                        'meta_value' => $row['bouwbeslag_id'], 
                        'fields' => 'ids', 
                        'posts_per_page' => 1 
                    ]);
                    if ( ! empty( $lookup ) ) $found_product_id = $lookup[0];
                }
                if ( ! $found_product_id && ! empty( $row['product'] ) ) {
                    $found_product_id = (int) $row['product'];
                }

                $qty = isset( $row['quantity'] ) ? (float) $row['quantity'] : 1;

                // ACF format for update_field with keys
                $repeater_rows[] = [
                    'field_69e70e683b597' => $found_product_id, // product subfield
                    'field_69e70e783b598' => $qty                // quantity subfield
                ];

                // Legacy Meta format for UI visibility
                $meta_prefix = "crucial_data_product_set_{$index}_";
                update_post_meta( $id, $meta_prefix . "product", $found_product_id );
                update_post_meta( $id, "_" . $meta_prefix . "product", 'field_69e70e683b597' );
                update_post_meta( $id, $meta_prefix . "quantity", $qty );
                update_post_meta( $id, "_" . $meta_prefix . "quantity", 'field_69e70e783b598' );
            }
            
            update_field( 'field_69e70e553b596', $repeater_rows, $id ); // product_set repeater key
            update_post_meta( $id, 'crucial_data_product_set', count( $repeater_rows ) );
            update_post_meta( $id, '_crucial_data_product_set', 'field_69e70e553b596' );
        }

        // Generic ACF fallback for other fields
        if ( isset( $params['acf'] ) && is_array( $params['acf'] ) ) {
            foreach ( $params['acf'] as $key => $value ) {
                if ( in_array( $key, [ 'crucial_data', 'product_set' ] ) ) continue;
                update_field( $key, $value, $id );
            }
        }

        return new WP_REST_Response( [ 'status' => 'success', 'id' => $id ], $is_creation ? 201 : 200 );
    }

    private static function dump_fields_recursive($fields, $level = 0) {
        $output = "";
        foreach ($fields as $f) {
            $indent = str_repeat("  ", $level);
            $output .= "\n$indent- " . $f['name'] . " (" . $f['key'] . ") [" . $f['type'] . "]";
            if (isset($f['sub_fields']) && !empty($f['sub_fields'])) $output .= self::dump_fields_recursive($f['sub_fields'], $level + 1);
        }
        return $output;
    }

    private static function sanitize_acf_data( $data ) {
        if ( ! is_array( $data ) && ! is_object( $data ) ) return $data;
        if ( is_array( $data ) && isset( $data['ID'] ) ) return (int) $data['ID'];
        $sanitized = [];
        foreach ( $data as $k => $v ) $sanitized[$k] = self::sanitize_acf_data($v);
        return $sanitized;
    }

    private static function resolve_field_key( $name ) {
        if ( ! function_exists( 'acf_get_field' ) ) return $name;
        $field = acf_get_field( $name );
        return ( $field && isset( $field['key'] ) ) ? $field['key'] : $name;
    }

    public static function log_rest_api_format( $post_id ) {
        if ( get_post_type( $post_id ) === 'set_product' ) self::generate_and_log_format( $post_id );
    }

    public static function log_rest_api_format_fallback( $post_id, $post, $update ) {
        if ( !defined( 'DOING_AUTOSAVE' ) && get_post_type($post_id) === 'set_product' && !isset($_POST['acf']) ) self::generate_and_log_format( $post_id );
    }

    private static function generate_and_log_format( $post_id ) {
        $post = get_post( $post_id );
        $payload = [ 'name' => $post->post_title, 'status' => $post->post_status, 'acf' => function_exists('get_fields') ? get_fields($post_id) : [] ];
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $slug = get_post_meta( $post_id, 'slug', true ) ?: $post_id;
            $log  = "URL: /wp-json/custom/v1/set-products/$slug\nPAYLOAD:\n" . wp_json_encode($payload, JSON_PRETTY_PRINT);
            $logger->debug( $log, [ 'source' => 'set-product-rest-api' ] );
        }
    }
}
?>
