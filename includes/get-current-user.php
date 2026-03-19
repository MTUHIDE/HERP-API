<?php
/**
 * Return the currently-authenticated user, including roles.
 */

/**
 * Pick a single "primary" role for convenience.
 *
 * @param string[] $roles
 */
function herp_pick_primary_role(array $roles): ?string {
    $roles = array_values(array_unique(array_filter(array_map('strval', $roles))));
    if (empty($roles)) {
        return null;
    }

    // Highest-privilege first.
    $priority = ['administrator', 'researcher', 'reviewer', 'editor', 'author', 'contributor', 'subscriber'];
    foreach ($priority as $p) {
        if (in_array($p, $roles, true)) {
            return $p;
        }
    }

    // Unknown custom role; just return the first.
    return $roles[0] ?? null;
}

function herp_get_current_user(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_Error('not_logged_in', 'You must be logged in', ['status' => 401]);
    }

    $user = get_userdata($user_id);
    if (!$user) {
        return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
    }

    $roles = is_array($user->roles ?? null) ? $user->roles : [];

    return [
        'id' => (int) $user->ID,
        'user_login' => (string) $user->user_login,
        'display_name' => (string) $user->display_name,
        'first_name' => (string) get_user_meta($user_id, 'first_name', true),
        'last_name' => (string) get_user_meta($user_id, 'last_name', true),
        'email' => (string) $user->user_email,
        'roles' => array_values($roles),
        'role' => herp_pick_primary_role($roles),
    ];
}

