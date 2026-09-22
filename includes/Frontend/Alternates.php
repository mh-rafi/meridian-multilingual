<?php

namespace Meridian\Multilingual\Frontend;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Query;
use Meridian\Multilingual\Routing\Request;
use Meridian\Multilingual\Routing\Url;
use Meridian\Multilingual\Translations\Functional;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Posts;
use Meridian\Multilingual\Translations\Slugs;
use Meridian\Multilingual\Translations\Terms;
use WP_Post;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What this page exists as, in which languages. (M8, M9)
 *
 * One computation, two consumers: the hreflang set and the language
 * switcher. They must agree -- a switcher offering a language that
 * hreflang does not claim is telling a visitor and a crawler different
 * things about the same page.
 *
 * A page with no translation returns only its own language. It then
 * emits no alternates and appears in nobody else's, which is the point:
 * an untranslated object has no prefixed URL (spec 2).
 */
final class Alternates
{
    /** @var array<string,string>|null lang => url */
    private static ?array $cache = null;

    /**
     * @return array lang => absolute URL
     */
    public static function all(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $set = self::compute();

        /**
         * Filter the languages this page exists in, and their URLs.
         *
         * @param array $set lang => url
         */
        $set = (array) apply_filters('meridian_alternates', $set);

        return self::$cache = $set;
    }

    /**
     * @return array lang => url
     */
    private static function compute(): array
    {
        if (!Registry::is_multilingual() || is_404()) {
            return array();
        }

        $object = get_queried_object();

        if (is_singular() && $object instanceof WP_Post) {
            return self::for_post($object);
        }

        if ((is_tax() || is_category() || is_tag()) && $object instanceof WP_Term) {
            return self::for_term($object);
        }

        if (is_front_page()) {
            $front = (int) Query::stored_front_page();
            if ($front < 1) {
                return self::structural();
            }

            // Every language that has a front page, at its own root.
            $set = array();
            foreach (Registry::codes() as $code) {
                if (Posts::has($front, $code)) {
                    $set[$code] = Url::set_language(home_url('/'), $code);
                }
            }
            return $set;
        }

        // Archives, search, post type archives, the blog index. These
        // are queries rather than content, so they exist in every
        // language the site serves.
        return self::structural();
    }

    /**
     * @return array lang => url
     */
    private static function for_post(WP_Post $post): array
    {
        return self::for_post_id((int) $post->ID);
    }

    /**
     * @return array lang => url
     */
    private static function for_post_id(int $post_id): array
    {
        // A page nobody duplicates is the same page in every language,
        // reachable under each prefix (M6).
        if (Functional::is_functional($post_id)) {
            $set = array();
            $base = (string) get_permalink($post_id);
            foreach (Registry::codes() as $code) {
                $set[$code] = Url::set_language($base, $code);
            }
            return $set;
        }

        $set = array();
        $single = Modes::is_single_source((string) get_post_type($post_id));

        foreach (Registry::codes() as $code) {
            if (!Posts::has($post_id, $code)) {
                continue;
            }

            if ($single) {
                // One row, so the URL differs only by prefix and slug.
                //
                // The permalink has to be normalised to the source slug
                // first. get_permalink() answers in the *current*
                // language and substitutes that language's translated
                // slug, so building the English alternate while serving
                // Spanish produced the Spanish slug with the prefix
                // stripped -- a URL that belongs to neither language.
                $base = Url::set_language(self::source_permalink($post_id), $code);
                $source_slug = (string) get_post_field('post_name', $post_id);
                $translated = Slugs::for_post($post_id, $code);
                $set[$code] = ($source_slug !== '' && $source_slug !== $translated)
                    ? (string) preg_replace('#/' . preg_quote($source_slug, '#') . '(/|$|\?|\#)#', '/' . $translated . '$1', $base, 1)
                    : $base;
                continue;
            }

            $translation = Posts::id($post_id, $code);
            if ($translation < 1) {
                continue;
            }

            // A translated front page is served at its language's root,
            // not at its own slug. get_permalink() answers with the
            // slug, so /es/ would announce itself as the alternate of
            // /elementor-home-es/ -- two URLs for one page, and the one
            // in the hreflang set is the one nothing links to.
            // get_permalink() decides the prefix from the *current*
            // language, not from the language of the post it is asked
            // about -- so a link to the Spanish page built while
            // serving English came back unprefixed, and the English one
            // built while serving Spanish came back under /es/. Both
            // are normalised to the language they actually belong to.
            $set[$code] = self::is_front_page_translation($post_id, $translation)
                ? Url::set_language(home_url('/'), $code)
                : Url::set_language((string) get_permalink($translation), $code);
        }

        return $set;
    }

    /**
     * A post's permalink in the default language, with its own slug.
     */
    private static function source_permalink(int $post_id): string
    {
        $force_default = static fn() => Registry::default_code();

        add_filter('meridian_current_language', $force_default, 99);
        Request::flush();

        $permalink = (string) get_permalink($post_id);

        remove_filter('meridian_current_language', $force_default, 99);
        Request::flush();

        return $permalink;
    }

    /**
     * Whether these two posts are the site's front page and a
     * translation of it.
     */
    private static function is_front_page_translation(int $post_id, int $translation): bool
    {
        if ('page' !== get_option('show_on_front')) {
            return false;
        }

        // The stored option, not the filtered one: Query filters
        // page_on_front to the current language's front page, and this
        // has to compare against the site's own.
        $front = (int) Query::stored_front_page();

        return $front > 0 && ($post_id === $front || $translation === $front || Posts::id($front, Posts::language_of($translation)) === $translation);
    }

    /**
     * @return array lang => url
     */
    private static function for_term(WP_Term $term): array
    {
        $set = array();

        if (!Modes::is_translated_taxonomy($term->taxonomy)) {
            // One shared set of terms, readable in every language.
            $base = get_term_link($term);
            if (is_wp_error($base)) {
                return array();
            }
            foreach (Registry::codes() as $code) {
                $set[$code] = Url::set_language((string) $base, $code);
            }
            return $set;
        }

        foreach (Terms::siblings((int) $term->term_id) as $code => $term_id) {
            if (!Registry::exists($code)) {
                continue;
            }
            $link = get_term_link((int) $term_id);
            if (!is_wp_error($link)) {
                $set[$code] = Url::set_language((string) $link, $code);
            }
        }

        // A term in no group at all is the source in the default
        // language, with no translations.
        if (!$set) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                $set[Registry::default_code()] = Url::set_language((string) $link, Registry::default_code());
            }
        }

        return $set;
    }

    /**
     * The current URL under every active language.
     *
     * @return array lang => url
     */
    private static function structural(): array
    {
        $current = self::current_url();
        if ('' === $current) {
            return array();
        }

        $set = array();
        foreach (Registry::codes() as $code) {
            $set[$code] = Url::set_language($current, $code);
        }

        return $set;
    }

    private static function current_url(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ('' === $uri) {
            return '';
        }

        // Query strings are dropped: a paginated or filtered view is
        // the same document in the same language, and putting a search
        // term into an hreflang set publishes it.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return home_url($path);
    }

    /**
     * The language the current page is in, if it is in the set.
     */
    public static function current(): string
    {
        return Request::code();
    }

    /**
     * A post's URLs in every language it exists in, for a sitemap.
     *
     * Outside a request for that post, so it cannot use the query.
     *
     * @return array lang => url
     */
    public static function for_sitemap(int $post_id): array
    {
        return self::for_post_id($post_id);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
