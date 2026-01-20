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
    // GET /wp-json/herp/v1/records?user_id=123
    register_rest_route('herp/v1', '/records', [
        'methods'             => 'GET',
        'callback'            => 'herp_get_user_records',
        'permission_callback' => '__return_true', // for now, open — secure later!
        'args'                => [
            'user_id' => [
                'required'          => true,
                'validate_callback' => static function ($param) {
                    return is_numeric($param) && intval($param) > 0;
                },
            ],
        ],
    ]);

    // Back-compat: GET /wp-json/herp/v1/records/123
    register_rest_route('herp/v1', '/records/(?P<user_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'herp_get_user_records',
        'permission_callback' => '__return_true', // for now, open — secure later!
    ]);
});