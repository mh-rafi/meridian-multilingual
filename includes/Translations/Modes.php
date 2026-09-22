<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How each post type is translated. (spec 5.1)
 *
 * Exposed rather than hardcoded, because a WooCommerce site adopting
 * this plugin needs exactly the same split and a setting is the honest
 * way to expose a decision this consequential.
 */
final class Modes
{
    /** A real post row per language, linked by a translation group. */
    const SEPARATE = 'separate';

    /** One row; translated values live in meridian_fields. */
    const SINGLE = 'single';

    /** Not translated at all. */
    const NONE = 'none';

    /**
     * @return string One of the constants above.
     */
    public static function for_post_type(string $post_type): string
    {
        $settings = get_option(Registry::OPTION, array());
        $stored = isset($settings['post_type_modes']) && is_array($settings['post_type_modes']) ? $settings['post_type_modes'] : array();

        $mode = $stored[$post_type] ?? self::default_for($post_type);

        /**
         * Filter how a post type is translated.
         *
         * @param string $mode      separate|single|none
         * @param string $post_type
         */
        $mode = (string) apply_filters('meridian_post_type_mode', $mode, $post_type);

        return in_array($mode, array(self::SEPARATE, self::SINGLE, self::NONE), true) ? $mode : self::NONE;
    }

    /**
     * The default for a post type nobody configured.
     *
     * Editorial content is duplicated; a product is not. A product is
     * not content -- duplicating it creates three products where the
     * customer bought one, and orders, reviews, ratings and file
     * delivery all record an ID (spec 5.2).
     */
    private static function default_for(string $post_type): string
    {
        if ('download' === $post_type) {
            return self::SINGLE;
        }

        if (in_array($post_type, array('page', 'post'), true)) {
            return self::SEPARATE;
        }

        // Anything else is left alone until somebody says otherwise.
        // Guessing wrong in this direction changes no URL.
        return self::NONE;
    }

    /**
     * How a taxonomy is translated. (M7)
     *
     * Only SEPARATE or NONE: a term is a name and a slug, so there is
     * no single-source shape for it -- translating one means a second
     * term.
     */
    public static function for_taxonomy(string $taxonomy): string
    {
        $settings = get_option(Registry::OPTION, array());
        $stored = isset($settings['taxonomy_modes']) && is_array($settings['taxonomy_modes']) ? $settings['taxonomy_modes'] : array();

        $mode = $stored[$taxonomy] ?? self::default_taxonomy_mode($taxonomy);

        /**
         * Filter how a taxonomy is translated.
         *
         * @param string $mode     separate|none
         * @param string $taxonomy
         */
        $mode = (string) apply_filters('meridian_taxonomy_mode', $mode, $taxonomy);

        return in_array($mode, array(self::SEPARATE, self::NONE), true) ? $mode : self::NONE;
    }

    /**
     * Categories are worth translating; tags are a long tail.
     *
     * This site has 10 download categories and 152 download tags. The
     * categories are visible in URLs and breadcrumbs and are worth the
     * work; the tags are not, and defaulting them to translated would
     * present somebody with 152 empty boxes (spec 11.3).
     */
    private static function default_taxonomy_mode(string $taxonomy): string
    {
        return in_array($taxonomy, array('category', 'download_category'), true) ? self::SEPARATE : self::NONE;
    }

    public static function is_translated_taxonomy(string $taxonomy): bool
    {
        return self::SEPARATE === self::for_taxonomy($taxonomy);
    }

    public static function is_single_source(string $post_type): bool
    {
        return self::SINGLE === self::for_post_type($post_type);
    }

    /**
     * @return string[] Post types whose translated slug lives in meridian_fields.
     */
    public static function single_source_types(): array
    {
        $types = array();
        foreach (get_post_types(array('public' => true)) as $post_type) {
            if (self::is_single_source($post_type)) {
                $types[] = $post_type;
            }
        }
        return $types;
    }
}
