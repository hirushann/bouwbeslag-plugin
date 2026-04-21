<?php
define('WP_USE_THEMES', false);
require_once('/Users/hirushanperera/Sites/staging-plugin-test/wp-load.php');

// Ensure the plugin class is loaded
require_once('/Users/hirushanperera/Sites/staging-plugin-test/wp-content/plugins/empire-product-api/includes/class-empire-set-product-cpt.php');

$json_payload = '{
    "name": "TEST SYNC - DIAGNOSTIC",
    "status": "publish",
    "acf": {
        "crucial_data": {
            "supplier_stock": "Repudiandae cupidita",
            "categories": [
                666,
                652,
                660
            ],
            "delivery_if_stock": "Molestiae repudianda",
            "delivery_if_no_stock": "Rerum sint id nemo",
            "delivery_if_low_but_1_stock": "Deleniti qui deserun",
            "product_set": [
                {
                    "product": 36897,
                    "quantity": "5"
                },
                {
                    "product": 36900,
                    "quantity": "8"
                },
                {
                    "product": 36901,
                    "quantity": "7"
                }
            ]
        }
    }
}';

echo "--- STARTING DIAGNOSTIC SYNC ---\n";

// Mock REST Request
$request = new WP_REST_Request('POST', '/custom/v1/set-products/diagnostic-test-slug');
$request->set_param('slug', 'diagnostic-test-slug');
$request->set_body($json_payload);
$request->set_header('Content-Type', 'application/json');

// Run the webhook logic
$response = Empire_Set_Product_CPT::update_set_product_webhook($request);

if (is_wp_error($response)) {
    echo "ERROR: " . $response->get_error_message() . "\n";
} else {
    $data = $response->get_data();
    $post_id = $data['id'];
    echo "SUCCESS! Created/Updated Post ID: $post_id\n";
    echo "Updated Fields: " . implode(', ', $data['updated_fields']) . "\n";

    echo "\n--- VERIFYING DATA IN DATABASE ---\n";
    
    // Check Post Meta
    $all_meta = get_post_meta($post_id);
    echo "Slug Meta: " . get_post_meta($post_id, 'slug', true) . "\n";
    echo "Product Set Meta (Count): " . get_post_meta($post_id, 'product_set', true) . "\n";
    
    // Check ACF Fields
    if (function_exists('get_fields')) {
        $fields = get_fields($post_id);
        echo "ACF Field Result:\n";
        print_r($fields);
    } else {
        echo "ACF get_fields function not found!\n";
    }

    // Check Categories
    $terms = wp_get_post_terms($post_id, 'product_cat');
    echo "Assigned Categories IDs: ";
    foreach($terms as $term) echo $term->term_id . ", ";
    echo "\n";
}

echo "\n--- DIAGNOSTIC COMPLETE ---\n";
