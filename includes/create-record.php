<?php
/**
 * Create record(s) the same way the website does (see theme `ajax_add_record()`):
 * - Insert into custom `record` table (one row per animal).
 * - Create WP post (post_type `record`) and then set `record.r_post_id`.
 *
 * This endpoint accepts NAMES (not IDs) and resolves them:
 * - `county` => WP term in taxonomy `county` by name/slug
 * - `group` => WP post (post_type `group`) by post_title
 * - `species` (+ optional `parent_species`) => WP post (post_type `species`) by post_title (+ parent)
 */

/**
 * Permission callback for POST /records.
 * Require an authenticated WP user (cookie auth or Application Password).
 */
function herp_can_create_record() {
    return is_user_logged_in();
}

/**
 * @return int|WP_Error
 */
function herp_find_group_id_by_name(string $group_name) {
    global $wpdb;

    $group_name = trim($group_name);
    if ($group_name === '') {
        return new WP_Error('missing_group', 'Missing group name', ['status' => 400]);
    }

    $posts_table = $wpdb->posts;
    $group_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$posts_table}
             WHERE post_type = 'group' AND post_title = %s AND post_status <> 'trash'
             ORDER BY ID DESC
             LIMIT 1",
            $group_name
        )
    );

    if (!$group_id) {
        return new WP_Error('unknown_group', "Unknown group: {$group_name}", ['status' => 400]);
    }

    return (int) $group_id;
}

/**
 * @return int|WP_Error
 */
function herp_find_county_id_by_name(string $county_name) {
    $county_name = trim($county_name);
    if ($county_name === '') {
        return new WP_Error('missing_county', 'Missing county name', ['status' => 400]);
    }

    $term = get_term_by('name', $county_name, 'county');
    if (!$term) {
        $term = get_term_by('slug', sanitize_title($county_name), 'county');
    }
    // Common app input: "Washtenaw" instead of "Washtenaw County".
    if ((!$term || is_wp_error($term)) && !preg_match('/\bcounty\b/i', $county_name)) {
        $with_suffix = $county_name . ' County';
        $term = get_term_by('name', $with_suffix, 'county');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($with_suffix), 'county');
        }
    }

    if (!$term || is_wp_error($term)) {
        return new WP_Error('unknown_county', "Unknown county: {$county_name}", ['status' => 400]);
    }

    return (int) $term->term_id;
}

/**
 * Find a species/subspecies post ID by title.
 *
 * If `$parent_species_name` is provided, we will only match a child post whose parent title matches.
 * Otherwise we prefer a top-level species (post_parent IN (0,NULL)).
 *
 * @return int|WP_Error
 */
function herp_find_species_id_by_name(string $species_name, ?string $parent_species_name = null) {
    global $wpdb;

    $species_name = trim($species_name);
    if ($species_name === '') {
        return new WP_Error('missing_species', 'Missing species name', ['status' => 400]);
    }

    $posts_table = $wpdb->posts;

    $parent_id = null;
    if ($parent_species_name !== null && trim($parent_species_name) !== '') {
        $parent_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$posts_table}
                 WHERE post_type = 'species' AND post_title = %s AND post_status <> 'trash'
                 ORDER BY ID DESC
                 LIMIT 1",
                trim($parent_species_name)
            )
        );
        if (!$parent_id) {
            return new WP_Error('unknown_parent_species', "Unknown parent species: {$parent_species_name}", ['status' => 400]);
        }
        $parent_id = (int) $parent_id;
    }

    if ($parent_id !== null) {
        $species_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$posts_table}
                 WHERE post_type = 'species' AND post_title = %s AND post_parent = %d AND post_status <> 'trash'
                 ORDER BY ID DESC
                 LIMIT 1",
                $species_name,
                $parent_id
            )
        );

        if (!$species_id) {
            return new WP_Error('unknown_subspecies', "Unknown subspecies: {$species_name} (parent: {$parent_species_name})", ['status' => 400]);
        }

        return (int) $species_id;
    }

    // Prefer top-level species.
    $species_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$posts_table}
             WHERE post_type = 'species' AND post_title = %s AND (post_parent IS NULL OR post_parent = 0) AND post_status <> 'trash'
             ORDER BY ID DESC
             LIMIT 1",
            $species_name
        )
    );

    // Fall back to any match.
    if (!$species_id) {
        $species_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$posts_table}
                 WHERE post_type = 'species' AND post_title = %s AND post_status <> 'trash'
                 ORDER BY ID DESC
                 LIMIT 1",
                $species_name
            )
        );
    }

    /**
     * Fuzzy-ish fallback: if there's still no exact match, try SOUNDEX.
     * This helps with common typos like "Pepper" vs "Peeper" coming from mobile keyboards.
     * Only accept it when there's a SINGLE clear match.
     */
    if (!$species_id) {
        if ($parent_id !== null) {
            $soundex_matches = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$posts_table}
                     WHERE post_type = 'species'
                       AND post_parent = %d
                       AND post_status <> 'trash'
                       AND SOUNDEX(post_title) = SOUNDEX(%s)
                     ORDER BY ID DESC
                     LIMIT 5",
                    $parent_id,
                    $species_name
                )
            );
        } else {
            $soundex_matches = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$posts_table}
                     WHERE post_type = 'species'
                       AND post_status <> 'trash'
                       AND SOUNDEX(post_title) = SOUNDEX(%s)
                     ORDER BY ID DESC
                     LIMIT 5",
                    $species_name
                )
            );
        }

        if (is_array($soundex_matches)) {
            $soundex_matches = array_values(array_unique(array_map('intval', $soundex_matches)));
            if (count($soundex_matches) === 1) {
                $species_id = $soundex_matches[0];
            }
        }
    }

    if (!$species_id) {
        // Provide suggestions for debugging UX.
        $like = '%' . $wpdb->esc_like($species_name) . '%';
        $suggestions = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_title FROM {$posts_table}
                 WHERE post_type = 'species'
                   AND post_status <> 'trash'
                   AND post_title LIKE %s
                 ORDER BY post_title ASC
                 LIMIT 10",
                $like
            )
        );

        $message = "Unknown species: {$species_name}";
        if (!empty($suggestions) && is_array($suggestions)) {
            $message .= '. Did you mean: ' . implode(', ', array_slice($suggestions, 0, 5)) . '?';
        } else {
            $message .= '. (Species are stored as WordPress posts with post_type="species".)';
        }

        return new WP_Error('unknown_species', $message, ['status' => 400]);
    }

    return (int) $species_id;
}

/**
 * Safely coerce a mixed param to an array.
 *
 * @return array
 */
function herp_param_to_array($value) {
    if (is_array($value)) {
        return $value;
    }
    if ($value === null) {
        return [];
    }
    return [$value];
}

/**
 * Returns first non-null value for any key in $keys.
 */
function herp_first_value(array $arr, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null) {
            return $arr[$k];
        }
    }
    return null;
}

/**
 * Normalize units like "F", "C", "Fahrenheit", "Celcius" etc to "F" or "C".
 */
function herp_normalize_temp_units($value): string {
    if ($value === null) {
        return '';
    }
    $s = strtoupper(trim((string) $value));
    if ($s === '') {
        return '';
    }
    if ($s[0] === 'F') {
        return 'F';
    }
    if ($s[0] === 'C') {
        return 'C';
    }
    return '';
}

/**
 * Normalize moon values to the enum used by `record.r_moon`.
 */
function herp_normalize_moon($value): string {
    if ($value === null) {
        return '';
    }
    $s = trim((string) $value);
    if ($s === '') {
        return '';
    }

    $map = [
        'New' => 'New Moon',
        'New Moon' => 'New Moon',
        'Waxing Crescent' => 'Waxing Crescent',
        'First Quarter' => 'First Quarter',
        'Waxing Gibbous' => 'Waxing Gibbous',
        'Full' => 'Full Moon',
        'Full Moon' => 'Full Moon',
        'Waning Gibbous' => 'Waning Gibbous',
        'Last Quarter' => 'Last Quarter',
        'Waning Crescent' => 'Waning Crescent',
        'Unknown' => 'Unknown',
    ];

    return $map[$s] ?? $s;
}

/**
 * Validate/normalize TRS fields to match DB enums.
 * If invalid, return '' to avoid insert failure.
 */
function herp_normalize_trs_township($value): string {
    if ($value === null) {
        return '';
    }
    $s = strtoupper(trim((string) $value));
    // DB enum is like 1N..68S (plus '').
    if (preg_match('/^\d{1,2}[NS]$/', $s)) {
        return $s;
    }
    return '';
}

function herp_normalize_trs_range($value): string {
    if ($value === null) {
        return '';
    }
    $s = strtoupper(trim((string) $value));
    // DB enum is like 1E..49W (plus '').
    if (preg_match('/^\d{1,2}[EW]$/', $s)) {
        return $s;
    }
    return '';
}

function herp_normalize_trs_section($value): string {
    if ($value === null) {
        return '';
    }
    $s = trim((string) $value);
    if ($s === '') {
        return '';
    }
    // DB enum is 1..36 (plus '').
    if (preg_match('/^(?:[1-9]|[12]\d|3[0-6])$/', $s)) {
        return $s;
    }
    return '';
}

/**
 * Build a MySQL datetime string from separate date/time fields.
 * Accepts:
 * - date: "YYYY-MM-DD"
 * - time: "HH:MM[:SS]"
 */
function herp_build_datetime($date_value, $time_value): ?string {
    $date = $date_value !== null ? trim((string) $date_value) : '';
    $time = $time_value !== null ? trim((string) $time_value) : '';

    if ($date === '' && $time === '') {
        return null;
    }

    if ($date !== '' && $time === '') {
        return $date . ' 00:00:00';
    }

    if ($date === '') {
        return null;
    }

    // Normalize HH:MM to HH:MM:SS
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $time .= ':00';
    }

    return $date . ' ' . $time;
}

/**
 * Allocate a unique `record.r_id` when the database is not AUTO_INCREMENTing it.
 * Uses a named MySQL lock to avoid race conditions.
 *
 * @return int|WP_Error
 */
function herp_allocate_record_id(int $timeout_seconds = 5) {
    global $wpdb;

    $locked = $wpdb->get_var(
        $wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'herp_record_rid', $timeout_seconds)
    );

    if ((int) $locked !== 1) {
        return new WP_Error('record_id_lock_failed', 'Could not allocate record id (lock timeout)', ['status' => 503]);
    }

    try {
        // Ignore broken legacy rows where r_id = 0.
        $next = (int) $wpdb->get_var('SELECT COALESCE(MAX(r_id), 0) + 1 FROM record WHERE r_id > 0');
        return $next > 0 ? $next : 1;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'herp_record_rid'));
    }
}

/**
 * Convert a PHP multi-upload entry into a flat list of file arrays.
 *
 * Expected input: $_FILES['files'] where each key (name/type/tmp_name/error/size)
 * contains an array.
 *
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function herp_normalize_uploaded_files(array $files): array {
    // REST-style: already a list of file arrays.
    if (array_is_list($files) && isset($files[0]) && is_array($files[0]) && array_key_exists('tmp_name', $files[0])) {
        $out = [];
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($f['name'] ?? ''),
                'type' => (string) ($f['type'] ?? ''),
                'tmp_name' => (string) ($f['tmp_name'] ?? ''),
                'error' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($f['size'] ?? 0),
            ];
        }
        return $out;
    }

    // Already normalized (single file).
    if (!empty($files['tmp_name']) && is_string($files['tmp_name'])) {
        return [[
            'name' => (string) ($files['name'] ?? ''),
            'type' => (string) ($files['type'] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'] ?? 0),
        ]];
    }

    $out = [];
    $names = $files['name'] ?? [];
    $types = $files['type'] ?? [];
    $tmp_names = $files['tmp_name'] ?? [];
    $errors = $files['error'] ?? [];
    $sizes = $files['size'] ?? [];

    $count = is_array($names) ? count($names) : 0;
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => (string) ($names[$i] ?? ''),
            'type' => (string) ($types[$i] ?? ''),
            'tmp_name' => (string) ($tmp_names[$i] ?? ''),
            'error' => (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$i] ?? 0),
        ];
    }

    return $out;
}

/**
 * Upload a single file to WP media library under /vouchers and attach to $post_id.
 *
 * @return int|WP_Error Attachment ID
 */
function herp_upload_voucher_to_media(array $file, int $post_id, ?int $actor_user_id = null) {
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'Invalid post_id for voucher upload', ['status' => 500]);
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', 'Upload error for voucher file', ['status' => 400, 'upload_error' => $file['error'] ?? null]);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !file_exists($tmp) || !is_readable($tmp)) {
        return new WP_Error('invalid_upload', 'Invalid uploaded file tmp_name', ['status' => 400]);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $prev_user_id = get_current_user_id();
    if ($actor_user_id !== null && $actor_user_id > 0 && $prev_user_id !== $actor_user_id) {
        wp_set_current_user($actor_user_id);
    }

    // Store all vouchers under /vouchers (same as the theme).
    $upload_filter = static function ($upload) {
        $upload['subdir'] = '/vouchers';
        $upload['path'] = $upload['basedir'] . $upload['subdir'];
        $upload['url'] = $upload['baseurl'] . $upload['subdir'];
        return $upload;
    };
    add_filter('upload_dir', $upload_filter);

    try {
        $overrides = ['test_form' => false];
        $moved = wp_handle_upload($file, $overrides);

        if (!is_array($moved) || !empty($moved['error'])) {
            return new WP_Error('upload_move_failed', 'Could not move uploaded voucher file', ['status' => 500, 'error' => $moved['error'] ?? 'unknown']);
        }

        $file_path = $moved['file'];
        $file_url = $moved['url'];
        $mime_type = $moved['type'] ?? '';

        $attachment = [
            'guid' => $file_url,
            'post_mime_type' => $mime_type,
            'post_title' => sanitize_file_name(pathinfo($file_path, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        // If wp_posts.ID isn't auto-incrementing, force a unique ID for the attachment too.
        $posts_has_ai = herp_wp_posts_has_auto_increment();
        if (!$posts_has_ai) {
            $allocated_post_id = herp_allocate_wp_post_id();
            if (!is_wp_error($allocated_post_id)) {
                $attachment['import_id'] = (int) $allocated_post_id;
            }
        }

        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id, true);
        if (is_wp_error($attach_id) || !$attach_id) {
            // Retry once with a fresh import_id.
            $retry = $attachment;
            $retry_alloc = herp_allocate_wp_post_id();
            if (!is_wp_error($retry_alloc)) {
                $retry['import_id'] = (int) $retry_alloc;
                $attach_id = wp_insert_attachment($retry, $file_path, $post_id, true);
            }

            // Fallback: sometimes wp_insert_attachment returns 0 even though a row was inserted.
            if (!is_wp_error($attach_id) && !$attach_id) {
                global $wpdb;
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_type = 'attachment' AND guid = %s
                         ORDER BY ID DESC
                         LIMIT 1",
                        $file_url
                    )
                );
                if ($found) {
                    $attach_id = (int) $found;
                }
            }

            if (is_wp_error($attach_id) || !$attach_id) {
                global $wpdb;
                $data = [
                    'status' => 500,
                    'file_path' => $file_path,
                    'file_url' => $file_url,
                    'mime_type' => $mime_type,
                    'db_last_error' => $wpdb->last_error,
                    'db_last_query' => $wpdb->last_query,
                ];
                if (is_wp_error($attach_id)) {
                    $data['inner_code'] = $attach_id->get_error_code();
                    $data['wp_error_code'] = $attach_id->get_error_code();
                    $data['wp_error_message'] = $attach_id->get_error_message();
                    $data['wp_error_data'] = $attach_id->get_error_data();
                } else {
                    $data['inner_code'] = 'wp_insert_attachment_returned_0';
                }
                return new WP_Error('attachment_insert_failed', 'Could not create attachment post', $data);
            }
        }

        $attach_id = (int) $attach_id;

        // Ensure the attached file meta is set (some environments skip it on weird insert flows).
        $uploads = wp_get_upload_dir();
        $relative = $file_path;
        if (!empty($uploads['basedir']) && str_starts_with($file_path, $uploads['basedir'] . '/')) {
            $relative = substr($file_path, strlen($uploads['basedir']) + 1);
        }
        if (is_string($relative) && $relative !== '') {
            update_post_meta($attach_id, '_wp_attached_file', $relative);
        }

        // Generating image sizes can be very slow on local setups; attachments still work without it.
        // If you need thumbnails later, we can re-enable this.

        return $attach_id;
    } finally {
        remove_filter('upload_dir', $upload_filter);
        if ($actor_user_id !== null && $actor_user_id > 0 && $prev_user_id !== $actor_user_id) {
            wp_set_current_user($prev_user_id);
        }
    }
}

/**
 * Insert a row into the legacy `voucher` table (if it exists).
 * Maps kinds: image/video => Photo, audio => Audio.
 */
/**
 * Insert a row into the legacy voucher table and return v_id.
 *
 * @return int|WP_Error v_id on success, 0 if voucher table missing
 */
function herp_insert_legacy_voucher_row(int $owner_id, int $record_id, string $file_kind) {
    global $wpdb;

    // Some installs use an un-prefixed table (`voucher`), others may use a prefixed table (`wp_voucher`).
    $candidates = ['voucher', $wpdb->prefix . 'voucher'];
    $table = null;
    foreach ($candidates as $candidate) {
        // Escape _ and % for LIKE (WordPress helper).
        $pattern = $wpdb->esc_like($candidate);
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pattern));
        if ($exists) {
            $table = $candidate;
            break;
        }
    }

    if (!$table) {
        return 0;
    }

    $kind = strtolower(trim($file_kind));
    $v_type = ($kind === 'audio') ? 'Audio' : 'Photo';

    $extra = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'v_id'
             LIMIT 1",
            $wpdb->dbname,
            $table
        )
    );
    $has_ai = is_string($extra) && stripos($extra, 'auto_increment') !== false;

    if ($has_ai) {
        $ok = $wpdb->insert($table, [
            'v_owner' => $owner_id,
            'v_record' => $record_id,
            'v_type' => $v_type,
        ]);
        if ($ok === false) {
            return new WP_Error('voucher_insert_failed', 'Could not insert voucher row', ['status' => 500, 'db_last_error' => $wpdb->last_error, 'db_last_query' => $wpdb->last_query]);
        }

        $vid = (int) $wpdb->insert_id;
        if ($vid > 0) {
            return $vid;
        }

        // Fallback if insert_id is not reliable.
        $vid = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT v_id FROM {$table} WHERE v_owner = %d AND v_record = %d AND v_type = %s ORDER BY v_id DESC LIMIT 1", $owner_id, $record_id, $v_type)
        );
        return $vid > 0 ? $vid : 0;
    }

    $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'herp_voucher_vid', 5));
    if ((int) $locked !== 1) {
        return new WP_Error('voucher_id_lock_failed', 'Could not allocate voucher id (lock timeout)', ['status' => 503]);
    }

    try {
        $next = (int) $wpdb->get_var("SELECT COALESCE(MAX(v_id), 0) + 1 FROM {$table} WHERE v_id > 0");
        if ($next <= 0) {
            $next = 1;
        }

        $ok = $wpdb->insert($table, [
            'v_id' => $next,
            'v_owner' => $owner_id,
            'v_record' => $record_id,
            'v_type' => $v_type,
        ]);
        if ($ok === false) {
            return new WP_Error('voucher_insert_failed', 'Could not insert voucher row', ['status' => 500, 'db_last_error' => $wpdb->last_error, 'db_last_query' => $wpdb->last_query]);
        }

        return $next;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'herp_voucher_vid'));
    }
}

/**
 * Check whether wp_posts.ID is AUTO_INCREMENT.
 */
function herp_wp_posts_has_auto_increment(): bool {
    global $wpdb;

    $posts_table = $wpdb->posts; // e.g. wp_posts

    // Prefer SHOW FULL COLUMNS (works even when INFORMATION_SCHEMA is restricted).
    $row = $wpdb->get_row("SHOW FULL COLUMNS FROM {$posts_table} LIKE 'ID'", ARRAY_A);
    if (is_array($row) && isset($row['Extra'])) {
        return stripos((string) $row['Extra'], 'auto_increment') !== false;
    }

    // Fallback to INFORMATION_SCHEMA; if we can't determine it, assume true.
    $extra = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT EXTRA FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'ID'
             LIMIT 1",
            $wpdb->dbname,
            $posts_table
        )
    );

    if ($extra === null) {
        return true;
    }

    return is_string($extra) && stripos($extra, 'auto_increment') !== false;
}

/**
 * Allocate a unique wp_posts.ID when the DB isn't AUTO_INCREMENTing it.
 * Uses a named lock to prevent collisions.
 *
 * @return int|WP_Error
 */
function herp_allocate_wp_post_id(int $timeout_seconds = 5) {
    global $wpdb;

    $locked = $wpdb->get_var(
        $wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'herp_wp_posts_id', $timeout_seconds)
    );

    if ((int) $locked !== 1) {
        return new WP_Error('wp_post_id_lock_failed', 'Could not allocate wp_posts ID (lock timeout)', ['status' => 503]);
    }

    try {
        $posts_table = $wpdb->posts;
        $next = (int) $wpdb->get_var("SELECT COALESCE(MAX(ID), 0) + 1 FROM {$posts_table} WHERE ID > 0");
        return $next > 0 ? $next : 1;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'herp_wp_posts_id'));
    }
}

/**
 * POST /wp-json/herp/v1/records
 *
 * Body (recommended JSON):
 * {
 *   "user_id": 123,
 *   "record": { "latitude": 1.23, "longitude": 4.56, "county": "Washtenaw County", "time": "2026-01-27 12:34:56", ... },
 *   "animals": [
 *     { "group": "Frogs and Toads", "species": "Green Frog", "parent_species": null, "quantity_observed": 1, "sex": "Unknown", "age": "Adult", ... }
 *   ]
 * }
 *
 * Returns:
 * { "success": true, "created": [ { "record_id": 1, "post_id": 123 }, ... ] }
 */
function herp_create_record(WP_REST_Request $request) {
    global $wpdb;

    /**
     * Your environment is printing PHP warnings into the HTTP response body,
     * which breaks JSON parsing for the app. We suppress warning output for this
     * endpoint and capture them for debugging instead.
     */
    $herp_warnings = [];
    $herp_buffered_output = '';
    $prev_display_errors = ini_get('display_errors');
    @ini_set('display_errors', '0');
    $prev_error_handler = set_error_handler(static function ($errno, $errstr, $errfile, $errline) use (&$herp_warnings) {
        // Capture common noisy levels; let fatals still bubble.
        $capture = [
            E_WARNING,
            E_NOTICE,
            E_USER_WARNING,
            E_USER_NOTICE,
            E_DEPRECATED,
            E_USER_DEPRECATED,
        ];
        if (!in_array($errno, $capture, true)) {
            return false;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $first_app_frame = null;
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (
                is_string($file) &&
                $file !== '' &&
                strpos($file, DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR) === false &&
                strpos($file, DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR) === false
            ) {
                $first_app_frame = $frame;
                break;
            }
        }

        $herp_warnings[] = [
            'errno' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'first_app_frame' => $first_app_frame ? [
                'file' => $first_app_frame['file'] ?? null,
                'line' => $first_app_frame['line'] ?? null,
                'function' => $first_app_frame['function'] ?? null,
            ] : null,
        ];

        // Prevent PHP from printing this warning.
        return true;
    });
    ob_start();

    $payload = $request->get_json_params();
    if (!is_array($payload) || empty($payload)) {
        $payload = $request->get_body_params();
    }
    if (!is_array($payload)) {
        $payload = [];
    }

    // Always use the authenticated user as the author.
    // (Prevents payload/user mismatch like "user_id": 42 when you're logged in as someone else.)
    $current_user_id = get_current_user_id();
    if ($current_user_id <= 0) {
        return new WP_Error('not_logged_in', 'You must be logged in to create records', ['status' => 401]);
    }

    $payload_user_id = intval($payload['user_id'] ?? 0);
    if ($payload_user_id && $payload_user_id !== $current_user_id) {
        return new WP_Error('user_id_mismatch', 'Payload user_id does not match logged-in user', ['status' => 400]);
    }

    $user_id = $current_user_id;

    // Support BOTH shapes:
    // 1) New recommended:
    //    { user_id, record: {...}, animals: [...] }
    // 2) Alyssa's app model (flat):
    //    { user_id, date, time, latitude, longitude, county, ..., animals/Animals: [...] }
    $record = is_array($payload['record'] ?? null) ? $payload['record'] : [];
    if (empty($record)) {
        $record = $payload;
    }

    $animals = $payload['animals'] ?? ($payload['Animals'] ?? []);
    // Multipart forms often send JSON strings for nested objects.
    if (is_string($animals) && $animals !== '') {
        $decoded = json_decode($animals, true);
        if (is_array($decoded)) {
            $animals = $decoded;
        }
    }
    $animals = herp_param_to_array($animals);

    if (empty($animals)) {
        // Allow single-animal payloads.
        $single = is_array($payload['animal'] ?? null) ? $payload['animal'] : null;
        if ($single) {
            $animals = [$single];
        }
    }

    if (empty($animals)) {
        return new WP_Error('missing_animals', 'Missing animals array', ['status' => 400]);
    }

    // Multipart voucher uploads (from app):
    // - files[]                 (uploaded files)
    // - assigned_animal_index[] (0-based index into animals)
    // - file_kind[]             ("image" | "video" | "audio")
    //
    // IMPORTANT: For WP REST requests, use $request->get_file_params() (more reliable than $_FILES).
    $uploaded_files = [];
    $file_params = $request->get_file_params();
    if (!empty($file_params['files']) && is_array($file_params['files'])) {
        $uploaded_files = herp_normalize_uploaded_files($file_params['files']);
    } elseif (!empty($_FILES['files']) && is_array($_FILES['files'])) {
        $uploaded_files = herp_normalize_uploaded_files($_FILES['files']);
    }

    $assigned_animal_index = $request->get_param('assigned_animal_index');
    $file_kind = $request->get_param('file_kind');
    if (!is_array($assigned_animal_index)) {
        $assigned_animal_index = [$assigned_animal_index];
    }
    if (!is_array($file_kind)) {
        $file_kind = [$file_kind];
    }

    // Resolve county from name (or accept numeric id).
    $county_id = 0;
    $county_value = herp_first_value($record, ['county_id', 'countyId', 'CountyId', 'county', 'County']);
    if ($county_value !== null && is_numeric($county_value)) {
        $county_id = intval($county_value);
    } elseif (!empty($county_value)) {
        $county_id = herp_find_county_id_by_name((string) $county_value);
        if (is_wp_error($county_id)) {
            return $county_id;
        }
    }

    $latitude = (float) (herp_first_value($record, ['latitude', 'Latitude']) ?? 0.0);
    $longitude = (float) (herp_first_value($record, ['longitude', 'Longitude']) ?? 0.0);

    // Accept combined datetime or separate date/time.
    // (Do NOT treat the app's `time` field as a full datetime.)
    $datetime = herp_first_value($record, ['datetime', 'dateTime', 'DateTime']);
    $date_value = herp_first_value($record, ['date', 'Date']);
    $time_value = herp_first_value($record, ['time', 'Time']);

    $time = null;
    if ($datetime !== null && is_string($datetime) && trim($datetime) !== '') {
        // If app passes only time (HH:MM:SS), we'll need Date too; otherwise accept full datetime.
        $dt = trim($datetime);
        $time = (strpos($dt, '-') !== false && strpos($dt, ':') !== false) ? $dt : null;
    }
    if ($time === null) {
        $time = herp_build_datetime($date_value, $time_value);
    }

    $default_sensitive = get_user_meta($user_id, 'preferences_security_level', true);
    $security_level = (!empty($default_sensitive) && $default_sensitive === 'sensitive') ? 'Sensitive' : 'Non-sensitive';

    $record_notes = (string) (herp_first_value($record, ['notes', 'Notes']) ?? '');
    $record_locale = (string) (herp_first_value($record, ['locale', 'Locale', 'area', 'Area']) ?? '');
    $raw_township = (string) (herp_first_value($record, ['township', 'Township']) ?? '');
    $raw_range = (string) (herp_first_value($record, ['range', 'Range']) ?? '');
    $raw_section = (string) (herp_first_value($record, ['section', 'Section']) ?? '');

    // TRS fields must match DB enums; otherwise the insert can fail.
    $record_township = herp_normalize_trs_township($raw_township);
    $record_range = herp_normalize_trs_range($raw_range);
    $record_section = herp_normalize_trs_section($raw_section);

    // If the app sent a human-readable township name (e.g. "Delhi Township"),
    // it won't fit the DB enum, so preserve it in locale text.
    if ($record_township === '' && trim($raw_township) !== '') {
        $record_locale = trim($record_locale . "\nTownship: " . trim($raw_township));
    }

    // Preserve invalid TRS values similarly (but avoid duplicating if already valid).
    if ($record_range === '' && trim($raw_range) !== '') {
        $record_locale = trim($record_locale . "\nRange: " . trim($raw_range));
    }
    if ($record_section === '' && trim($raw_section) !== '') {
        $record_locale = trim($record_locale . "\nSection: " . trim($raw_section));
    }

    $record_humidity = intval(herp_first_value($record, ['humidity', 'Humidity']) ?? 0);
    $record_sky = (string) (herp_first_value($record, ['sky', 'Sky']) ?? '');
    $record_moon = herp_normalize_moon(herp_first_value($record, ['moon', 'Moon']));

    $record_airtemp = (float) (herp_first_value($record, ['air_temp', 'airTemp', 'AirTemp']) ?? 0);
    $record_airtemp_units = herp_normalize_temp_units(herp_first_value($record, ['air_temp_units', 'airTempUnits', 'FCAir', 'fcAir']));

    $record_groundtemp = (float) (herp_first_value($record, ['ground_temp', 'groundTemp', 'GroundTemp']) ?? 0);
    $record_groundtemp_units = herp_normalize_temp_units(herp_first_value($record, ['ground_temp_units', 'groundTempUnits', 'FCGround', 'fcGround']));

    $created = [];

    foreach ($animals as $animal_idx => $animal) {
        if (!is_array($animal)) {
            continue;
        }

        // Group: accept id or name.
        $group_id = 0;
        $group_value = herp_first_value($animal, ['group_id', 'groupId', 'GroupId', 'group', 'Group']);
        if ($group_value !== null && is_numeric($group_value)) {
            $group_id = intval($group_value);
        } elseif (!empty($group_value)) {
            $group_id = herp_find_group_id_by_name((string) $group_value);
            if (is_wp_error($group_id)) {
                return $group_id;
            }
        } else {
            return new WP_Error('missing_group', 'Missing animal.group', ['status' => 400]);
        }

        // Species: accept id or name (+ optional parent for subspecies).
        $taxon_id = 0;
        $species_value = herp_first_value($animal, ['species_id', 'speciesId', 'SpeciesId', 'species', 'Species']);
        if ($species_value !== null && is_numeric($species_value)) {
            $taxon_id = intval($species_value);
        } elseif (!empty($species_value)) {
            $taxon_id = herp_find_species_id_by_name(
                (string) $species_value,
                ($animal['parent_species'] ?? $animal['parentSpecies'] ?? $animal['ParentSpecies'] ?? null) !== null
                    ? (string) ($animal['parent_species'] ?? $animal['parentSpecies'] ?? $animal['ParentSpecies'])
                    : null
            );
            if (is_wp_error($taxon_id)) {
                return $taxon_id;
            }
        } else {
            return new WP_Error('missing_species', 'Missing animal.species', ['status' => 400]);
        }

        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );

        // Insert record row (website inserts first, then creates post).
        // Quantity: accept Quantity, quantity, quantity_observed, etc.
        $qty = herp_first_value($animal, ['quantity_observed', 'quantityObserved', 'QuantityObserved', 'quantity', 'Quantity']);
        $qty = $qty !== null ? intval($qty) : 1;
        if ($qty <= 0) {
            $qty = 1;
        }

        $sex = (string) (herp_first_value($animal, ['sex', 'Sex']) ?? '');
        $age = (string) (herp_first_value($animal, ['age', 'Age']) ?? '');
        $disease = (string) (herp_first_value($animal, ['disease', 'Disease']) ?? 'No');

        // Allocate a proper record id up front (your DB isn't auto-incrementing r_id).
        $record_id = 0;

        $insert = [
            // IMPORTANT: r_id is not auto-incrementing locally.
            'r_id'               => 0,
            'r_uuid'             => (string) $uuid,
            'r_source'           => 'mobile',
            'r_searchtime'       => intval(herp_first_value($record, ['search_time', 'searchTime', 'SearchTime']) ?? 0),
            'r_animal'           => 0,
            'r_time'             => $time,
            'r_accuracy'         => intval(herp_first_value($record, ['accuracy', 'Accuracy']) ?? 0),
            'r_group'            => $group_id,
            'r_taxon'            => $taxon_id,
            'r_sex'              => $sex,
            'r_age'              => $age,
            'r_qty'              => $qty,
            'r_disease'          => $disease,
            'r_bodytemp'         => (float) (herp_first_value($animal, ['body_temp', 'bodyTemp', 'BodyTemp']) ?? 0),
            'r_bodytemp_units'   => herp_normalize_temp_units(herp_first_value($animal, ['body_temp_units', 'bodyTempUnits', 'BodyTempUnits'])),
            'r_latitude'         => $latitude,
            'r_longitude'        => $longitude,
            'r_county'           => $county_id ? $county_id : 0,
            'r_locale'           => $record_locale,
            'r_elevation'        => (float) (herp_first_value($record, ['elevation', 'Elevation']) ?? 0),
            'r_habitat'          => (string) (herp_first_value($record, ['habitat', 'Habitat']) ?? ''),
            // Some DBs require the misspelled column too.
            'r_habbitat'         => (string) (herp_first_value($record, ['habbitat', 'Habbitat', 'habitat', 'Habitat']) ?? ''),
            'r_method'           => (string) (herp_first_value($record, ['method', 'Method']) ?? ''),
            'r_coordmethod'      => (string) (herp_first_value($record, ['coord_method', 'coordMethod', 'CoordMethod', 'coord-method', 'Coord-Method']) ?? ''),
            'r_datum'            => (string) (herp_first_value($record, ['datum', 'Datum']) ?? ''),
            'r_airtemp'          => $record_airtemp,
            'r_airtemp_units'    => $record_airtemp_units,
            'r_groundtemp'       => $record_groundtemp,
            'r_groundtemp_units' => $record_groundtemp_units,
            'r_humidity'         => $record_humidity,
            'r_research_id'      => (string) (herp_first_value($record, ['research_id', 'researchId', 'ResearchId']) ?? ''),
            'r_observers'        => (string) (herp_first_value($record, ['other_observers', 'otherObservers', 'OtherObservers']) ?? ''),
            'r_notes'            => $record_notes,
            'r_admin_notes'      => (string) (herp_first_value($record, ['admin_notes', 'adminNotes', 'AdminNotes']) ?? ''),
            'r_restricted'       => 0,
            'r_anonymous'        => !empty(herp_first_value($record, ['anonymous', 'Anonymous'])) ? 1 : 0,
            'r_township'         => $record_township,
            'r_range'            => $record_range,
            'r_section'          => $record_section,
            'r_moon'             => $record_moon,
            'r_sky'              => $record_sky,
            'r_security'         => $security_level,
            'r_owner'            => (string) $user_id,
        ];

        $ok = false;
        $insert_rows_affected = 0;
        $insert_last_error = '';
        $insert_last_query = '';

        // Retry a few times in case of a race / duplicate key.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $allocated = herp_allocate_record_id();
            if (is_wp_error($allocated)) {
                return $allocated;
            }

            $record_id = (int) $allocated;
            $insert['r_id'] = $record_id;

            $ok = $wpdb->insert('record', $insert);
            $insert_rows_affected = (int) $wpdb->rows_affected;
            $insert_last_error = (string) $wpdb->last_error;
            $insert_last_query = (string) $wpdb->last_query;

            if ($ok !== false) {
                break;
            }

            // If it's a duplicate key on r_id, loop and try a new id.
            if (stripos($insert_last_error, 'Duplicate') !== false) {
                continue;
            }

            break;
        }

        // True failure: the INSERT returned false.
        if ($ok === false) {
            $debug = (defined('WP_DEBUG') && WP_DEBUG);

            $data = ['status' => 500];
            if ($debug) {
                $data['db_last_error'] = $insert_last_error;
                $data['db_last_query'] = $insert_last_query;
                $data['db_insert_id'] = (int) $wpdb->insert_id;
                $data['db_rows_affected'] = $insert_rows_affected;

                // Sometimes WPDB leaves last_error empty; ask the driver directly.
                if (!empty($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
                    $data['db_errno'] = mysqli_errno($wpdb->dbh);
                    $data['db_driver_error'] = mysqli_error($wpdb->dbh);
                    $data['db_sqlstate'] = mysqli_sqlstate($wpdb->dbh);
                }
            }

            return new WP_Error('db_insert_failed', 'Error creating record in database', $data);
        }

        if ($record_id <= 0) {
            $debug = (defined('WP_DEBUG') && WP_DEBUG);
            $data = ['status' => 500];
            if ($debug) {
                $data['db_last_error'] = $insert_last_error;
                $data['db_last_query'] = $insert_last_query;
                $data['db_insert_id'] = (int) $wpdb->insert_id;
                $data['db_rows_affected'] = $insert_rows_affected;
                $data['uuid'] = (string) $uuid;
            }
            return new WP_Error('db_insert_failed', 'Record inserted but could not determine r_id', $data);
        }

        // Create WP record post.
        // WordPress may refuse to insert posts if the *current* user lacks capabilities.
        // We'll keep `post_author` = the real authenticated user, but optionally run the insert
        // as a privileged "replacement user" (theme option) if needed.
        $prev_user_id = get_current_user_id();

        $author_id = $user_id;
        $actor_id = $author_id;

        if (!user_can($author_id, 'edit_posts')) {
            $replacement_user = intval(get_option('options_replacement_user'));
            if ($replacement_user > 0 && get_user_by('id', $replacement_user)) {
                // If actor is different from author, actor must be able to edit others' posts.
                if (
                    user_can($replacement_user, 'edit_posts') &&
                    user_can($replacement_user, 'edit_others_posts')
                ) {
                    $actor_id = $replacement_user;
                }
            }
        }

        // If the post type isn't registered (e.g. theme not loaded), fail with details.
        if (!post_type_exists('record')) {
            return new WP_Error(
                'record_post_type_missing',
                'Post type "record" is not registered. Is the theme active?',
                [
                    'status' => 500,
                    'record_id' => $record_id,
                    'user_id' => $author_id,
                ]
            );
        }

        if ($prev_user_id !== $actor_id) {
            wp_set_current_user($actor_id);
        }

        // Use a status that won't be rejected by capability checks.
        $post_status = user_can($actor_id, 'publish_posts') ? 'publish' : 'pending';

        $posts_has_ai = herp_wp_posts_has_auto_increment();

        // Title should be the species name (app expectation).
        $taxon_post = get_post($taxon_id);
        $species_title = (!empty($taxon_post) && !is_wp_error($taxon_post)) ? (string) $taxon_post->post_title : '';
        if ($species_title === '') {
            $species_title = "Record #{$record_id}";
        }

        // Set a deterministic slug so we can look the post up even if wp_insert_post returns 0.
        $desired_slug = 'record-' . $record_id;

        $post_args = [
            'post_title'   => $species_title,
            'post_content' => '',
            'post_status'  => $post_status,
            'post_author'  => $author_id,
            'post_type'    => 'record',
            'post_name'    => $desired_slug,
        ];

        // If wp_posts.ID is not AUTO_INCREMENT-ing in this environment, force a unique ID.
        if (!$posts_has_ai) {
            $allocated_post_id = herp_allocate_wp_post_id();
            if (is_wp_error($allocated_post_id)) {
                return $allocated_post_id;
            }
            $post_args['import_id'] = (int) $allocated_post_id;
        }

        $post_id = wp_insert_post($post_args, true);

        if ($prev_user_id !== $actor_id) {
            wp_set_current_user($prev_user_id);
        }

        // Fallback: sometimes wp_insert_post returns 0 even though a row was inserted.
        if (!is_wp_error($post_id) && !$post_id) {
            $post_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'record' AND post_name = %s ORDER BY ID DESC LIMIT 1",
                    $desired_slug
                )
            );
        }

        // Fallback #2: retry once with a forced ID.
        if (
            (is_wp_error($post_id) && $post_id->get_error_code() === 'db_insert_error') ||
            (!is_wp_error($post_id) && !$post_id)
        ) {
            $allocated_post_id = herp_allocate_wp_post_id();
            if (!is_wp_error($allocated_post_id)) {
                $post_args['import_id'] = (int) $allocated_post_id;
                $post_id = wp_insert_post($post_args, true);
            }
        }

        if (is_wp_error($post_id) || !$post_id) {
            // Don't leave orphaned DB record without a post pointer.
            $wpdb->update('record', ['r_post_id' => null], ['r_id' => $record_id]);

            // Always return the WP error message/code (essential for debugging).
            $data = [
                'status' => 500,
                'record_id' => $record_id,
                'user_id' => $user_id,
                'post_type_exists' => post_type_exists('record'),
                'post_status' => $post_status ?? null,
                'actor_id' => $actor_id ?? null,
                'author_can_edit_posts' => user_can($author_id, 'edit_posts'),
                'actor_can_edit_posts' => user_can($actor_id, 'edit_posts'),
                'actor_can_edit_others_posts' => user_can($actor_id, 'edit_others_posts'),
                'desired_slug' => $desired_slug ?? null,
                'species_title' => $species_title ?? null,
                'posts_has_auto_increment' => $posts_has_ai ?? null,
            ];

            if (is_wp_error($post_id)) {
                $data['wp_error_code'] = $post_id->get_error_code();
                $data['wp_error_message'] = $post_id->get_error_message();
                $data['wp_error_data'] = $post_id->get_error_data();
            } else {
                $data['wp_error_code'] = 'wp_insert_post_returned_0';
                $data['wp_error_message'] = 'wp_insert_post returned 0 (no WP_Error object)';
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $data['current_user_id'] = get_current_user_id();
                // DB-level diagnostics for wp_posts insert failures.
                $data['posts_db_last_error'] = $wpdb->last_error;
                $data['posts_db_last_query'] = $wpdb->last_query;
                if (!empty($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
                    $data['posts_db_errno'] = mysqli_errno($wpdb->dbh);
                    $data['posts_db_driver_error'] = mysqli_error($wpdb->dbh);
                    $data['posts_db_sqlstate'] = mysqli_sqlstate($wpdb->dbh);
                }
            }

            return new WP_Error('post_create_failed', 'Error creating WP record post', $data);
        }

        $post_id = (int) $post_id;

        // Handle uploaded vouchers for this animal (multipart form).
        $voucher_attachment_ids = [];
        $voucher_legacy_vids = [];
        $voucher_upload_errors = [];
        $file_count = count($uploaded_files);
        for ($i = 0; $i < $file_count; $i++) {
            $assigned_idx = isset($assigned_animal_index[$i]) ? intval($assigned_animal_index[$i]) : null;
            if ($assigned_idx === null || $assigned_idx !== intval($animal_idx)) {
                continue;
            }

            $kind = isset($file_kind[$i]) ? (string) $file_kind[$i] : 'image';

            // Insert into legacy voucher table first so we can name the file like production did:
            // /vouchers/{record_id}-{v_id}.ext
            $vid = herp_insert_legacy_voucher_row($user_id, $record_id, $kind);
            if (is_wp_error($vid)) {
                $voucher_upload_errors[] = [
                    'file_index' => $i,
                    'assigned_animal_index' => $assigned_idx,
                    'file_kind' => $kind,
                    'error_code' => $vid->get_error_code(),
                    'error_message' => $vid->get_error_message(),
                    'error_data' => $vid->get_error_data(),
                ];
                continue;
            }

            $file_for_upload = $uploaded_files[$i];
            if (is_int($vid) && $vid > 0) {
                $ext = pathinfo((string) ($file_for_upload['name'] ?? ''), PATHINFO_EXTENSION);
                $ext = $ext ? ('.' . $ext) : '';
                $file_for_upload['name'] = $record_id . '-' . $vid . $ext;
            }

            $attach_id = herp_upload_voucher_to_media($file_for_upload, $post_id, $actor_id ?? null);
            if (is_wp_error($attach_id)) {
                $voucher_upload_errors[] = [
                    'file_index' => $i,
                    'assigned_animal_index' => $assigned_idx,
                    'file_kind' => $kind,
                    'error_code' => $attach_id->get_error_code(),
                    'error_message' => $attach_id->get_error_message(),
                    'error_data' => $attach_id->get_error_data(),
                ];
                continue;
            }

            $voucher_attachment_ids[] = (int) $attach_id;

            // Keep track of the legacy voucher id we created (if any).
            $voucher_legacy_vids[] = is_int($vid) ? $vid : 0;
        }

        // Mirror website behavior: store voucher attachment IDs in the ACF `vouchers` field.
        if (!empty($voucher_attachment_ids) && function_exists('update_field') && function_exists('get_field')) {
            $existing = get_field('vouchers', $post_id);
            $existing_ids = [];
            if (is_array($existing)) {
                foreach ($existing as $ex) {
                    if (is_array($ex) && !empty($ex['ID'])) {
                        $existing_ids[] = intval($ex['ID']);
                    } elseif (is_numeric($ex)) {
                        $existing_ids[] = intval($ex);
                    }
                }
            }

            $merged = array_values(array_unique(array_merge($existing_ids, $voucher_attachment_ids)));
            update_field('vouchers', $merged, $post_id);
        }

        // Link DB record -> post.
        $updated = $wpdb->update('record', ['r_post_id' => $post_id], ['r_id' => $record_id]);
        if ($updated === false || (int) $wpdb->rows_affected === 0) {
            // Fallback (shouldn't be needed, but helps with weird local DB states).
            $wpdb->update('record', ['r_post_id' => $post_id], ['r_uuid' => (string) $uuid]);
        }

        // Prevent theme from trying to auto-create a record row later.
        update_post_meta($post_id, 'record_been_saved', 1);

        // Optionally set the county taxonomy term on the post (handy for WP-side browsing).
        if ($county_id) {
            wp_set_object_terms($post_id, [$county_id], 'county', false);
        }

        $include_voucher_debug = current_user_can('manage_options') || (defined('WP_DEBUG') && WP_DEBUG);

        $created_item = [
            'record_id' => $record_id,
            'post_id'   => $post_id,
            'voucher_attachment_ids' => $voucher_attachment_ids,
            'voucher_legacy_vids' => $voucher_legacy_vids,
        ];

        if ($include_voucher_debug) {
            $created_item['voucher_received_count'] = $file_count;
            $created_item['voucher_assigned_animal_index'] = $assigned_animal_index;
            $created_item['voucher_file_kind'] = $file_kind;
            $created_item['voucher_upload_errors'] = $voucher_upload_errors;
        }

        $created[] = $created_item;
    }

    if (empty($created)) {
        return new WP_Error('no_records_created', 'No records created (invalid animals payload)', ['status' => 400]);
    }

    $herp_buffered_output = (string) ob_get_clean();
    if (is_callable($prev_error_handler)) {
        set_error_handler($prev_error_handler);
    } else {
        restore_error_handler();
    }
    @ini_set('display_errors', (string) $prev_display_errors);

    $response = [
        'success' => true,
        'created' => $created,
    ];

    // Only include diagnostics for admins (or when WP_DEBUG is true).
    if (current_user_can('manage_options') || (defined('WP_DEBUG') && WP_DEBUG)) {
        if ($herp_buffered_output !== '') {
            $response['buffered_output'] = $herp_buffered_output;
        }
        if (!empty($herp_warnings)) {
            $response['warnings'] = $herp_warnings;
        }
    }

    return $response;
}

