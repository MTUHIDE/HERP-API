<?php
/**
 * Returns a user's records in the same "shape" the website uses:
 * - Records are WP posts of type `record` authored by the user.
 * - Record details live in the custom `record` table keyed by `r_post_id`.
 * - Taxon is a WP post (post_type `species`) referenced by `record.r_taxon`.
 * - Group is a WP post (post_type `group`) referenced by `record.r_group`.
 * - County is a WP term (taxonomy `county`) stored as `record.r_county` (term_id).
 * - Genus is a WP term (taxonomy `genus`) attached to the species (or its parent).
 */
function herp_get_user_records(WP_REST_Request $request) {
    global $wpdb;

    // Support both:
    // - /records?user_id=123
    // - /records/123 (route param)
    $user_id = intval($request->get_param('user_id'));
    if (!$user_id) {
        return new WP_Error('missing_user_id', 'Missing user_id parameter', ['status' => 400]);
    }

    // Mirror website data model: records are WP posts (type `record`) authored by user,
    // joined to the custom `record` table via r_post_id.
    $posts_table = $wpdb->posts;
    $terms_table = $wpdb->terms;
    $term_taxonomy_table = $wpdb->term_taxonomy;
    $term_relationships_table = $wpdb->term_relationships;

    $sql = $wpdb->prepare(
        "
        SELECT
            r.*,
            p.ID AS post_id,
            p.post_author AS user_id,
            p.post_status AS post_status,
            p.post_date AS post_date,
            p.post_modified AS post_modified,

            taxon.ID AS taxon_id,
            taxon.post_title AS taxon_title,
            taxon.post_parent AS species_id,
            parent_taxon.post_title AS species_title,

            grp.ID AS group_id,
            grp.post_title AS group_title,

            county.term_id AS county_id,
            county.name AS county_title,

            GROUP_CONCAT(DISTINCT genus.term_id) AS genus_ids,
            GROUP_CONCAT(DISTINCT genus.name) AS genus_names
        FROM {$posts_table} p
        INNER JOIN record r
            ON r.r_post_id = p.ID
        LEFT JOIN {$posts_table} taxon
            ON taxon.ID = r.r_taxon
        LEFT JOIN {$posts_table} parent_taxon
            ON parent_taxon.ID = taxon.post_parent
        LEFT JOIN {$posts_table} grp
            ON grp.ID = r.r_group
        LEFT JOIN {$terms_table} county
            ON county.term_id = r.r_county
        LEFT JOIN {$term_relationships_table} tr
            ON (tr.object_id = taxon.ID OR tr.object_id = parent_taxon.ID)
        LEFT JOIN {$term_taxonomy_table} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
            AND tt.taxonomy = 'genus'
        LEFT JOIN {$terms_table} genus
            ON genus.term_id = tt.term_id
        WHERE
            p.post_type = 'record'
            AND p.post_author = %d
            AND p.post_status <> 'trash'
        GROUP BY r.r_id
        ORDER BY r.r_id DESC
        ",
        $user_id
    );

    $records = $wpdb->get_results($sql);

    $records = $records ?: [];

    // Attach vouchers (ACF field on the WP `record` post).
    foreach ($records as $record) {
        $post_id = intval($record->post_id ?? 0);
        $record_id = intval($record->r_id ?? 0);
        $record->vouchers = herp_get_record_vouchers($post_id, $record_id);
    }

    return $records;
}

/**
 * Normalize the ACF `vouchers` field into a frontend-friendly payload.
 *
 * @return array<int, array<string, mixed>>
 */
function herp_get_record_vouchers(int $post_id, int $record_id = 0): array {
    if ($post_id <= 0) {
        return [];
    }

    $attachment_ids = [];

    if (!function_exists('get_field')) {
        // Fallback to legacy voucher table if ACF isn't available.
        return herp_get_record_vouchers_from_legacy_table($record_id);
    }

    $raw = get_field('vouchers', $post_id);
    if (!empty($raw) && is_array($raw)) {
        foreach ($raw as $voucher) {
            $attachment_id = 0;

            if (is_numeric($voucher)) {
                $attachment_id = intval($voucher);
            } elseif (is_array($voucher)) {
                $attachment_id = intval($voucher['ID'] ?? $voucher['id'] ?? $voucher['attachment_id'] ?? 0);
            } elseif (is_object($voucher)) {
                // ACF can return WP_Post objects depending on field return format.
                $attachment_id = intval($voucher->ID ?? 0);
            }

            if ($attachment_id > 0) {
                $attachment_ids[] = $attachment_id;
            }
        }
    }

    // If ACF field is empty, try legacy voucher table lookup.
    if (empty($attachment_ids) && $record_id > 0) {
        $attachment_ids = herp_get_record_attachment_ids_from_legacy_table($record_id);
    }

    $attachment_ids = array_values(array_unique(array_filter(array_map('intval', $attachment_ids))));
    if (empty($attachment_ids)) {
        return [];
    }

    $out = [];

    foreach ($attachment_ids as $attachment_id) {
        $url = wp_get_attachment_url($attachment_id) ?: '';
        $mime = get_post_mime_type($attachment_id) ?: '';
        $type = '';
        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            $type = 'image';
        } elseif (is_string($mime) && str_starts_with($mime, 'audio/')) {
            $type = 'audio';
        } else {
            $type = 'file';
        }

        $attachment = get_post($attachment_id);
        $title = $attachment ? (string) $attachment->post_title : '';
        $caption = $attachment ? (string) $attachment->post_excerpt : '';
        $description = $attachment ? (string) $attachment->post_content : '';

        $meta = wp_get_attachment_metadata($attachment_id);

        $item = [
            'id' => $attachment_id,
            'type' => $type,              // image | audio | file
            'mime_type' => $mime,
            'url' => $url,                // full URL
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
        ];

        if ($type === 'image') {
            $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $item['alt'] = is_string($alt) ? $alt : '';

            $full = wp_get_attachment_image_src($attachment_id, 'full');
            $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            $medium = wp_get_attachment_image_src($attachment_id, 'medium');
            $large = wp_get_attachment_image_src($attachment_id, 'large');

            $item['sizes'] = [
                'thumbnail' => $thumb ? $thumb[0] : null,
                'medium' => $medium ? $medium[0] : null,
                'large' => $large ? $large[0] : null,
                'full' => $full ? $full[0] : $url,
            ];

            if (is_array($full)) {
                $item['width'] = $full[1] ?? null;
                $item['height'] = $full[2] ?? null;
            }
        }

        if ($type === 'audio' && is_array($meta)) {
            // WP sometimes stores audio duration as length_formatted.
            if (!empty($meta['length_formatted'])) {
                $item['length_formatted'] = $meta['length_formatted'];
            }
        }

        $out[] = $item;
    }

    return $out;
}

/**
 * Find attachment IDs by consulting the legacy voucher table and using the naming convention:
 * /uploads/vouchers/{record_id}-{v_id}.ext
 *
 * @return int[]
 */
function herp_get_record_attachment_ids_from_legacy_table(int $record_id): array {
    global $wpdb;

    if ($record_id <= 0) {
        return [];
    }

    $table = null;
    foreach (['voucher', $wpdb->prefix . 'voucher'] as $candidate) {
        $pattern = $wpdb->esc_like($candidate);
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pattern));
        if ($exists) {
            $table = $candidate;
            break;
        }
    }

    if (!$table) {
        return [];
    }

    $vids = $wpdb->get_col(
        $wpdb->prepare("SELECT v_id FROM {$table} WHERE v_record = %d ORDER BY v_id ASC", $record_id)
    );

    if (empty($vids) || !is_array($vids)) {
        return [];
    }

    $ids = [];
    foreach ($vids as $vid) {
        $vid = intval($vid);
        if ($vid <= 0) {
            continue;
        }

        $like = '%/vouchers/' . $wpdb->esc_like($record_id . '-' . $vid) . '.%';
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND guid LIKE %s
                 ORDER BY ID DESC
                 LIMIT 1",
                $like
            )
        );
        if ($found) {
            $ids[] = intval($found);
        }
    }

    return $ids;
}

/**
 * Backwards-compatible wrapper: if you only have the legacy voucher table, return normalized vouchers.
 *
 * @return array<int, array<string, mixed>>
 */
function herp_get_record_vouchers_from_legacy_table(int $record_id): array {
    $ids = herp_get_record_attachment_ids_from_legacy_table($record_id);
    if (empty($ids)) {
        return [];
    }

    // Reuse the main normalizer by pretending we have a post_id (we don't).
    $out = [];
    foreach ($ids as $attachment_id) {
        $url = wp_get_attachment_url($attachment_id) ?: '';
        $mime = get_post_mime_type($attachment_id) ?: '';
        $type = (is_string($mime) && str_starts_with($mime, 'image/')) ? 'image' : ((is_string($mime) && str_starts_with($mime, 'audio/')) ? 'audio' : 'file');

        $out[] = [
            'id' => (int) $attachment_id,
            'type' => $type,
            'mime_type' => $mime,
            'url' => $url,
            'title' => get_the_title($attachment_id) ?: '',
        ];
    }

    return $out;
}
