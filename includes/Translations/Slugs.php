<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translated slugs. (M3)
 *
 * /es/software-de-punto-de-venta, not /es/stocky-pos-erp. A slug is
 * read by people and by search engines, and leaving it in the default
 * language is the one part of a translated page that still says which
 * language it was really written in.
 *
 * Two models, one interface:
 *
 *   Separate post per language (pages, posts) -- free. The translation
 *   is its own post row with its own post_name, and nothing here is
 *   involved.
 *
 *   Single source (downloads) -- one post row, so the translated slug
 *   is a 'slug' field in meridian_fields keyed by the source ID.
 */
final class Slugs
{
    const FIELD = 'slug';

    /**
     * The slug a post should be reached by in one language.
     *
     * Falls back to the source slug, so a half-translated site still
     * routes: a Spanish product page with no translated slug is served
     * at the English slug under /es/ rather than not at all.
     */
    public static function for_post(int $post_id, string $lang): string
    {
        $source = (string) get_post_field('post_name', $post_id);

        if ($post_id < 1 || Registry::is_default($lang)) {
            return $source;
        }

        $translated = Fields::get($post_id, $lang, self::FIELD);

        return ($translated && '' !== $translated) ? $translated : $source;
    }

    /**
     * The post a translated slug belongs to, or 0.
     *
     * The reverse lookup the router needs: WordPress will ask for a
     * download whose post_name is 'software-de-punto-de-venta' and find
     * nothing, because that name exists only here.
     */
    public static function resolve(string $slug, string $lang): int
    {
        if ('' === $slug || Registry::is_default($lang)) {
            return 0;
        }

        return Fields::find($lang, self::FIELD, $slug);
    }

    /**
     * Store a translated slug, sanitised the way WordPress would.
     *
     * @return true|\WP_Error
     */
    public static function set(int $post_id, string $lang, string $slug, array $args = array())
    {
        $slug = sanitize_title($slug);

        if ('' === $slug) {
            return Fields::delete($post_id, $lang, self::FIELD)
                ? true
                : new \WP_Error('meridian_slug_empty', __('A translated slug cannot be empty. Clear it to fall back to the source slug.', 'meridian'));
        }

        $taken = self::resolve($slug, $lang);
        if ($taken > 0 && $taken !== $post_id) {
            return new \WP_Error('meridian_slug_taken', sprintf(
                /* translators: %s: the slug that is already in use. */
                __('The slug "%s" is already used by another translation in this language.', 'meridian'),
                $slug
            ));
        }

        $previous = self::for_post($post_id, $lang);

        $result = Fields::set($post_id, $lang, self::FIELD, $slug, $args + array(
            'source' => (string) get_post_field('post_name', $post_id),
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        if ($previous !== $slug) {
            /**
             * Fires when a translated slug changes.
             *
             * The old URL has to keep working, and this site already has
             * a redirect table -- Sightline's. One redirect table per
             * site (M3), so this announces the change and the Sightline
             * integration writes the rule.
             *
             * @param int    $post_id
             * @param string $lang
             * @param string $previous Old slug.
             * @param string $slug     New slug.
             */
            do_action('meridian_slug_changed', $post_id, $lang, $previous, $slug);
        }

        return true;
    }
}
