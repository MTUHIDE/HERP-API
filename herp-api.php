<?php
/**
 * Plugin Name: HERP API
 * Description: Custom REST API endpoints for your app.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/get-user-records.php';

add_action('rest_api_init', function () {
    register_rest_route('herp/v1', '/records/(?P<user_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_user_records',
        'permission_callback' => '__return_true', // for now, open — secure later!
    ]);
});

function get_user_records($data) {
    global $wpdb;
    $user_id = intval($data['user_id']);

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM wp_records WHERE user_id = %d", $user_id)
    );

    return $results;
}