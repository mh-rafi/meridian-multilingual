<?php

namespace Meridian\Multilingual\Integrations;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Frontend\Alternates;
use Meridian\Multilingual\Routing\Links;
use Meridian\Multilingual\Routing\Request;
use Meridian\Multilingual\Translations\Content;
use Meridian\Multilingual\Translations\Fields;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Terms;
use Meridian\Multilingual\Routing\Url;
use Sightline\SEO\Redirects\Store;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sightline SEO, where it is present. (M3's redirects; M11 for the rest)
 *
 * Detected, never required. Everything here is guarded on the class
 * actually existing, and the plugin works with no SEO plugin at all.
 */
final class Sightline
{
    public static function register(): void
    {
        if (!self::available()) {
            return;
        }

        add_action('meridian_slug_changed', array(self::class, 'record_redirect'), 10, 4);

        // M11. Each of these is additive: Sightline keeps owning the
        // concern, and Meridian only supplies the per-language answer.
        add_filter('sightline_redirect_request_path', array(self::class, 'strip_prefix'), 10, 2);
        add_filter('sightline_title', array(self::class, 'title'), 10, 2);
        add_filter('sightline_description', array(self::class, 'description'), 10, 2);
        add_filter('sightline_webpage_schema', array(self::class, 'in_language'));
        add_filter('sightline_sitemap_post_urls', array(self::class, 'sitemap_urls'), 10, 2);
        add_filter('sightline_sitemap_term_urls', array(self::class, 'sitemap_term_urls'), 10, 2);
    }

    public static function available(): bool
    {
        return class_exists(Store::class) && method_exists(Store::class, 'save');
    }

    /**
     * Keep the old translated URL working.
     *
     * Meridian does not grow its own redirect table. One redirect table
     * per site (M3): two of them means two places to look when a URL
     * stops working, and the answer is in whichever one you checked
     * second.
     *
     * @param int    $post_id
     * @param string $lang
     * @param string $previous Old slug.
     * @param string $slug     New slug.
     */
    public static function record_redirect($post_id, $lang, $previous, $slug): void
    {
        $post_id = (int) $post_id;
        $previous = (string) $previous;
        $slug = (string) $slug;

        if ($post_id < 1 || '' === $previous || $previous === $slug || !Registry::exists((string) $lang)) {
            return;
        }

        // get_permalink() answers in whatever language this request is
        // in -- the admin's, not the one whose slug changed -- and it
        // substitutes that language's translated slug. Both make the
        // result unusable as something to swap slugs in. Forcing the
        // default language for the duration gives one predictable
        // shape: the source slug, no prefix, whatever permastruct the
        // site uses.
        $base = self::source_permalink($post_id);
        if ('' === $base) {
            return;
        }

        $source_slug = (string) get_post_field('post_name', $post_id);
        if ('' === $source_slug) {
            return;
        }

        $new_url = Url::set_language(self::swap_last_segment($base, $source_slug, $slug), (string) $lang);
        $old_url = Url::set_language(self::swap_last_segment($base, $source_slug, $previous), (string) $lang);

        if ($old_url === $new_url) {
            return;
        }

        $source = self::path_of($old_url);
        if ('' === $source) {
            return;
        }

        $sources = array(array('comparison' => 'exact', 'pattern' => $source));

        // Sightline stores rules keyed on their sources, so re-saving
        // the same source updates the rule rather than stacking a
        // second one. Renaming a slug three times leaves three rules,
        // each pointing at the URL current when it was written -- which
        // is correct, and each one still resolves in a single hop.
        $existing = method_exists(Store::class, 'find_by_sources') ? (int) Store::find_by_sources($sources) : 0;

        Store::save(array(
            'sources'     => $sources,
            'url_to'      => $new_url,
            'header_code' => 301,
            'status'      => 'active',
        ), $existing ?: null);

        self::drop_rule_for(self::path_of($new_url));
    }

    /**
     * Remove any rule whose source is the URL that is now live.
     *
     * Renaming a slug back to something it used to be leaves a rule
     * pointing away from the address the post now answers to: the live
     * URL 301s to its own previous name, which 301s back. A redirect
     * loop, from two saves that were each individually correct.
     */
    private static function drop_rule_for(string $path): void
    {
        if ('' === $path || !method_exists(Store::class, 'find_by_sources') || !method_exists(Store::class, 'delete')) {
            return;
        }

        $id = (int) Store::find_by_sources(array(array('comparison' => 'exact', 'pattern' => $path)));

        if ($id > 0) {
            Store::delete($id);
        }
    }

    /**
     * The post's permalink in the default language, with its own slug.
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
     * Let one redirect rule cover every language. (M11)
     *
     * Sightline stores a rule as a path and compares it against the
     * request with the site's subdirectory stripped, but not a language
     * prefix -- so a rule saved as 'downloads/x' never fires for
     * '/es/downloads/x', and maintaining 26 rules times N languages is
     * a table nobody maintains.
     *
     * The full path is offered first and the stripped one only as a
     * fallback, because M3 stores language-specific rules *with* the
     * prefix: a retired Spanish slug only ever existed in Spanish.
     * Stripping unconditionally would stop those matching.
     *
     * @param string|array $path
     * @param string       $uri
     * @return string|array
     */
    public static function strip_prefix($path, $uri = '')
    {
        if (!Registry::is_multilingual()) {
            return $path;
        }

        $full = is_array($path) ? (string) reset($path) : (string) $path;
        $prefix = Url::prefix_of(home_url('/' . ltrim($full, '/')));

        if ('' === $prefix) {
            return $path;
        }

        $stripped = ltrim((string) substr($full, strlen($prefix)), '/');

        // Full first, stripped second.
        return array($full, $stripped);
    }

    /**
     * @param string                    $title
     * @param \Sightline\SEO\Context|mixed $context
     */
    public static function title($title, $context = null): string
    {
        return self::meta_value((string) $title, self::post_id_of($context), '_sightline_title', Content::TITLE);
    }

    /**
     * @param string                    $description
     * @param \Sightline\SEO\Context|mixed $context
     */
    public static function description($description, $context = null): string
    {
        return self::meta_value((string) $description, self::post_id_of($context), '_sightline_description', Content::EXCERPT);
    }

    /**
     * The post ID out of a Sightline Context, or 0.
     *
     * sightline_title and sightline_description pass Values::__construct()'s
     * Context object as the second argument, not a post ID -- casting
     * it with (int) does not throw, it silently evaluates to 1, so this
     * was reading and writing translations for post 1 on every request
     * instead of doing nothing on the pages it did not apply to. Only a
     * Context whose type is 'post' names an ID this class means anything
     * by; a term, archive or search context is 0, same as "no post".
     */
    private static function post_id_of($context): int
    {
        if ($context instanceof \Sightline\SEO\Context && \Sightline\SEO\Context::POST === $context->type) {
            return (int) $context->object_id;
        }

        // Tolerate a raw post ID too, in case something else calls
        // these methods directly rather than through the filter.
        return is_numeric($context) ? (int) $context : 0;
    }

    /**
     * Sightline reads _sightline_* from the source row, so on a
     * single-source product it would serve the English title and
     * description under a Spanish URL.
     */
    private static function meta_value(string $value, int $post_id, string $key, string $fallback): string
    {
        if (is_admin() || $post_id < 1 || !Registry::is_multilingual()) {
            return $value;
        }

        $lang = Request::code();
        if (Registry::is_default($lang) || !Modes::is_single_source((string) get_post_type($post_id))) {
            return $value;
        }

        $translated = Fields::get($post_id, $lang, $key);
        if (null !== $translated && '' !== $translated) {
            return $translated;
        }

        // No translated SEO field, but a translated title or excerpt is
        // a better answer than the English one.
        $content = Fields::get($post_id, $lang, $fallback);

        return (null !== $content && '' !== $content) ? $content : $value;
    }

    /*
     * Deliberately not filtering 'sightline_context'.
     *
     * The spec listed it as where Meridian tells Sightline the current
     * language. It cannot: Sightline\SEO\Context is a typed object
     * with four fixed public properties and no slot for one, and
     * treating it as an array to add a key fataled every page it runs
     * on -- Values::__construct() and Robots::rules() both typehint it.
     *
     * Nothing needs it. Anything asking Sightline what language a
     * request is in can ask Meridian directly through
     * meridian_current_language, which is the filter that already
     * answers that question.
     */

    /**
     * @param array $schema
     * @return array
     */
    public static function in_language($schema): array
    {
        $schema = (array) $schema;
        $language = Registry::get(Request::code());

        if ($language) {
            $schema['inLanguage'] = str_replace('_', '-', $language->locale ?: $language->code);
        }

        return $schema;
    }

    /**
     * One source download becomes one URL per language it exists in.
     *
     * The filter hands over one post type's whole page of entries
     * (`loc`/`lastmod`/`images`, paginated), not one post's -- the
     * earlier version of this method expected a post ID as the second
     * argument and got the post type string instead, so it always
     * returned early having done nothing. Fixed by resolving each
     * entry's own post ID from its `loc` and expanding it in place.
     *
     * Only single-source post types are expanded. A duplicated post
     * type (pages, posts) already has one real row per language, so
     * the base query that built $urls already listed each translation
     * as its own entry -- expanding it again would duplicate every one.
     *
     * @param array  $urls
     * @param string $post_type
     * @return array
     */
    public static function sitemap_urls($urls, $post_type = ''): array
    {
        $urls = (array) $urls;

        if (!Registry::is_multilingual() || !Modes::is_single_source((string) $post_type)) {
            return $urls;
        }

        $expanded = array();

        foreach ($urls as $entry) {
            if (!is_array($entry) || empty($entry['loc'])) {
                $expanded[] = $entry;
                continue;
            }

            // url_to_postid() strips home_url() off the URL before
            // matching, and home_url() is filtered to carry the
            // current language -- the same self-interference M2 hit
            // resolving menu links. Removed for the one call, so a
            // prefixed loc still resolves.
            remove_filter('home_url', array(Links::class, 'home'), 10);
            $post_id = url_to_postid((string) $entry['loc']);
            add_filter('home_url', array(Links::class, 'home'), 10, 2);

            $alternates = $post_id > 0 ? Alternates::for_sitemap($post_id) : array();

            if (!$alternates) {
                // Not resolvable, or not translated: the one entry the
                // base query already built is the whole answer.
                $expanded[] = $entry;
                continue;
            }

            foreach ($alternates as $url) {
                $expanded[] = array('loc' => $url) + $entry;
            }
        }

        return $expanded;
    }

    /**
     * A translated category's archive, listed after its source's.
     *
     * Sightline leaves empty terms out of the sitemap, reasonably: an
     * archive with nothing on it is a thin page. A translated term is
     * always "empty", though. Nothing is assigned to it by design,
     * because its archive lists the source's items through the widened
     * group query (Queries::widen_term_query()). So every Spanish category
     * was missing from the sitemap even though its page is as full as
     * the English one.
     *
     * Each translation is listed only when its source was, which keeps
     * Sightline's rules (not empty, not noindex) deciding. A translation
     * marked noindex itself is still left out.
     *
     * @param array  $urls
     * @param string $taxonomy
     * @return array
     */
    public static function sitemap_term_urls($urls, $taxonomy = ''): array
    {
        $urls = (array) $urls;

        if (!Registry::is_multilingual() || !Modes::is_translated_taxonomy((string) $taxonomy)) {
            return $urls;
        }

        $seen = array();
        foreach ($urls as $entry) {
            if (is_array($entry) && !empty($entry['loc'])) {
                $seen[(string) $entry['loc']] = true;
            }
        }

        $active = Registry::active();
        $robots_key = method_exists(\Sightline\SEO\Meta::class, 'key') ? \Sightline\SEO\Meta::key('robots') : '_sightline_robots';
        $out = array();

        foreach ($urls as $entry) {
            $out[] = $entry;
            if (!is_array($entry) || empty($entry['loc'])) {
                continue;
            }

            // The entry's term, from its last path segment: the source's
            // own slug, since the sitemap is built in the default language.
            $path = trim((string) wp_parse_url((string) $entry['loc'], PHP_URL_PATH), '/');
            $term = get_term_by('slug', (string) substr(strrchr('/' . $path, '/'), 1), (string) $taxonomy);
            if (!$term instanceof \WP_Term || !Registry::is_default(Terms::language_of((int) $term->term_id))) {
                continue;
            }

            foreach (Terms::siblings((int) $term->term_id) as $lang => $id) {
                if ((int) $id === (int) $term->term_id || !isset($active[$lang])) {
                    continue;
                }
                if (false !== stripos((string) get_term_meta((int) $id, $robots_key, true), 'noindex')) {
                    continue;
                }

                $link = Links::own_term_link((int) $id);
                if ('' !== $link && !isset($seen[$link])) {
                    $seen[$link] = true;
                    $out[] = array('loc' => $link) + $entry;
                }
            }
        }

        return $out;
    }

    private static function swap_last_segment(string $url, string $from, string $to): string
    {
        return (string) preg_replace(
            '#/' . preg_quote($from, '#') . '(/|$|\?|\#)#',
            '/' . $to . '$1',
            $url,
            1
        );
    }

    /**
     * A URL as Sightline stores a rule source: path only, no leading or
     * trailing slash, matching Router::request_path().
     */
    private static function path_of(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = Request::base_path();

        if ('/' !== $base && 0 === strpos($path, $base)) {
            $path = substr($path, strlen($base) - 1);
        }

        return trim($path, '/');
    }
}
