<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which post is which language, and what its translations are.
 *
 * The seam between routing (M2) and storage (M4 for editorial posts,
 * M5 for downloads). Routing has to ask "is there a Spanish version of
 * this?" on every link it writes, and the honest answer before the
 * translation tables exist is "no" -- which is exactly the answer that
 * makes M2 correct on its own: an untranslated object gets no prefixed
 * URL, so with nothing translated the site keeps every URL it has today.
 *
 * Both questions go through a filter, so M4 and M5 implement them by
 * hooking rather than by editing routing, and a site can answer them
 * itself.
 */
final class Posts
{
    /** Per-request memo; translation lookups repeat hard on an archive. */
    private static array $cache = array();

    /**
     * The ID of $post_id's translation in $lang, or 0.
     *
     * A post asked for its own language answers itself, which keeps
     * callers from special-casing the default language everywhere.
     */
    public static function id(int $post_id, string $lang): int
    {
        if ($post_id < 1 || '' === $lang) {
            return 0;
        }

        $key = $post_id . ':' . $lang;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (self::language_of($post_id) === $lang) {
            return self::$cache[$key] = $post_id;
        }

        // A single-source post is its own translation: there is one row
        // and the translated values hang off it (spec 5.2). It counts
        // as translated once anything has actually been written for
        // that language -- a product with no Spanish values at all is
        // not a Spanish product, and giving it a /es/ URL would put the
        // English page under a Spanish address.
        if (Modes::is_single_source((string) get_post_type($post_id))) {
            $has_values = (bool) Fields::all($post_id, $lang);
            return self::$cache[$key] = $has_values ? $post_id : 0;
        }

        // Separate-post model: the translation is a real post row,
        // found through the group it shares with this one (M4).
        $translation = Modes::SEPARATE === Modes::for_post_type((string) get_post_type($post_id))
            ? Groups::translation($post_id, $lang)
            : 0;

        /**
         * Filter the ID of a post's translation in one language.
         *
         * @param int    $translation 0 when there is none.
         * @param int    $post_id     The post being translated.
         * @param string $lang        Target language code.
         */
        $translation = (int) apply_filters('meridian_translated_post_id', $translation, $post_id, $lang);

        // A translation that is not published is not a translation: a
        // link to a draft is a 404 for everyone but its author.
        if ($translation > 0 && 'publish' !== get_post_status($translation)) {
            $translation = 0;
        }

        return self::$cache[$key] = $translation;
    }

    public static function has(int $post_id, string $lang): bool
    {
        return self::id($post_id, $lang) > 0;
    }

    /**
     * The language a post is written in.
     *
     * Everything is the default language until something says otherwise,
     * which is what makes installing this plugin a no-op on a site that
     * has not translated anything.
     */
    public static function language_of(int $post_id): string
    {
        $default = Registry::default_code();
        if ($post_id < 1) {
            return $default;
        }

        $stored = Groups::language_of($post_id);

        /**
         * Filter which language a post is written in.
         *
         * @param string $lang    Language code.
         * @param int    $post_id
         */
        $lang = (string) apply_filters('meridian_post_language', $stored, $post_id);

        return Registry::exists($lang) ? $lang : $default;
    }

    /**
     * Every language this post can actually be read in, default first.
     *
     * The set hreflang (M8) and the switcher (M9) are built from, so it
     * lists only languages with a published translation -- never every
     * configured language.
     *
     * @return string[]
     */
    public static function languages_for(int $post_id): array
    {
        $available = array();
        foreach (Registry::codes() as $code) {
            if (self::has($post_id, $code)) {
                $available[] = $code;
            }
        }
        return $available;
    }

    public static function flush(): void
    {
        self::$cache = array();
    }
}
