<?php
add_action('rest_api_init', function () {
    register_rest_route('herp/v1', '/records', [
        'methods'  => 'GET',
        'callback' => 'herp_get_user_records',
        'permission_callback' => '__return_true', // Allow public access (you can tighten this later)
    ]);
});

function herp_get_user_records(WP_REST_Request $request) {
    global $wpdb;

    $user_id = intval($request->get_param('user_id'));
    if (!$user_id) {
        return new WP_Error('missing_user_id', 'Missing user_id parameter', ['status' => 400]);
    }

    // 1️⃣ Get all posts authored by user where post_name starts with "record-"
    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_name FROM wp_posts WHERE post_author = %d",
            $user_id
        )
    );

    if (empty($posts)) {
        return [
        ];
    }

    // 2️⃣ Extract record IDs from post_name like "record-36471"
    $record_ids = [];
    foreach ($posts as $post) {
        if (preg_match('/record-(\d+)/', $post->post_name, $matches)) {
            $record_ids[] = intval($matches[1]);
        }
    }

    if (empty($record_ids)) {
        return [];
    }

    // 3️⃣ Query the record table for those IDs
    $record_ids = array_map('intval', $record_ids); // ensure all integers
    $ids_string = implode(',', $record_ids);

    $records = $wpdb->get_results("
       SELECT 
        r.*,
        c.c_name AS county_title,
        g.t_name AS group_title
       FROM record r
       LEFT JOIN county c ON r.r_county = c.c_id
       LEFT JOIN `taxon` g on r.r_taxon = g.t_id
       WHERE r.r_id IN ($ids_string) ");


    return $records;
}
