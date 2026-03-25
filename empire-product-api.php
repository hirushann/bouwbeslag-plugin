<?php
/**
 * Plugin Name: Sync Products from Empire API
 * Description: Retrieve products from an external API and sync with WooCommerce.
 * Version: 1.0.0
 * Author: Hirushan
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-empire-product-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-empire-category-api.php';

add_action( 'plugins_loaded', [ 'Empire_Product_API', 'init' ] );
add_action( 'plugins_loaded', [ 'Empire_Category_API', 'init' ] );

add_filter('wp_headers', function($headers) {
    // Whitelist your Next.js domain for CSP frame-ancestors
    $headers['Content-Security-Policy'] = "frame-ancestors 'self' https://bouwbeslag.nl;";
    
    // Modern browsers use CSP, but older ones may need X-Frame-Options set to the specific URL
    // Note: 'ALLOW-FROM' is deprecated but sometimes needed for legacy support
    unset($headers['X-Frame-Options']); 
    
    return $headers;
}, 99);

add_action('send_headers', function () {
    header_remove('Content-Security-Policy');

    header(
        "Content-Security-Policy: frame-ancestors 'self' https://bouwbeslag.nl"
    );
});

// Remove X-Frame-Options header added by WordPress Core
remove_action('admin_init', 'send_frame_options_header');
remove_action('login_init', 'send_frame_options_header');
// Also check the 'init' hook just in case a plugin added it there
remove_action('init', 'send_frame_options_header');

/**
 * Redirect WooCommerce "Thank You" (Order Received) to Headless Frontend
 * Add this to your theme's functions.php or a custom plugin.
 */
add_action( 'template_redirect', 'headless_redirect_thankyou' );
function headless_redirect_thankyou() {
    if ( ! is_wc_endpoint_url( 'order-received' ) || empty( $_GET['key'] ) ) {
        return;
    }
    $order_id = absint( get_query_var( 'order-received' ) );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $headless_url = 'https://bouwbeslag.nl';
    $redirect_url = add_query_arg(
        array(
            'orderId' => $order_id,
            'key'     => $order->get_order_key(),
        ),
        $headless_url . '/checkout/success' 
    );
    wp_redirect( $redirect_url );
    exit;
}


/**
 * B2B Customer Role & Approval Workflow (v4 - Debug & Pre-Insert Fix)
 * Add this to your theme's functions.php or a custom plugin.
 */
// 1. Add B2B Role
function add_b2b_customer_role_v4() {
    if ( get_role( 'b2b_customer' ) ) {
        return; 
    }
    $customer_role = get_role( 'customer' );
    $capabilities  = $customer_role ? $customer_role->capabilities : array( 'read' => true );
    add_role( 'b2b_customer', 'B2B Customer', $capabilities );
}
add_action( 'init', 'add_b2b_customer_role_v4' );


add_filter( 'woocommerce_rest_insert_customer', 'force_b2b_role_pre_insert', 10, 2 );
function force_b2b_role_pre_insert( $data, $request ) { 
    if ( isset( $request->get_params()['role'] ) && 'b2b_customer' === $request->get_params()['role'] ) {
        
        $data->set_role('b2b_customer');
        
    }
    return $data;
}


// 3. Fallback: Force on Creation (Double Security)
add_action( 'woocommerce_created_customer', 'force_b2b_role_on_registration_v4', 10, 3 );
function force_b2b_role_on_registration_v4( $customer_id, $new_customer_data, $password_generated ) {
    $is_b2b = get_user_meta( $customer_id, 'is_b2b_registration', true );
    if ( 'yes' === $is_b2b ) {
        $user = new WP_User( $customer_id );
        if ( ! in_array( 'b2b_customer', (array) $user->roles ) ) {
             error_log( 'B2B: Role fallback triggered for User ' . $customer_id );
             $user->set_role( 'b2b_customer' );
             update_user_meta( $customer_id, 'b2b_status', 'pending' );
        }
    }
}

// 4. Block Login
add_filter( 'authenticate', 'check_b2b_status_login_v4', 30, 3 );
function check_b2b_status_login_v4( $user, $username, $password ) {
    if ( is_a( $user, 'WP_User' ) ) {
        $status = get_user_meta( $user->ID, 'b2b_status', true );
        
        // If B2B Role, but no status, treat as Pending (Strict Mode)
        if ( in_array( 'b2b_customer', (array) $user->roles ) ) {
            if ( ! $status || $status === 'pending' ) {
                return new WP_Error( 'b2b_pending', __( '<strong>Nog even geduld</strong>: Uw zakelijke account is in behandeling.' ) );
            }
            if ( $status === 'rejected' ) {
                return new WP_Error( 'b2b_rejected', __( '<strong>Helaas</strong>: Uw zakelijke accountaanvraag is afgewezen.' ) );
            }
        }
    }
    return $user;
}

// 5. Admin UI
add_action( 'show_user_profile', 'show_b2b_status_field_v4' );
add_action( 'edit_user_profile', 'show_b2b_status_field_v4' );
function show_b2b_status_field_v4( $user ) {
    if ( ! in_array( 'b2b_customer', (array) $user->roles ) && ! in_array( 'customer', (array) $user->roles ) ) {
        return;
    }
    $status = get_user_meta( $user->ID, 'b2b_status', true );
    if ( ! $status ) $status = 'none'; 
    ?>
    <h3>B2B Management</h3>
    <table class="form-table">
        <tr>
             <th><label>Registration Type</label></th>
             <td>
                <?php echo in_array('b2b_customer', (array)$user->roles) ? '<strong style="color:blue">B2B Customer</strong>' : 'Regular Customer'; ?>
             </td>
        </tr>
        <tr>
            <th><label for="b2b_status">Application Status</label></th>
            <td>
                <select name="b2b_status" id="b2b_status">
                    <option value="none" <?php selected( $status, 'none' ); ?>>- Geen -</option>
                    <option value="pending" <?php selected( $status, 'pending' ); ?>>In Behandeling (Pending)</option>
                    <option value="approved" <?php selected( $status, 'approved' ); ?>>Goedgekeurd (Approved)</option>
                    <option value="rejected" <?php selected( $status, 'rejected' ); ?>>Afgewezen (Rejected)</option>
                </select>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'personal_options_update', 'save_b2b_status_field_v4' );
add_action( 'edit_user_profile_update', 'save_b2b_status_field_v4' );
function save_b2b_status_field_v4( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) { return; }
    if ( isset( $_POST['b2b_status'] ) ) {
        $new_status = sanitize_text_field( $_POST['b2b_status'] );
        update_user_meta( $user_id, 'b2b_status', $new_status );
        if ( $new_status === 'approved' ) {
            $u = new WP_User( $user_id );
            if ( in_array('customer', (array)$u->roles) ) { $u->set_role('b2b_customer'); }
        }
    }
}

add_action( 'send_headers', 'allow_headless_iframe' );
function allow_headless_iframe() {
    header_remove('X-Frame-Options');
    header( "Content-Security-Policy: frame-ancestors https://app.bouwbeslag.nl https://bouwbeslag.nl" );
}

function add_csp_header() {
    $csp = "frame-ancestors 'self' https://app.bouwbeslag.nl https://bouwbeslag.nl;"; 
    header("Content-Security-Policy: $csp");
    header_remove('X-Frame-Options'); 
}
add_action('send_headers', 'add_csp_header');


add_filter( 'woocommerce_product_get_backorders', 'enable_backorders_globally', 10, 2 );
add_filter( 'woocommerce_product_variation_get_backorders', 'enable_backorders_globally', 10, 2 );

function enable_backorders_globally( $backorder_status, $product ) {
    return 'yes';
}

add_action('template_redirect', function() {
    // 1. Allow REST API requests
    // (CRITICAL: Your headless app needs this to fetch products/data)
    if ( defined('REST_REQUEST') && REST_REQUEST ) {
        return;
    }

    // 2. Allow Checkout page and "Order Received" (Success) page
    if ( function_exists('is_checkout') && ( is_checkout() || is_wc_endpoint_url('order-received') ) ) {
        return;
    }

    // 3. Allow standard WordPress Login and Admin Dashboard
    if ( is_admin() || in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php']) ) {
        return;
    }

    // 4. (Optional) Allow Administrators to view the site normally
    if ( current_user_can('manage_options') ) {
        return;
    }

    // 5. Redirect everything else to the Checkout page
    wp_safe_redirect( wc_get_checkout_url() );
    exit;
});

/**
 * Update ACF fields when a product category is created or updated via WooCommerce REST API
 */
add_action( 'woocommerce_rest_insert_product_cat', 'update_acf_on_woo_category_rest', 10, 3 );

function update_acf_on_woo_category_rest( $term, $request, $creating ) {
    // Check if the 'acf' key exists in the request body
    $acf_data = $request->get_param( 'acf' );

    if ( is_array( $acf_data ) ) {
        foreach ( $acf_data as $key => $value ) {
            // ACF term fields are stored as "product_cat_{ID}"
            update_field( $key, $value, 'product_cat_' . $term->term_id );
        }
    }
}

function prepare_category_fields( $response, $item, $request ) {
    if ( empty( $response->data ) ) {
        return $response;
    }
    // 'product_cat_' is the required prefix for WooCommerce product category terms
    if ( function_exists( 'get_fields' ) ) {
        $response->data['acf'] = get_fields( $item->taxonomy . '_' . $item->term_id );
    } else {
        $response->data['acf'] = null;
    }
    return $response;
}
add_filter( 'woocommerce_rest_prepare_product_cat', 'prepare_category_fields', 10, 3 );

/**
 * Filter to allow setting WooCommerce Product Categories by 'name' via REST API.
 * Add this code to your theme's functions.php file.
 */
add_filter( 'woocommerce_rest_pre_insert_product_object', 'wc_rest_lookup_category_by_name', 10, 2 );

function wc_rest_lookup_category_by_name( $product, $request ) {
    // Retrieve the categories sent in the API request payload
    $categories = $request->get_param( 'categories' );

    // Only proceed if categories are present in the payload
    if ( ! empty( $categories ) && is_array( $categories ) ) {
        $new_category_ids = array();

        foreach ( $categories as $cat_data ) {
            
            // 1. If an ID is explicitly provided, trust it and use it.
            if ( ! empty( $cat_data['id'] ) ) {
                $new_category_ids[] = absint( $cat_data['id'] );
                continue;
            }

            // 2. If no ID, look it up by Name
            if ( ! empty( $cat_data['name'] ) ) {
                $term_name = $cat_data['name'];
                
                // Try to find the category (term) by name in 'product_cat' taxonomy
                $term = get_term_by( 'name', $term_name, 'product_cat' );

                if ( $term ) {
                    // Found it! Add the ID to our list
                    $new_category_ids[] = $term->term_id;
                } else {
                    // 3. (Optional) Create the category if it doesn't exist
                    // If you want to automatically create missing categories, uncomment the lines below:
                    /*
                    $new_term = wp_insert_term( $term_name, 'product_cat' );
                    if ( ! is_wp_error( $new_term ) ) {
                        $new_category_ids[] = $new_term['term_id'];
                    }
                    */
                }
            }
        }

        // If we successfully resolved any IDs, assign them to the product
        if ( ! empty( $new_category_ids ) ) {
            // array_unique prevents duplicate IDs
            $product->set_category_ids( array_unique( $new_category_ids ) );
        }
    }

    return $product;
}

/**
 * Prevent duplicate images when products are created/updated via WooCommerce REST API.
 */
add_filter( 'woocommerce_rest_pre_insert_product_object', 'wc_rest_prevent_duplicate_images', 10, 2 );

function wc_rest_prevent_duplicate_images( $product, $request ) {
    $images = $request->get_param( 'images' );
    
    // If 'images' exists in the REST payload, check each one to see if we already have it
    if ( ! empty( $images ) && is_array( $images ) ) {
        $new_images = array();
        foreach ( $images as $image ) {
            // Already includes an ID? Skip checking
            if ( ! empty( $image['id'] ) ) {
                $new_images[] = $image;
                continue;
            }
            
            // Source URL is present? Try to find existing attachment to avoid duplicates
            if ( ! empty( $image['src'] ) ) {
                $existing_id = Empire_Product_API::upload_from_ftp_path( $image['src'], 'image' );
                if ( $existing_id ) {
                    $image['id'] = $existing_id;
                }
                $new_images[] = $image;
            } else {
                $new_images[] = $image;
            }
        }
        // Update the request parameters so WooCommerce uses our existing or newly sideloaded (but deduplicated) IDs
        $request->set_param( 'images', $new_images );
    }
    
    return $product;
}

/**
 * Handle custom fields (like Brand and ACF) when a product is created or updated via WooCommerce REST API.
 */
add_action( 'woocommerce_rest_insert_product', 'update_custom_product_data_on_rest', 10, 3 );

function update_custom_product_data_on_rest( $product, $request, $creating ) {
    // In woocommerce_rest_insert_product, $product is the WC_Product object.
    $product_id = $product->get_id();
    
    // 1. Handle Brand Data
    $brand_data = $request->get_param( 'brand' );
    
    // Also check if brand is inside ACF payload (checking both 'brand' and 'crucial_data_brand')
    if ( empty( $brand_data ) ) {
        $acf_data = $request->get_param( 'acf' );
        if ( isset( $acf_data['brand'] ) ) {
            $brand_data = $acf_data['brand'];
        } elseif ( isset( $acf_data['crucial_data_brand'] ) ) {
            $brand_data = $acf_data['crucial_data_brand'];
        }
    }

    if ( ! empty( $brand_data ) ) {
        $brand_name = '';
        $image_url  = '';

        if ( is_array( $brand_data ) ) {
            $brand_name = isset( $brand_data['name'] ) ? trim( (string) $brand_data['name'] ) : '';
            $image_url  = isset( $brand_data['image_url'] ) ? esc_url_raw( $brand_data['image_url'] ) : '';
        } else {
            $brand_name = trim( (string) $brand_data );
        }

        if ( $brand_name !== '' ) {
            // Detect the correct brand taxonomy
            $taxonomy_to_use = 'product_brand';
            $taxonomies_to_check = [ 'product_brand', 'brand', 'pwb-brand', 'pa_brand' ];
            
            foreach ( $taxonomies_to_check as $tax ) {
                if ( taxonomy_exists( $tax ) ) {
                    $taxonomy_to_use = $tax;
                    break;
                }
            }

            $term = term_exists( $brand_name, $taxonomy_to_use );

            if ( ! $term ) {
                $term = wp_insert_term( $brand_name, $taxonomy_to_use );
            }

            if ( ! is_wp_error( $term ) ) {
                $term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

                // Assign brand to product
                $res = wp_set_object_terms( $product_id, [ $term_id ], $taxonomy_to_use, false );

                if ( ! is_wp_error( $res ) ) {
                    // Success - clear product transients to ensure UI updates
                    if ( function_exists( 'wc_delete_product_transients' ) ) {
                        wc_delete_product_transients( $product_id );
                        wc_update_product_lookup_tables( $product_id );
                    }
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

                // Handle brand image if provided
                if ( $image_url ) {
                    $existing_thumb_id = get_term_meta( $term_id, 'thumbnail_id', true );
                    if ( ! $existing_thumb_id || ! get_post( $existing_thumb_id ) ) {
                        $attachment_id = Empire_Product_API::upload_from_ftp_path( $image_url, 'image' );
                        if ( $attachment_id ) {
                            update_term_meta( $term_id, 'thumbnail_id', $attachment_id );
                        }
                    }
                }
            }
        }
    }

    // 2. Handle generic ACF field updates
    $acf_payload = $request->get_param( 'acf' );
    if ( is_array( $acf_payload ) ) {
        foreach ( $acf_payload as $key => $value ) {
            // We already handled 'brand' above, but updating again doesn't hurt or we can skip it
            update_field( $key, $value, $product_id );
        }
    }
}


/**
 * Custom REST API Endpoint: Update 'dayz-holidays' Option
 * * URL:    /wp-json/custom/v1/holidays
 * Method: POST
 * Body:   { "dates": ["2024-01-01", "2024-12-25"] }
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'custom/v1', '/holidays', array(
        'methods'             => 'POST',
        'callback'            => 'handle_update_dayz_holidays',
        'permission_callback' => function () {
            // Only allow administrators to update this option
            return current_user_can( 'manage_options' );
        }
    ) );
} );

function handle_update_dayz_holidays( $request ) {
    // Retrieve the 'dates' array from the request body
    $dates = $request->get_param( 'dates' );

    // Basic validation
    if ( ! is_array( $dates ) ) {
        return new WP_Error( 'invalid_param', 'Payload must contain a "dates" array.', array( 'status' => 400 ) );
    }

    // Sanitize the dates (optional but recommended)
    $sanitized_dates = array_map( 'sanitize_text_field', $dates );

    // Update the WordPress option 'dayz-holidays'
    update_option( 'dayz-holidays', $sanitized_dates );

    // Return success response
    return new WP_REST_Response( array(
        'success' => true,
        'message' => 'Holidays updated successfully.',
        'data'    => $sanitized_dates
    ), 200 );
}

/**
 * Schedule Daily Sync with Empire Product API
 */
add_action( 'empire_cron_sync_with_api', [ 'Empire_Product_API', 'handle_sync' ] );

function empire_schedule_daily_sync() {
    if ( ! wp_next_scheduled( 'empire_cron_sync_with_api' ) ) {
        wp_schedule_event(
            time(),   
            'daily',        
            'empire_cron_sync_with_api'
        );
    }
}
add_action( 'init', 'empire_schedule_daily_sync' );

define('BOUWBESLAG_B2B_PRICE_KEY', 'crucial_data_b2b_and_b2c_sales_price_b2b');
function bouwbeslag_b2b_price_override($price, $product) {
    // 1. Check if user is logged in
    if (!is_user_logged_in()) {
        return $price;
    }

    // 2. Check if user has B2B or Admin role
    $user = wp_get_current_user();
    $allowed_roles = array('b2b_customer', 'administrator');
    $is_b2b = array_intersect($allowed_roles, $user->roles);

    if (empty($is_b2b)) {
        return $price;
    }

    // 3. Get the ACF B2B Price
    $product_id = $product->get_id();
    $b2b_price = get_post_meta($product_id, BOUWBESLAG_B2B_PRICE_KEY, true);

    // 4. Return B2B price if valid
    if (!empty($b2b_price) && is_numeric($b2b_price)) {
        return (float) $b2b_price;
    }

    return $price;
}

// Hook into price filters
add_filter('woocommerce_product_get_price', 'bouwbeslag_b2b_price_override', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'bouwbeslag_b2b_price_override', 10, 2);
add_filter('woocommerce_product_variation_get_price', 'bouwbeslag_b2b_price_override', 10, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'bouwbeslag_b2b_price_override', 10, 2);

/**
 * Sync Featured Image with ACF image field (brand_image)
 */
function sync_brand_thumbnail_to_acf( $post_id, $post, $update ) {

    // Check if this is a revision or not the correct post type
    if (wp_is_post_revision($post_id) || $post->post_type != 'brand') {
        return;
    }

    // Get the new featured image ID
    $thumbnail_id = get_post_thumbnail_id($post_id);

    // Update your specific ACF field with the new image ID
    if ($thumbnail_id) {
        update_field('brand_logo', $thumbnail_id, $post_id);
    }
}

add_action( 'save_post', 'sync_brand_thumbnail_to_acf', 10, 3 );

function extend_product_search_join( $join ) {
    global $pagenow, $wpdb;
    
    // Only run in admin, on edit.php, for 'product' post type, with a search query
    if ( ! is_admin() || 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'product' !== $_GET['post_type'] || ! isset( $_GET['s'] ) ) {
        return $join;
    }

    // Use an alias 'pm_ean' to avoid collisions with other usage of postmeta
    $join .= " LEFT JOIN $wpdb->postmeta AS pm_ean ON $wpdb->posts.ID = pm_ean.post_id ";
    return $join;
}
add_filter( 'posts_join', 'extend_product_search_join' );

function extend_product_search_where( $where ) {
    global $pagenow, $wpdb;
    
    // Same checks as above
    if ( ! is_admin() || 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'product' !== $_GET['post_type'] || ! isset( $_GET['s'] ) ) {
        return $where;
    }

    // Replace the default title search to include the EAN meta search
    // We target the standard (wp_posts.post_title LIKE '...') structure
    $where = preg_replace(
        "/\(\s*{$wpdb->posts}.post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
        "({$wpdb->posts}.post_title LIKE $1 OR (pm_ean.meta_key = 'crucial_data_product_ean_code' AND pm_ean.meta_value LIKE $1))",
        $where
    );
    return $where;
}
add_filter( 'posts_where', 'extend_product_search_where' );

// Add custom EAN field to search
function add_ean_to_search( $search, $wp_query ) {
    global $wpdb;
    if ( empty( $search ) || ! $wp_query->is_search || is_admin() ) return $search;

    $search = $wpdb->prepare( "
        OR EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm
            WHERE pm.post_id = {$wpdb->posts}.ID
            AND pm.meta_key = '_global_unique_id'
            AND pm.meta_value LIKE %s
        )
    ", '%' . $wpdb->esc_like( get_query_var( 's' ) ) . '%' );

    return $search;
}
add_filter( 'posts_search', 'add_ean_to_search', 10, 2 );

add_filter( 'dgwt/wcas/variation_support_modes', function() {
    return ['search_in_sku', 'search_in_global_unique_id', 'exact_match'];
} );


function woocommerce_basic_permissions(WP_REST_Request $request)
  {
    $headers = $request->get_headers();

    if (!isset($headers['authorization'][0])) {
        return false;
    }

    $auth_header = $headers['authorization'][0];
    if (strpos($auth_header, 'Basic ') !== 0) {
        return false;
    }

    $auth_value = base64_decode(substr($auth_header, 6));
    list($consumer_key, $consumer_secret) = explode(':', $auth_value);

    global $wpdb;

    $consumer_key = wc_api_hash( sanitize_text_field( $consumer_key ) );

    $user         = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT key_id, user_id, permissions, consumer_key, consumer_secret, nonces FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
            $consumer_key
        )
    );

    if (empty($user) || !hash_equals( $user->consumer_secret, $consumer_secret)) { 
        return false;
    }

    return true;
  }


add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/update-brand/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'update_brand_acf_fields',
        'permission_callback' => 'woocommerce_basic_permissions'
    ));
});

function update_brand_acf_fields($data) {
    $term_id = $data['id'];
    $params = $data->get_json_params();
    $updated_keys = [];

    // Handle 'brand_image' if passed at the root payload instead of inside 'acf'
    if (isset($params['brand_image']) && is_string($params['brand_image'])) {
        $attachment_id = Empire_Product_API::upload_from_ftp_path($params['brand_image'], 'image');
        if ($attachment_id) {
            update_field('brand_image', $attachment_id, 'product_brand_' . $term_id);
            $updated_keys[] = 'brand_image';
        }
    }

    if (isset($params['acf'])) {
        foreach ($params['acf'] as $key => $value) {
            // If the field is an image and a URL is provided, sideload it into the media library
            if ($key === 'brand_image' && is_string($value)) {
                $attachment_id = Empire_Product_API::upload_from_ftp_path($value, 'image');
                if ($attachment_id) {
                    $value = $attachment_id;
                }
            }

            // ACF term format is "taxonomy_termid"
            update_field($key, $value, 'product_brand_' . $term_id);
            if (!in_array($key, $updated_keys)) {
                $updated_keys[] = $key;
            }
        }
    }

    if (!empty($updated_keys)) {
        return new WP_REST_Response(['status' => 'success', 'updated' => $updated_keys], 200);
    }

    return new WP_Error('no_acf', 'No ACF data provided', array('status' => 400));
}

foreach ( array( 'pre_term_description' ) as $filter ) { 
    remove_filter( $filter, 'wp_filter_kses' ); 
} 
foreach ( array( 'term_description' ) as $filter ) { 
    remove_filter( $filter, 'wp_kses_data' ); 
}

// Function to delete all media files programmatically

// function delete_all_media_programmatically() {
//     // Query all attachments
//     $attachments = get_posts( array(
//         'post_type'      => 'attachment',
//         'posts_per_page' => -1, // Get all attachments
//         'post_status'    => 'any',
//         'fields'         => 'ids', // Only fetch IDs for efficiency
//     ) );

//     if ( $attachments ) {
//         foreach ( $attachments as $attachment_id ) {
//             if ( false === wp_delete_attachment( $attachment_id, true ) ) {
//                 error_log( "Failed to delete attachment ID: $attachment_id" );
//             }
//         }
//         echo "All media files have been deleted.";
//     } else {
//         echo "No media files found.";
//     }
// }
// add_action( 'init', 'delete_all_media_programmatically' );
