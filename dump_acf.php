<?php
define('WP_USE_THEMES', false);
require_once('/Users/hirushanperera/Sites/staging-plugin-test/wp-load.php');

if (!function_exists('acf_get_field_groups')) {
    echo "ACF not active!\n";
    exit;
}

echo "--- ACF FIELD STRUCTURE FOR SET PRODUCT ---\n";

$groups = acf_get_field_groups(['post_type' => 'set_product']);

if (empty($groups)) {
    // Try getting all groups if none are explicitly assigned yet
    $groups = acf_get_field_groups();
}

foreach ($groups as $group) {
    echo "\nGROUP: " . $group['title'] . " (" . $group['key'] . ")\n";
    $fields = acf_get_fields($group['key']);
    if ($fields) {
        display_fields_recursive($fields);
    }
}

function display_fields_recursive($fields, $indent = 2) {
    foreach ($fields as $field) {
        echo str_repeat(" ", $indent) . "FIELD: " . $field['label'] . " [Name: " . $field['name'] . "] [Key: " . $field['key'] . "] [Type: " . $field['type'] . "]\n";
        if (isset($field['sub_fields']) && !empty($field['sub_fields'])) {
            display_fields_recursive($field['sub_fields'], $indent + 4);
        }
    }
}

echo "\n--- TAXONOMY CHECK ---\n";
$taxonomies = get_object_taxonomies('set_product');
print_r($taxonomies);

echo "\n--- END ---\n";
