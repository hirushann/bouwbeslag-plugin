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

        // Known keys from diagnostic logs
        $key_map = [
            'crucial_data'                => 'field_69e70bc0870c0',
            'supplier_stock'              => 'field_69e70bd6870c1',
            'categories'                  => 'field_69e70c1aa9fcc',
            'delivery_if_stock'           => 'field_69e70d163b593',
            'delivery_if_no_stock'        => 'field_69e70d243b594',
            'delivery_if_low_but_1_stock' => 'field_69e70d2c3b595',
            'product_set'                 => 'field_69e70e553b596'
        ];
        
        $id = 0;
        if ( ! empty( $slug ) ) {
            if ( is_numeric( $slug ) ) {
                $check_post = get_post( intval( $slug ) );
                if ( $check_post && $check_post->post_type === 'set_product' ) $id = $check_post->ID;
            }
            if ( ! $id ) {
                $existing = get_posts([ 'post_type' => 'set_product', 'meta_key' => 'slug', 'meta_value' => $slug, 'posts_per_page' => 1, 'post_status' => 'any', 'fields' => 'ids' ]);
                if ( ! empty( $existing ) ) $id = $existing[0];
            }
        }

        $is_creation = empty( $id );
        $post_args = [];
        if ( isset( $params['name'] ) ) $post_args['post_title'] = sanitize_text_field( $params['name'] );
        if ( isset( $params['status'] ) ) $post_args['post_status'] = sanitize_text_field( $params['status'] );

        if ( $is_creation ) {
            $post_args['post_type'] = 'set_product';
            if ( empty( $post_args['post_title'] ) ) $post_args['post_title'] = $slug ?: 'New Set Product';
            $id = wp_insert_post( $post_args );
            if ( ! empty( $slug ) ) update_post_meta( $id, 'slug', $slug );
        } else {
            $post_args['ID'] = $id;
            wp_update_post( $post_args );
        }

        if ( isset( $params['acf'] ) && is_array( $params['acf'] ) ) {
            $acf_data = self::sanitize_acf_data( $params['acf'] );

            foreach ( $acf_data as $key => $value ) {
                $field_key = $key_map[$key] ?? self::resolve_field_key($key);
                
                // Handle the Group 'crucial_data'
                if ( $key === 'crucial_data' && is_array( $value ) ) {
                    foreach ( $value as $sub_key => $sub_value ) {
                        $sub_field_key = $key_map[$sub_key] ?? self::resolve_field_key($sub_key);
                        
                        // Handle Repeater 'product_set'
                        if ( $sub_key === 'product_set' && is_array( $sub_value ) ) {
                            $final_rows = [];
                            foreach ( $sub_value as $index => $row ) {
                                $clean_row = [];
                                foreach ( $row as $rk => $rv ) {
                                    $rk_key = self::resolve_field_key($rk);
                                    $r_val = ( $rk === 'product' || $rk === 'quantity' ) ? (int)$rv : $rv;
                                    $clean_row[$rk_key] = $r_val;
                                    update_post_meta( $id, "crucial_data_product_set_{$index}_{$rk}", $r_val );
                                    update_post_meta( $id, "_crucial_data_product_set_{$index}_{$rk}", $rk_key );
                                }
                                $final_rows[] = $clean_row;
                            }
                            $sub_value = $final_rows;
                            update_post_meta( $id, 'crucial_data_product_set', count($sub_value) );
                            update_post_meta( $id, '_crucial_data_product_set', $sub_field_key );
                        }

                        update_field( $sub_field_key, $sub_value, $id );
                        update_post_meta( $id, "crucial_data_{$sub_key}", $sub_value );
                        update_post_meta( $id, "_crucial_data_{$sub_key}", $sub_field_key );
                        
                        if ( $sub_key === 'categories' && is_array( $sub_value ) ) {
                            wp_set_object_terms( $id, array_map('intval', $sub_value), 'product_cat' );
                        }
                    }
                }

                update_field( $field_key, $value, $id );
            }
        }

        return new WP_REST_Response( [ 'status' => 'success', 'id' => $id ], $is_creation ? 201 : 200 );
    }

    private static function dump_fields_recursive($fields, $level = 0) {
        $output = "";
        foreach ($fields as $f) {
            $indent = str_repeat("  ", $level);
            $output .= "\n$indent- " . $f['name'] . " (" . $f['key'] . ") [" . $f['type'] . "]";
            if (isset($f['sub_fields']) && !empty($f['sub_fields'])) {
                $output .= self::dump_fields_recursive($f['sub_fields'], $level + 1);
            }
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
