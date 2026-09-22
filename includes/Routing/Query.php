<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Posts;
use Meridian\Multilingual\Translations\Slugs;
use Meridian\Multilingual\Translations\Terms;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a prefixed URL actually serves. (M2)
 *
 * Two jobs, and the second is a principle rather than a convenience.
 *
 *  1. /es/ is the Spanish front page, not the English one under a
 *     Spanish URL.
 *  2. A prefixed URL for something that has no translation is a 404.
 *     Serving the default language's content under /es/ would be
 *     duplicate content with a lie in the URL (spec 2), and the lie is
 *     the part search engines act on.
 */
final class Query
{
    public static function register(): void
    {
        add_filter('request', array(self::class, 'translated_slug'));
        add_filter('request', array(self::class, 'front_page'));
        add_filter('pre_option_page_on_front', array(self::class, 'front_page_option'));
        add_action('wp', array(self::class, 'enforce_translation_exists'));
        add_action('wp', array(self::class, 'redirect_mismatched_post'));
        add_action('wp', array(self::class, 'redirect_mismatched_term'));

        // Before redirect_canonical, which runs at 10 on the same hook
        // and would otherwise get its answer in first.
        add_action('template_redirect', array(self::class, 'redirect_unprefixed_translation'), 5);
    }

    /**
     * Send a translated post's unprefixed URL to its language's URL.
     *
     * A separate-mode translation is a real post row with its own
     * post_name, so WordPress answers at that slug with no prefix as
     * well as under the language prefix: /elementor-home-es/ and /es/
     * are one page. Links no longer *writes* the first form anywhere,
     * but it stays reachable, and anything that already indexed it
     * keeps a duplicate alive.
     *
     * Derived from the post rather than from a stored rule, so every
     * translation created from here on is covered the moment it is
     * published and no redirect table has to be maintained.
     */
    public static function redirect_unprefixed_translation(): void
    {
        if (is_admin() || !Registry::is_multilingual() || !is_singular() || is_preview()) {
            return;
        }

        // Only a request carrying no prefix at all. A *wrong* prefix --
        // /fr/ on a Spanish page -- is redirect_canonical's business
        // and it already resolves it; a second redirect competing with
        // it is how a loop gets built.
        if (!Request::is_default()) {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof WP_Post || 'publish' !== $post->post_status) {
            return;
        }

        if (Modes::SEPARATE !== Modes::for_post_type((string) $post->post_type)) {
            return;
        }

        $lang = Posts::language_of((int) $post->ID);
        if (Registry::is_default($lang)) {
            return;
        }

        $target = (string) get_permalink((int) $post->ID);
        if ('' === $target) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $requested_path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $target_path = (string) wp_parse_url($target, PHP_URL_PATH);

        // Nothing to correct. Checked on the path rather than trusting
        // the conditions above, because this is the one guard standing
        // between a mistake here and an infinite redirect.
        if (untrailingslashit($requested_path) === untrailingslashit($target_path)) {
            return;
        }

        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        if ('' !== $query) {
            $target .= (false === strpos($target, '?') ? '?' : '&') . $query;
        }

        wp_safe_redirect($target, 301);
        exit;
    }

    /**
     * Turn a translated slug back into the one WordPress can find. (M3)
     *
     * A single-source post has one row and one post_name, so
     * /es/downloads/software-de-punto-de-venta asks WordPress for a
     * download named 'software-de-punto-de-venta' and it finds nothing.
     * That name exists only in meridian_fields, so it is resolved here
     * -- on 'request', which is after the rewrite rules matched and
     * before the query runs, the only point where the query var can
     * still be changed without a second query.
     *
     * Untouched when nothing resolves, so an untranslated slug under a
     * prefix still routes to the source post (M3's fallback) rather
     * than 404ing.
     *
     * @param array $vars
     * @return array
     */
    public static function translated_slug(array $vars): array
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return $vars;
        }

        $lang = isset($vars['lang']) ? (string) $vars['lang'] : '';
        if ('' === $lang || Registry::is_default($lang) || !Registry::exists($lang)) {
            return $vars;
        }

        foreach (Modes::single_source_types() as $post_type) {
            $object = get_post_type_object($post_type);
            if (!$object) {
                continue;
            }

            // The query var a permalink resolves into: 'download' for
            // the download post type, 'name' for posts.
            $key = ('post' === $post_type) ? 'name' : ($object->query_var ?: $post_type);

            if (empty($vars[$key]) || !is_string($vars[$key])) {
                continue;
            }

            $source = Slugs::resolve($vars[$key], $lang);
            if ($source > 0 && get_post_type($source) === $post_type) {
                $vars[$key] = (string) get_post_field('post_name', $source);
            }
        }

        return $vars;
    }

    /**
     * Point /es/ at the front page's translation.
     *
     * @param array $vars
     * @return array
     */
    public static function front_page(array $vars): array
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return $vars;
        }

        $lang = isset($vars['lang']) ? (string) $vars['lang'] : '';
        if ('' === $lang || Registry::is_default($lang) || !Registry::exists($lang)) {
            return $vars;
        }

        // Only the bare language home. /es/anything carries its own
        // query vars and routes like any other request.
        if (array_keys($vars) !== array('lang')) {
            return $vars;
        }

        if ('page' !== get_option('show_on_front')) {
            return $vars;
        }

        $front = (int) self::unfiltered_front_page();
        if ($front < 1) {
            return $vars;
        }

        $translation = Posts::id($front, $lang);
        if ($translation < 1) {
            // No translated front page means the language has no home.
            // Left to resolve as a 404 rather than quietly serving the
            // default one: a language whose front page nobody wrote is
            // not ready to be linked to.
            $vars['error'] = '404';
            return $vars;
        }

        $vars['page_id'] = $translation;

        return $vars;
    }

    /**
     * Make the whole site agree on which page is the front page.
     *
     * is_front_page(), the theme's template choice and anything reading
     * homepage content from post meta all resolve through this option.
     * Filtering the query alone would leave a Spanish front page
     * rendering the English page's fields -- the redesign keeps homepage
     * content in post meta on that page, so it would be the English
     * hero under a Spanish URL.
     *
     * @param mixed $pre
     * @return mixed
     */
    public static function front_page_option($pre)
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return $pre;
        }

        $lang = Request::code();
        if (Registry::is_default($lang)) {
            return $pre;
        }

        $front = (int) self::unfiltered_front_page();
        if ($front < 1) {
            return $pre;
        }

        $translation = Posts::id($front, $lang);

        return $translation > 0 ? (string) $translation : $pre;
    }

    /**
     * The stored front page, without re-entering the filter above.
     */
    /**
     * The site's own front page, not the current language's.
     */
    public static function stored_front_page(): int
    {
        return self::unfiltered_front_page();
    }

    private static function unfiltered_front_page(): int
    {
        remove_filter('pre_option_page_on_front', array(self::class, 'front_page_option'));
        $front = (int) get_option('page_on_front');
        add_filter('pre_option_page_on_front', array(self::class, 'front_page_option'));

        return $front;
    }

    /**
     * 404 a prefixed URL whose content has no translation.
     *
     * On 'wp' rather than 'pre_get_posts': the queried object is only
     * known once the query has run, and this is a decision about what
     * was found rather than about what to look for.
     */
    public static function enforce_translation_exists(): void
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return;
        }

        global $wp_query;
        if (!$wp_query instanceof WP_Query || !$wp_query->is_main_query() || $wp_query->is_404()) {
            return;
        }

        $lang = Request::code();
        if (Registry::is_default($lang)) {
            return;
        }

        // Archives, search and the front page are queries rather than
        // content: they exist in every language the site serves.
        if (!$wp_query->is_singular()) {
            return;
        }

        $post_id = (int) $wp_query->get_queried_object_id();
        if ($post_id < 1) {
            return;
        }

        if (apply_filters('meridian_post_available_in_every_language', false, $post_id)) {
            return;
        }

        if (Posts::has($post_id, $lang)) {
            return;
        }

        /**
         * Filter whether an untranslated object 404s under a prefix.
         *
         * Turning this off serves the default language's content at a
         * prefixed URL, which is duplicate content. It exists so that a
         * site mid-translation can make that trade knowingly, not
         * because it is a reasonable default.
         *
         * @param bool   $enforce
         * @param int    $post_id
         * @param string $lang
         */
        if (!apply_filters('meridian_404_untranslated', true, $post_id, $lang)) {
            return;
        }

        self::send_404($wp_query);
    }

    /**
     * A singular post to the row that actually belongs to this URL's
     * language.
     *
     * The same gap redirect_mismatched_term() closes for terms, in the
     * place it was left open for posts. WordPress resolves a page by
     * matching post_name literally and 'lang' sits unused, so
     * /es/terms/ -- the English page's own slug under a Spanish prefix
     * -- rendered the English page. enforce_translation_exists() did
     * not catch it, and could not: it asks whether a translation
     * exists, and here one does. What nothing asked was whether the
     * row WordPress actually found is that translation.
     *
     * The untranslated case is deliberately untouched and still 404s
     * through enforce_translation_exists(); the half-translated
     * fallback for single-source posts is untouched too, because a
     * single-source row is its own translation and fails the
     * $correct === $post_id guard below, exactly as the term version
     * does.
     */
    public static function redirect_mismatched_post(): void
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return;
        }

        global $wp_query;
        if (!$wp_query instanceof WP_Query || !$wp_query->is_main_query() || $wp_query->is_404()) {
            return;
        }
        if (!$wp_query->is_singular() || is_preview()) {
            return;
        }

        $url_lang = Request::code();
        if (Registry::is_default($url_lang)) {
            // An unprefixed URL naming a translated row is the other
            // direction, and redirect_unprefixed_translation() owns it.
            return;
        }

        $post_id = (int) $wp_query->get_queried_object_id();
        if ($post_id < 1) {
            return;
        }

        // A page that is never duplicated is readable under every
        // prefix (M6). EDD's checkout and account pages are the case
        // that matters: redirecting one out of /es/ would end a Spanish
        // visitor's session in English mid-purchase.
        if (apply_filters('meridian_post_available_in_every_language', false, $post_id)) {
            return;
        }

        if (Posts::language_of($post_id) === $url_lang) {
            // Already the row that belongs to this URL's language.
            return;
        }

        $correct = Posts::id($post_id, $url_lang);
        if ($correct < 1 || $correct === $post_id) {
            // No sibling in this language -- enforce_translation_exists()
            // decides that case -- or a single-source row, which is its
            // own translation and is already serving the right content.
            return;
        }

        $target = Url::set_language((string) get_permalink($correct), $url_lang);
        if ('' === $target) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

        // The loop guard. Everything above says these two URLs differ;
        // this is the one line that does not take that on trust.
        if (untrailingslashit((string) wp_parse_url($uri, PHP_URL_PATH)) === untrailingslashit((string) wp_parse_url($target, PHP_URL_PATH))) {
            return;
        }

        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        if ('' !== $query) {
            $target .= (false === strpos($target, '?') ? '?' : '&') . $query;
        }

        wp_safe_redirect($target, 301);
        exit;
    }

    /**
     * A term archive to the term that actually belongs to this URL's
     * language. (found 23 September 2026, closing a gap M7 left open)
     *
     * WordPress resolves a taxonomy archive by matching the requested
     * slug literally -- 'lang' sits in the query vars unused. So
     * /es/downloads/category/admin-dashboard/ (the English term's own
     * slug) rendered the English term's archive under a Spanish URL,
     * and the same slug with no prefix at all rendered a *translated*
     * term's archive, because nothing ever checked whether the term
     * WordPress found actually belongs to the language the URL
     * implies. M3 built this check for downloads
     * (Query::translated_slug()); M7 never built its equivalent for
     * terms.
     *
     * The fix is the same shape as M3's slug-change redirect: exactly
     * one canonical URL per language per term. Anything that resolves
     * to the right *group* but the wrong *member* 301s to the address
     * that actually belongs to this URL's language, rather than
     * silently serving content whose language does not match its URL.
     */
    public static function redirect_mismatched_term(): void
    {
        if (is_admin() || !Registry::is_multilingual()) {
            return;
        }

        global $wp_query;
        if (!$wp_query instanceof WP_Query || !$wp_query->is_main_query() || $wp_query->is_404()) {
            return;
        }
        if (!$wp_query->is_tax() && !$wp_query->is_category() && !$wp_query->is_tag()) {
            return;
        }

        $term = get_queried_object();
        if (!$term instanceof \WP_Term || !Modes::is_translated_taxonomy($term->taxonomy)) {
            return;
        }

        $url_lang = Request::code();
        $term_lang = Terms::language_of((int) $term->term_id);

        if ($term_lang === $url_lang) {
            // Already the term that belongs to this URL's language --
            // the ordinary, correctly-resolved case.
            return;
        }

        $correct = Terms::translation((int) $term->term_id, $url_lang);
        if ($correct < 1 || $correct === (int) $term->term_id) {
            // No sibling in the requested language. This is the
            // documented fallback (spec M3, applied here to terms): the
            // source term renders as-is under a prefix it has no
            // translation for, so a half-translated site still routes,
            // rather than 404ing.
            return;
        }

        $link = get_term_link($correct);
        if (is_wp_error($link)) {
            return;
        }

        wp_safe_redirect(Url::set_language((string) $link, $url_lang), 301);
        exit;
    }

    /**
     * Turn the current request into a 404 and make it stick.
     *
     * set_404() alone is not enough. redirect_canonical() runs later,
     * sees a 404 whose query vars still name a real post, and tries to
     * rescue it -- on this site by 301ing to the language home, which
     * is worse than the 404 in every way: it is permanent, browsers and
     * caches remember it, and the visitor lands somewhere that looks
     * deliberate. So the rescue is removed for this request only, not
     * globally, and the queried object is cleared so nothing downstream
     * still believes a post was found.
     */
    private static function send_404(WP_Query $wp_query): void
    {
        $wp_query->set_404();
        $wp_query->queried_object = null;
        $wp_query->queried_object_id = 0;

        remove_action('template_redirect', 'redirect_canonical');

        // Easy Digital Downloads treats a 404 under its archive slug as
        // evidence that its rewrite rules went missing, and flushes
        // them and redirects once to prove it. A deliberately
        // untranslated product is not that, and letting it fire both
        // costs a rewrite flush and turns the 404 into a redirect.
        // Detected, not required -- this plugin works without EDD.
        if (function_exists('edd_refresh_permalinks_on_bad_404')) {
            remove_action('template_redirect', 'edd_refresh_permalinks_on_bad_404');
        }

        status_header(404);
        nocache_headers();
    }
}
