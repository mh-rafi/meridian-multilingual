<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use WP_Error;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translating taxonomy terms. (M7)
 *
 * A term is a name and a slug, so a translated term is a second term --
 * there is no single-source shape for it. They are linked by the same
 * translation groups posts use, with object_type 'term'.
 *
 * The part that matters is what they do NOT copy. A Spanish product
 * category lists the same products as the English one: the objects stay
 * assigned to the source term, and the query is widened to the group
 * rather than the products being re-assigned. Re-assigning 34 products
 * to a second term is work for an editor, and it splits every count and
 * archive in two the first time somebody forgets.
 */
final class Terms
{
    const TYPE = 'term';

    public static function language_of(int $term_id): string
    {
        return Groups::language_of($term_id, self::TYPE);
    }

    public static function translation(int $term_id, string $lang): int
    {
        return Groups::translation($term_id, $lang, self::TYPE);
    }

    /**
     * @return array lang => term_id
     */
    public static function siblings(int $term_id): array
    {
        return Groups::siblings($term_id, self::TYPE);
    }

    public static function has(int $term_id, string $lang): bool
    {
        if (Registry::is_default($lang)) {
            return self::language_of($term_id) === $lang || Groups::group_of($term_id, self::TYPE) < 1;
        }

        return self::translation($term_id, $lang) > 0;
    }

    /**
     * Every term in $term_id's group, including itself.
     *
     * The set a taxonomy query is widened to, so that a translated
     * archive lists its source's objects.
     *
     * @return int[]
     */
    public static function group_ids(int $term_id): array
    {
        $siblings = self::siblings($term_id);
        $ids = array_map('intval', array_values($siblings));

        if (!in_array($term_id, $ids, true)) {
            $ids[] = $term_id;
        }

        return $ids;
    }

    /**
     * The terms of a taxonomy not to offer an item written in $lang.
     *
     * Every term in another language, and every default-language term
     * with a translation in $lang, whose translation is offered in its
     * place. A default-language term with no translation in $lang stays,
     * because it is still the only term of its kind.
     *
     * @return int[]
     */
    public static function hidden_for(string $taxonomy, string $lang): array
    {
        global $wpdb;

        if (!Schema::translations_installed()) {
            return array();
        }

        $table = Schema::translations_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.object_id, m.lang
             FROM {$table} m
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.object_id AND tt.taxonomy = %s
             WHERE m.object_type = %s AND m.lang <> %s",
            $taxonomy,
            self::TYPE,
            $lang
        ), ARRAY_A);

        $hidden = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['object_id'];
            if (Registry::is_default((string) $row['lang']) && self::translation($id, $lang) < 1) {
                continue;
            }
            $hidden[] = $id;
        }

        return $hidden;
    }

    /**
     * Create a translation of a term.
     *
     * @return int|WP_Error New term ID.
     */
    public static function create(int $source_id, string $lang, string $name = '', string $slug = '')
    {
        $source = get_term($source_id);

        if (!$source instanceof WP_Term) {
            return new WP_Error('meridian_no_term', __('That term does not exist.', 'meridian'));
        }
        if (!Registry::exists($lang) || Registry::is_default($lang)) {
            return new WP_Error('meridian_bad_language', __('Pick a language other than the default one.', 'meridian'));
        }
        if (!Modes::is_translated_taxonomy($source->taxonomy)) {
            return new WP_Error('meridian_taxonomy_not_translated', __('This taxonomy is not set to be translated. Turn it on in Meridian’s settings first.', 'meridian'));
        }
        if (self::translation($source_id, $lang) > 0) {
            return new WP_Error('meridian_already_translated', __('A translation in that language already exists.', 'meridian'));
        }

        $name = '' !== trim($name) ? $name : $source->name;
        $slug = '' !== trim($slug) ? sanitize_title($slug) : self::available_slug($source, $lang);

        $created = wp_insert_term($name, $source->taxonomy, array(
            'slug'        => $slug,
            'description' => $source->description,
            // The parent's own translation where there is one, so a
            // translated child sits under a translated parent.
            'parent'      => $source->parent ? (self::translation((int) $source->parent, $lang) ?: 0) : 0,
        ));

        if (is_wp_error($created)) {
            return $created;
        }

        $new_id = (int) $created['term_id'];

        $group = Groups::group_of($source_id, self::TYPE);
        if ($group < 1) {
            $group = Groups::link($source_id, Registry::default_code(), 0, self::TYPE);
            if (is_wp_error($group)) {
                wp_delete_term($new_id, $source->taxonomy);
                return $group;
            }
        }

        $linked = Groups::link($new_id, $lang, (int) $group, self::TYPE);
        if (is_wp_error($linked)) {
            wp_delete_term($new_id, $source->taxonomy);
            return $linked;
        }

        // Deliberately no object re-assignment. See the class comment:
        // the query is widened to the group instead.

        self::copy_meta($source_id, $new_id);

        do_action('meridian_term_translation_created', $new_id, $source_id, $lang);

        return $new_id;
    }

    /**
     * Copy the source's term meta onto a newly created translation.
     *
     * The same job Duplicator::copy_meta() does for posts (spec 5.6): a
     * translated object inherits nothing of its own, so without this a
     * new category started with a blank SEO title and description
     * (Sightline's `_sightline_title`/`_sightline_description` term
     * meta) and no `page_title`/`position` (ul_field_schema()'s own
     * download_category fields) -- found 23 September 2026, because
     * this half of M7 was never written, only its post equivalent.
     */
    private static function copy_meta(int $source_id, int $target_id): void
    {
        foreach ((array) get_term_meta($source_id) as $key => $values) {
            /** @see \Meridian\Multilingual\Translations\Duplicator::is_internal_key() -- same filter, same reasoning. */
            if (apply_filters('meridian_meta_key_is_internal', 0 === strpos((string) $key, '_elementor'), $key)) {
                continue;
            }

            delete_term_meta($target_id, $key);
            foreach ((array) $values as $value) {
                add_term_meta($target_id, $key, maybe_unserialize($value));
            }
        }
    }

    /**
     * The stored slug for a new translation: `{source slug}-{lang}`, plus
     * `-N` if that is taken. UrlSlugs publishes exactly this shape under
     * the source's slug, so changing it means changing that rule too.
     */
    public static function available_slug(WP_Term $source, string $lang): string
    {
        $base = $source->slug . '-' . $lang;
        $slug = $base;
        $i = 2;

        while (get_term_by('slug', $slug, $source->taxonomy)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
