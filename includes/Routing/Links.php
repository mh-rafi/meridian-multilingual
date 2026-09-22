<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Languages\Registry;
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
 * The prefix going out. (M2)
 *
 * The half that fails quietly. Routing /es/ in is one rewrite rule and
 * it is obviously working or obviously not; every link on the page
 * pointing back at English looks perfect until somebody follows one.
 *
 * Two kinds of URL, and they are decided differently:
 *
 *   Structural -- home, archives, search, pagination. These exist in
 *   every language because they are queries, not content, so they take
 *   the current language's prefix. Handled once by filtering home_url(),
 *   which every one of them is built from.
 *
 *   Objects -- a post, a page, a term. These exist in a language only
 *   if somebody translated them. They arrive here already prefixed,
 *   because they were built from home_url(), and the job is to take the
 *   prefix back off when there is no translation to point at. A missing
 *   translation is a missing page, not an English one (spec 2), so the
 *   link goes to the language the content is actually in.
 */
final class Links
{
    /**
     * True while WordPress is working out what was requested.
     *
     * WP::parse_request() strips home_url()'s path off the request
     * before matching it against the rewrite rules. With home_url()
     * filtered to carry the language, it stripped '/es' from
     * '/es/downloads/x' and matched the *unprefixed* rule -- so the
     * language-prefixed rules this plugin registers were never used and
     * 'lang' never became a query var. Routing still appeared to work,
     * because the language was being detected from the path separately,
     * which is exactly the kind of accident that holds until the first
     * thing depends on the query var.
     */
    private static bool $parsing = false;

    /** Guards the re-entry when a term link resolves to another term. */
    private static bool $resolving_term = false;

    public static function register(): void
    {
        add_filter('home_url', array(self::class, 'home'), 10, 2);

        // Bracket parse_request. do_parse_request fires at its start
        // and the parse_request action at its end; 'wp' is a backstop
        // for the case where a plugin short-circuits the first and the
        // second never runs.
        add_filter('do_parse_request', array(self::class, 'start_parsing'), 1);
        add_action('parse_request', array(self::class, 'stop_parsing'), 9999);
        add_action('wp', array(self::class, 'stop_parsing'), 1);

        // Late on parse_request, not early. Other routing code runs on
        // this hook -- Sightline's redirect router is on it at priority
        // 1 -- and it compares the request against stored paths by
        // stripping home_url()'s path off it. Those consumers must see
        // the same unprefixed home WordPress itself just used to match
        // the rewrite rules, or a stored path either matches or does
        // not depending on which plugin registered its hook first.
        // parse_request is a routing hook, so nothing on it is building
        // URLs for output; 'wp' is the backstop.

        // Objects. Priority 20 so a theme or SEO plugin that builds the
        // canonical permalink at the default priority has already run.
        add_filter('post_link', array(self::class, 'post'), 20, 2);
        add_filter('page_link', array(self::class, 'page'), 20, 2);
        add_filter('post_type_link', array(self::class, 'post'), 20, 2);
        add_filter('term_link', array(self::class, 'term'), 20, 2);

        // Structural, already correct from home_url(); listed because
        // getting them wrong is the failure this requirement is about,
        // and a reader looking for them should find them named.
        // post_type_archive_link, get_pagenum_link and paginate_links
        // all build on home_url() and need no filter of their own.

        add_filter('redirect_canonical', array(self::class, 'canonical'), 10, 2);
        // wp_get_nav_menu_items() rather than nav_menu_link_attributes:
        // the attributes filter only fires for menus rendered by
        // wp_nav_menu(), and a theme is free to call
        // wp_get_nav_menu_items() and echo $item->url itself -- this
        // site's footer does exactly that, so half its links went
        // uncorrected while the header's were fine. Correcting the item
        // at its source covers both, because wp_nav_menu() reads the
        // same function.
        add_filter('wp_get_nav_menu_items', array(self::class, 'menu_items'), 10, 3);

        // The REST API is built by appending its prefix to home_url('/'),
        // so the exclusion in Url cannot see it -- by the time
        // 'wp-json/' is added, home_url() has already returned a
        // prefixed root. Corrected here instead, where the whole URL is
        // known. /es/wp-json/ is not the API in Spanish, it is a 404.
        add_filter('rest_url', array(self::class, 'rest'), 10);
    }

    /**
     * @param bool $do
     */
    public static function start_parsing($do)
    {
        self::$parsing = true;
        return $do;
    }

    public static function stop_parsing(): void
    {
        self::$parsing = false;
    }

    /**
     * Every structural URL on the site.
     *
     * @param string $url
     * @param string $path
     */
    public static function home($url, $path = ''): string
    {
        if (is_admin() || self::$parsing || !Registry::is_multilingual()) {
            return (string) $url;
        }

        return Url::set_language((string) $url, Request::code());
    }

    /**
     * @param string       $url
     * @param WP_Post|int  $post
     */
    public static function post($url, $post): string
    {
        $id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
        return self::for_object((string) $url, $id);
    }

    /**
     * page_link passes an ID rather than an object.
     *
     * @param string $url
     * @param int    $post_id
     */
    public static function page($url, $post_id = 0): string
    {
        return self::for_object((string) $url, (int) $post_id);
    }

    /**
     * Point a link at the language its target can actually be read in.
     */
    private static function for_object(string $url, int $post_id): string
    {
        if (is_admin() || !Registry::is_multilingual() || $post_id < 1) {
            return $url;
        }

        $current = Request::code();

        // A post with nothing to translate is readable in every
        // language as soon as that language exists. EDD's checkout,
        // receipt and account pages are the case that matters: they are
        // never duplicated (spec 5.3), what a visitor reads is their
        // shortcodes' output, and dropping the prefix on the checkout
        // link would end a Spanish visitor's session in English
        // mid-purchase.
        if (self::is_available_everywhere($post_id)) {
            return Url::set_language($url, $current);
        }

        // A post translated as separate rows is one language's own row,
        // so its URL is that language's -- whatever language the page
        // holding the link happens to be written in. Deciding it from
        // the current language instead was wrong in both directions:
        // Posts::has() is true for a Spanish page whenever an English
        // counterpart exists, so the Spanish page's permalink came back
        // with the prefix stripped, giving it a second, English-looking
        // URL that served Spanish content -- and that was the URL every
        // link and the sitemap used. Where the slug happened to be a
        // language code, the stripping went further still and /bn/
        // became the site root.
        if (Modes::SEPARATE === Modes::for_post_type((string) get_post_type($post_id))) {
            return self::for_separate_post($url, $post_id);
        }

        if (Posts::has($post_id, $current)) {
            return self::with_translated_slug(Url::set_language($url, $current), $post_id, $current);
        }

        // No translation: link to the language it is written in, which
        // for an untranslated post is the default and therefore has no
        // prefix at all.
        return Url::set_language($url, Posts::language_of($post_id));
    }

    /**
     * The URL of one language's own post row.
     *
     * Always that post's own language, never the request's.
     */
    private static function for_separate_post(string $url, int $post_id): string
    {
        $lang = Posts::language_of($post_id);

        // A translated front page is served at its language's root, not
        // at its slug: /es/ and /es/elementor-home-es/ are one page and
        // the second only redirects to the first. Answering with the
        // slug put the redirecting URL into every link and into the
        // sitemap. Alternates::for_post_id() already decides a front
        // page translation the same way for hreflang (M8); this is the
        // same rule on the permalink side.
        if (self::is_front_page_translation($post_id)) {
            return Url::set_language(home_url('/'), $lang);
        }

        return Url::set_language($url, $lang);
    }

    /**
     * Whether a post is the site's front page in some language.
     */
    private static function is_front_page_translation(int $post_id): bool
    {
        if ('page' !== get_option('show_on_front')) {
            return false;
        }

        // The stored option, not the filtered one: Query filters
        // page_on_front to the current language's front page, and the
        // question here is about the site's own.
        $front = (int) Query::stored_front_page();

        return $front > 0 && Posts::id($front, Posts::language_of($post_id)) === $post_id;
    }

    /**
     * Swap a single-source post's slug for its translated one. (M3)
     *
     * A post translated per language carries its own post_name, so the
     * permalink is already right and this does nothing. A single-source
     * post has one post_name in the default language, and the
     * translated slug lives in meridian_fields.
     */
    private static function with_translated_slug(string $url, int $post_id, string $lang): string
    {
        if (!Modes::is_single_source((string) get_post_type($post_id))) {
            return $url;
        }

        $source = (string) get_post_field('post_name', $post_id);
        $translated = Slugs::for_post($post_id, $lang);

        if ('' === $source || $source === $translated) {
            return $url;
        }

        // Only the slug segment, matched with its delimiters, so a slug
        // that also appears inside the archive base or a query string
        // is not rewritten with it.
        return (string) preg_replace(
            '#/' . preg_quote($source, '#') . '(/|$|\?|\#)#',
            '/' . $translated . '$1',
            $url,
            1
        );
    }

    /**
     * @param string       $url
     * @param WP_Term|null $term
     */
    public static function term($url, $term = null): string
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return (string) $url;
        }

        $term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
        $taxonomy = $term instanceof WP_Term ? (string) $term->taxonomy : '';
        $current = Request::code();

        // A taxonomy nobody translates has one term per name, shared by
        // every language, and a shared object is readable in all of
        // them -- the same rule that puts the checkout page under every
        // prefix. This is the answer for download_tag's 152 terms.
        $translated_taxonomy = '' !== $taxonomy && Modes::is_translated_taxonomy($taxonomy);

        if (!$translated_taxonomy) {
            $available = true;
        } else {
            $translation = ($term_id > 0 && !Registry::is_default($current)) ? Terms::translation($term_id, $current) : 0;

            if ($translation > 0 && !self::$resolving_term) {
                // Point at the translated term itself, not at this
                // one's URL under a prefix: it has its own slug, and
                // that slug is the whole point of translating it.
                self::$resolving_term = true;
                $translated_url = get_term_link($translation);
                self::$resolving_term = false;

                if (!is_wp_error($translated_url)) {
                    return Url::set_language((string) $translated_url, $current);
                }
            }

            $available = $translation > 0;
        }

        /**
         * Filter whether a term is readable in the current language.
         *
         * @param bool   $available
         * @param int    $term_id
         * @param string $lang
         */
        $available = (bool) apply_filters('meridian_term_available_in_language', $available, $term_id, $current);

        return Url::set_language((string) $url, $available ? $current : Registry::default_code());
    }

    /**
     * Whether a post serves every language from the one row.
     *
     * @param int $post_id
     */
    private static function is_available_everywhere(int $post_id): bool
    {
        /**
         * Filter posts that are never duplicated but are readable in
         * every language.
         *
         * M6 fills this with EDD's functional pages.
         *
         * @param bool $shared
         * @param int  $post_id
         */
        return (bool) apply_filters('meridian_post_available_in_every_language', false, $post_id);
    }

    /**
     * Never redirect a prefixed URL to its unprefixed self.
     *
     * redirect_canonical compares the request against the permalink it
     * would build and redirects to close the difference. It does not
     * know about the prefix, so on a Spanish URL it sees a mismatch it
     * created and redirects the visitor out of their language -- a
     * 301, which browsers and caches then remember.
     *
     * @param string $redirect
     * @param string $requested
     */
    public static function canonical($redirect, $requested = ''): string
    {
        if (!$redirect || !Registry::is_multilingual()) {
            return (string) $redirect;
        }

        $had = Url::prefix_of((string) $requested);
        if ('' === $had) {
            return (string) $redirect;
        }

        // The request was prefixed. Keep the prefix on whatever
        // redirect_canonical wants to do -- it may still have a real
        // correction to make, such as adding a trailing slash.
        if (Url::prefix_of((string) $redirect) === $had) {
            return (string) $redirect;
        }

        $kept = Url::set_language((string) $redirect, $had);

        // If keeping the prefix makes the redirect a no-op, there was
        // nothing to correct but the prefix itself.
        return untrailingslashit($kept) === untrailingslashit((string) $requested) ? '' : $kept;
    }

    /**
     * @param string $url
     */
    public static function rest($url): string
    {
        return Registry::is_multilingual()
            ? Url::set_language((string) $url, Registry::default_code())
            : (string) $url;
    }

    /**
     * Custom nav-menu links.
     *
     * Items pointing at a post or a term already hold a permalink built
     * by get_permalink() or get_term_link(), so their own filters above
     * have decided them. A custom link holds a URL somebody typed, and
     * the tempting reading -- that a typed URL is structural and takes
     * the current language -- is wrong often enough to matter. On this
     * site the footer's "License" and "Privacy" are typed links to real
     * pages, so prefixing them produced /es/privacy/, which has no
     * translation and 404s.
     *
     * So the URL is resolved back to a post where it names one and
     * decided as an object. What resolves to nothing is structural.
     *
     * @param array  $items
     * @param object $menu
     * @param array  $args
     * @return array
     */
    public static function menu_items($items, $menu = null, $args = array()): array
    {
        if (is_admin() || !Registry::is_multilingual() || !is_array($items)) {
            return (array) $items;
        }

        foreach ($items as $item) {
            if (!is_object($item) || 'custom' !== ($item->type ?? '') || empty($item->url)) {
                continue;
            }

            $url = (string) $item->url;
            if (!Url::is_internal($url) || Url::is_untranslatable_path((string) wp_parse_url($url, PHP_URL_PATH))) {
                continue;
            }

            $post_id = self::post_id_for_url($url);

            $item->url = $post_id > 0
                ? self::for_object($url, $post_id)
                : Url::set_language($url, Request::code());
        }

        return $items;
    }

    /**
     * url_to_postid(), memoised.
     *
     * It parses the URL against every rewrite rule, and a menu repeats
     * the same handful of URLs in a header and a footer on every page.
     */
    private static function post_id_for_url(string $url): int
    {
        static $seen = array();

        if (!array_key_exists($url, $seen)) {
            // url_to_postid() strips home_url() off the URL before
            // matching it against the rewrite rules -- and home_url()
            // is filtered here to carry the current language. On a
            // Spanish page it therefore tried to strip
            // 'http://site/es' from 'http://site/license/', failed to
            // match, and returned 0 for every URL on the site. Every
            // custom menu link then looked structural and got a prefix
            // it had no translation for.
            remove_filter('home_url', array(self::class, 'home'), 10);
            $seen[$url] = (int) url_to_postid(Url::set_language($url, Registry::default_code()));
            add_filter('home_url', array(self::class, 'home'), 10, 2);
        }

        return $seen[$url];
    }
}
