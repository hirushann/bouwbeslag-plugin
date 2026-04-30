<?php
/**
 * This test simulates EXACTLY what happens during the sync:
 * An attribute exists in WC DB (from a prior sync) but WP doesn't have
 * it registered yet in this request (fresh PHP boot).
 */
require_once '/Users/hirushanperera/Sites/staging-plugin-test/wp-load.php';

// Pick a real product
$products = wc_get_products(['limit' => 1, 'return' => 'ids']);
if ( empty($products) ) { die("No products found\n"); }
$product_id = $products[0];
echo "Using product ID: {$product_id}\n\n";

// Use a real attribute from your WC install that has existing terms
// but let's test with a value that doesn't exist as a term yet
$key       = 'color'; // should be a real pa_color
$new_value = 'SYNCTEST_' . rand(1000, 9999);
$attr_slug = sanitize_title( $key );
$taxonomy  = 'pa_' . $attr_slug;

echo "Taxonomy: {$taxonomy}\n";
echo "taxonomy_exists(): " . ( taxonomy_exists($taxonomy) ? 'YES' : 'NO' ) . "\n";
echo "wc_attribute_taxonomy_id: " . wc_attribute_taxonomy_id_by_name($taxonomy) . "\n\n";

// ---- Now simulate the exact code path in the sync function ----
$existing_meta = get_post_meta( $product_id, '_product_attributes', true );
if ( ! is_array( $existing_meta ) ) $existing_meta = [];

// This is the check in the sync code:
if ( ! taxonomy_exists( $taxonomy ) ) {
    echo "[BRANCH] taxonomy does NOT exist — calling wc_create_attribute\n";
    $attr_id = wc_create_attribute([
        'name'         => ucwords( str_replace( '_', ' ', $key ) ),
        'slug'         => $attr_slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ]);

    echo "wc_create_attribute result: ";
    if ( is_wp_error( $attr_id ) ) {
        echo "WP_Error: " . $attr_id->get_error_code() . " - " . $attr_id->get_error_message() . "\n";
        echo ">>> THIS IS THE SILENT SKIP BUG - attribute exists in DB but not registered!\n";
        echo ">>> Now registering taxonomy manually anyway...\n";
    } else {
        echo "Created attr ID: {$attr_id}\n";
    }

    delete_transient( 'wc_attribute_taxonomies' );
    WC_Cache_Helper::invalidate_cache_group( 'woocommerce' );

    register_taxonomy(
        $taxonomy,
        [ 'product' ],
        ['hierarchical' => false, 'show_ui' => true, 'query_var' => true]
    );
    echo "register_taxonomy() called. taxonomy_exists() now: " . ( taxonomy_exists($taxonomy) ? 'YES' : 'NO' ) . "\n";
} else {
    echo "[BRANCH] taxonomy already exists — skipping wc_create_attribute\n";
}

echo "\n--- term_exists('$new_value', '$taxonomy') ---\n";
$term = term_exists( $new_value, $taxonomy );
var_dump($term);

if ( ! $term ) {
    echo "\n--- wp_insert_term ---\n";
    $term = wp_insert_term( $new_value, $taxonomy );
    var_dump($term);
    if ( is_wp_error($term) ) {
        echo "FAILED: " . $term->get_error_code() . " - " . $term->get_error_message() . "\n";
        die();
    }
}

$term_id  = (int) ( is_array($term) ? $term['term_id'] : $term );
$term_ids = [ $term_id ];

echo "\n--- wp_set_object_terms ---\n";
$result = wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
var_dump($result);
if ( is_wp_error($result) ) {
    echo "FAILED: " . $result->get_error_message() . "\n";
    die();
}

$existing_meta[ $taxonomy ] = [
    'name' => $taxonomy, 'value' => '', 'position' => 0,
    'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1,
];
update_post_meta( $product_id, '_product_attributes', $existing_meta );
clean_object_term_cache( $product_id, 'product' );
wc_delete_product_transients( $product_id );

echo "\n=== Final verification: get_the_terms ===\n";
$terms = get_the_terms( $product_id, $taxonomy );
if ( $terms && ! is_wp_error($terms) ) {
    foreach ( $terms as $t ) {
        echo "  Term [{$t->term_id}]: {$t->name}\n";
    }
} else {
    echo "  No terms found / error\n";
}

echo "\n=== Final _product_attributes ===\n";
$meta = get_post_meta( $product_id, '_product_attributes', true );
if ( isset($meta[$taxonomy]) ) {
    echo "  is_taxonomy=" . $meta[$taxonomy]['is_taxonomy'] . "\n";
    echo "  name=" . $meta[$taxonomy]['name'] . "\n";
    echo "SUCCESS: Term '{$new_value}' created and assigned!\n";
} else {
    echo "  NOT FOUND in meta\n";
}
