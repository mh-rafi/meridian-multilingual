<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creating a translation of a post. (M4)
 *
 * The important part is not the post row. It is everything hanging off
 * it: a duplicated page inherits nothing (spec 5.6). On a single-source
 * download an untranslated field falls back to the source, because
 * there is a source to fall back to; on a separate post row there is no
 * such relationship in post meta, so an empty Spanish front page renders
 * with no hero image, no chosen products and no section order.
 *
 * So creating a translation copies the source's meta, and the
 * translator then overwrites the prose. Which keys count as prose is
 * something this plugin cannot know on its own -- it is a property of
 * whatever registered the fields -- so it asks through a filter, and
 * the edd-widgets integration answers from ul_field_is_translatable().
 */
final class Duplicator
{
    /**
     * Create a translation of $source_id in $lang, as a draft.
     *
     * @return int|WP_Error New post ID.
     */
    public static function create(int $source_id, string $lang)
    {
        $source = get_post($source_id);

        if (!$source) {
            return new WP_Error('meridian_no_source', __('That post does not exist.', 'meridian'));
        }
        if (!Registry::exists($lang)) {
            return new WP_Error('meridian_unknown_language', __('That language is not configured.', 'meridian'));
        }
        if (Modes::SEPARATE !== Modes::for_post_type($source->post_type)) {
            return new WP_Error('meridian_not_duplicated', __('This post type is not translated by duplication.', 'meridian'));
        }
        if (Groups::translation($source_id, $lang) > 0) {
            return new WP_Error('meridian_already_translated', __('A translation in that language already exists.', 'meridian'));
        }
        if (Functional::is_functional($source_id)) {
            // Refused in the service, not only hidden in the UI. The
            // admin screen is one way in; a duplicated checkout page is
            // a page with a checkout shortcode on it and none of EDD's
            // behaviour, however it was created (spec 5.3).
            return new WP_Error('meridian_functional_page', __('This page is not translated by duplicating it - what a visitor reads is its shortcodes output, which is translated as text rather than as a page.', 'meridian'));
        }

        $new_id = wp_insert_post(array(
            'post_type'      => $source->post_type,
            // A draft, always. A translation that publishes itself the
            // moment it is created is an empty page at a live URL.
            'post_status'    => 'draft',
            'post_title'     => $source->post_title,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_author'    => get_current_user_id() ?: $source->post_author,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            // The parent's own translation where there is one, so a
            // translated child sits under a translated parent rather
            // than under the English one.
            'post_parent'    => $source->post_parent ? (Groups::translation((int) $source->post_parent, $lang) ?: (int) $source->post_parent) : 0,
            'post_name'      => self::available_slug($source, $lang),
        ), true);

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $group = Groups::group_of($source_id);
        if ($group < 1) {
            // The source has never been translated, so it is not in a
            // group yet. Put it in one, in its own language, first --
            // otherwise the new post is in a group of one and the two
            // are not related to each other.
            $group = Groups::link($source_id, Posts::language_of($source_id));
            if (is_wp_error($group)) {
                wp_delete_post($new_id, true);
                return $group;
            }
        }

        $linked = Groups::link((int) $new_id, $lang, (int) $group);
        if (is_wp_error($linked)) {
            wp_delete_post($new_id, true);
            return $linked;
        }

        self::copy_meta($source_id, (int) $new_id);
        self::copy_terms($source_id, (int) $new_id, $source->post_type);

        /**
         * Fires after a translation post is created.
         *
         * @param int    $new_id
         * @param int    $source_id
         * @param string $lang
         */
        do_action('meridian_translation_created', (int) $new_id, $source_id, $lang);

        return (int) $new_id;
    }

    /**
     * Rewrite the shared (non-prose) meta from the source.
     *
     * The copy made at creation is a snapshot, and the English side
     * keeps changing. Swapping a hero image on the source is otherwise
     * invisible in every translation of it.
     *
     * @return int Number of keys rewritten.
     */
    public static function resync_shared(int $translation_id): int
    {
        $lang = Posts::language_of($translation_id);
        $siblings = Groups::siblings($translation_id);

        $source_id = 0;
        foreach ($siblings as $sibling_lang => $sibling_id) {
            if (Registry::is_default($sibling_lang)) {
                $source_id = $sibling_id;
                break;
            }
        }

        if ($source_id < 1 || $source_id === $translation_id) {
            return 0;
        }

        return self::copy_meta($source_id, $translation_id, true);
    }

    /**
     * @param bool $shared_only Skip keys that hold prose.
     * @return int Number of keys written.
     */
    private static function copy_meta(int $source_id, int $target_id, bool $shared_only = false): int
    {
        $written = 0;

        foreach ((array) get_post_meta($source_id) as $key => $values) {
            if (self::is_internal_key((string) $key)) {
                continue;
            }
            if ($shared_only && self::is_translatable_key((string) $key, $source_id)) {
                continue;
            }

            delete_post_meta($target_id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($target_id, $key, maybe_unserialize($value));
            }
            $written++;
        }

        return $written;
    }

    /**
     * Meta WordPress or an editor owns, which must not be copied.
     *
     * _edit_lock and _edit_last would make the new post look like
     * somebody else is editing it; _wp_old_slug is another post's
     * redirect history; the Elementor keys would hand a duplicated page
     * a builder payload this project spent a migration removing.
     */
    private static function is_internal_key(string $key): bool
    {
        $exact = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time');

        if (in_array($key, $exact, true)) {
            return true;
        }

        /**
         * Filter meta keys that are never copied to a translation.
         *
         * @param bool   $internal
         * @param string $key
         */
        return (bool) apply_filters('meridian_meta_key_is_internal', 0 === strpos($key, '_elementor'), $key);
    }

    /**
     * Whether a meta key holds language-dependent copy.
     *
     * False by default: this plugin has no idea what a given key means,
     * and treating an unknown key as prose would leave a resync
     * silently skipping it forever. The edd-widgets integration answers
     * properly from ul_field_is_translatable().
     */
    private static function is_translatable_key(string $key, int $post_id): bool
    {
        /**
         * Filter whether a meta key holds translatable copy.
         *
         * @param bool   $translatable
         * @param string $key
         * @param int    $post_id
         */
        return (bool) apply_filters('meridian_meta_key_is_translatable', false, $key, $post_id);
    }

    private static function copy_terms(int $source_id, int $target_id, string $post_type): void
    {
        // The same terms, not copies of them. A translated page belongs
        // in the same categories as its source until M7 gives the
        // taxonomy its own per-language terms; re-assigning would be
        // work for an editor and would split the archives in two.
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = wp_get_object_terms($source_id, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms) && $terms) {
                wp_set_object_terms($target_id, $terms, $taxonomy);
            }
        }
    }

    /**
     * A slug that is free, derived from the source's.
     *
     * The language code is a suffix rather than a prefix so that the
     * editor's slug field still sorts and reads like the original. It
     * is only a starting point: M3 is where a real translated slug is
     * typed.
     */
    private static function available_slug(\WP_Post $source, string $lang): string
    {
        $base = $source->post_name ? $source->post_name . '-' . $lang : sanitize_title($source->post_title . '-' . $lang);
        $slug = $base;
        $i = 2;

        while (get_page_by_path($slug, OBJECT, $source->post_type)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
