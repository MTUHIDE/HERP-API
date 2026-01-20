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

    return $records ?: [];
}
