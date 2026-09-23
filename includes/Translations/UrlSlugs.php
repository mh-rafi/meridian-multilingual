<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Query;
use Meridian\Multilingual\Routing\Url;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The slug a translated page or term shows in its URL.
 *
 * WordPress wants slugs unique -- across a taxonomy for terms, under one
 * parent for pages -- so a Spanish translation cannot be stored as
 * `business` while its source already is. Meridian stores it as
 * `business-es`, and until this class existed it also *published* it
 * that way: /es/downloads/category/business-es/, where the /es/ prefix
 * already says Spanish.
 *
 * The prefix makes a URL unique per language, so the suffix is only ever
 * needed in the database. A translation whose slug is exactly what
 * Meridian generated -- `{source slug}-{lang}`, plus the `-N` added on a
 * further collision -- is published under its source's slug. Any other
 * slug is somebody's deliberate choice (`negocios`) and is published as
 * typed.
 *
 * Derived, not stored, on purpose. The core slug field stays the one
 * place a slug is edited, and translations created before this existed
 * -- including every one on production, where the database is never
 * replaced from local -- get clean URLs without a migration.
 *
 * Allowing the two languages to share one stored slug was the other way
 * to do this, and was rejected: wp_update_term() refuses a duplicate,
 * term_exists() and every get_term_by('slug') in EDD, the theme and the
 * SEO plugin would quietly pick one of the two, and a slug-based tax
 * query would match both. This keeps WordPress's invariant intact and
 * confines the whole idea to URLs going out and requests coming in.
 */
final class UrlSlugs
{
    /** term_id => slug before the edit in progress. */
    private static array $old_term_slugs = array();

    public static function register(): void
    {
        add_action('edit_terms', array(self::class, 'remember_term_slug'), 10, 2);
        add_action('edited_term', array(self::class, 'follow_term_slug'), 10, 3);
        add_action('post_updated', array(self::class, 'follow_post_slug'), 10, 3);
    }

    /**
     * @param int    $term_id
     * @param string $taxonomy
     */
    public static function remember_term_slug($term_id, $taxonomy = ''): void
    {
        $term = get_term((int) $term_id, (string) $taxonomy);
        if ($term instanceof WP_Term) {
            self::$old_term_slugs[(int) $term_id] = (string) $term->slug;
        }
    }

    /**
     * Keep a translation's generated slug generated when its source's
     * slug changes.
     *
     * The public slug is derived from the source's, so renaming
     * `business` to `business-apps` left `business-es` matching nothing
     * and the Spanish URL fell back to /es/.../business-es/ -- the exact
     * URL this class exists to remove. A translation somebody gave a
     * slug of its own (`negocios`) is left exactly as it is.
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public static function follow_term_slug($term_id, $tt_id = 0, $taxonomy = ''): void
    {
        $term_id = (int) $term_id;
        $taxonomy = (string) $taxonomy;
        $old = self::$old_term_slugs[$term_id] ?? '';
        unset(self::$old_term_slugs[$term_id]);

        // Only a source moves its translations. The translations' own
        // updates below come back through here and stop at this line.
        if ('' === $old || !Modes::is_translated_taxonomy($taxonomy) || !Registry::is_default(Terms::language_of($term_id))) {
            return;
        }

        $source = get_term($term_id, $taxonomy);
        if (!$source instanceof WP_Term || $source->slug === $old) {
            return;
        }

        foreach (Terms::siblings($term_id) as $lang => $id) {
            $translation = (int) $id === $term_id ? null : get_term((int) $id, $taxonomy);
            if ($translation instanceof WP_Term && self::is_generated((string) $translation->slug, $old, (string) $lang)) {
                wp_update_term((int) $id, $taxonomy, array('slug' => Terms::available_slug($source, (string) $lang)));
            }
        }
    }

    /**
     * The same, for a post translated as separate rows.
     *
     * @param int      $post_id
     * @param \WP_Post $after
     * @param \WP_Post $before
     */
    public static function follow_post_slug($post_id, $after = null, $before = null): void
    {
        if (!$after instanceof \WP_Post || !$before instanceof \WP_Post || wp_is_post_revision((int) $post_id)) {
            return;
        }
        if ('' === $before->post_name || $after->post_name === $before->post_name) {
            return;
        }
        if (Modes::SEPARATE !== Modes::for_post_type($after->post_type) || !Registry::is_default(Posts::language_of((int) $post_id))) {
            return;
        }

        foreach (Groups::siblings((int) $post_id) as $lang => $id) {
            if ((int) $id === (int) $post_id) {
                continue;
            }
            if (self::is_generated((string) get_post_field('post_name', (int) $id), $before->post_name, (string) $lang)) {
                wp_update_post(array('ID' => (int) $id, 'post_name' => Duplicator::available_slug($after, (string) $lang)));
            }
        }
    }

    /**
     * The public form of a stored slug.
     */
    public static function public_slug(string $slug, string $source_slug, string $lang): string
    {
        if ('' === $slug || '' === $source_slug || '' === $lang) {
            return $slug;
        }

        $generated = '#^' . preg_quote($source_slug . '-' . strtolower($lang), '#') . '(-[0-9]+)?$#';

        return preg_match($generated, $slug) ? $source_slug : $slug;
    }

    /**
     * Whether a stored slug is one Meridian generated from its source.
     */
    public static function is_generated(string $slug, string $source_slug, string $lang): bool
    {
        return '' !== $source_slug && $slug !== $source_slug && self::public_slug($slug, $source_slug, $lang) === $source_slug;
    }

    /* ------------------------------------------------------------------ */
    /* Terms                                                               */
    /* ------------------------------------------------------------------ */

    public static function for_term(WP_Term $term): string
    {
        $lang = Terms::language_of((int) $term->term_id);
        if (Registry::is_default($lang)) {
            return (string) $term->slug;
        }

        $source = self::default_term($term);

        return $source ? self::public_slug((string) $term->slug, (string) $source->slug, $lang) : (string) $term->slug;
    }

    /**
     * A term link with every stored slug on its path made public.
     *
     * The whole path, not just the last segment: the category rewrite is
     * hierarchical, and a translated child sits under a translated
     * parent whose slug carries the same suffix.
     */
    public static function term_url(string $url, WP_Term $term): string
    {
        $chain = array_merge(array((int) $term->term_id), array_map('intval', get_ancestors((int) $term->term_id, $term->taxonomy, 'taxonomy')));

        foreach ($chain as $id) {
            $node = $id === (int) $term->term_id ? $term : get_term($id, $term->taxonomy);
            if ($node instanceof WP_Term) {
                $url = self::swap_segment($url, (string) $node->slug, self::for_term($node));
            }
        }

        return $url;
    }

    /**
     * A term's public URL, computed without the front-end link filters.
     *
     * For admin screens, where those filters are off and get_term_link()
     * answers with the stored slug and no prefix.
     */
    public static function public_term_url(WP_Term $term): string
    {
        $link = get_term_link($term);
        if (is_wp_error($link)) {
            return '';
        }

        return Url::set_language(self::term_url((string) $link, $term), Terms::language_of((int) $term->term_id));
    }

    /**
     * Turn a requested term path back into stored slugs.
     *
     * Each segment is looked up as a source slug; where that source has
     * a translation in the requested language whose *public* slug is
     * the segment, the translation's stored slug replaces it. Anything
     * else passes through untouched, so a stored slug still resolves --
     * and is then redirected to its public form, not served twice.
     */
    public static function stored_term_path(string $path, string $taxonomy, string $lang): string
    {
        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $i => $segment) {
            if ('' === $segment) {
                continue;
            }

            $found = get_term_by('slug', $segment, $taxonomy);
            if (!$found instanceof WP_Term || Terms::language_of((int) $found->term_id) === $lang) {
                continue;
            }

            $translation = Terms::translation((int) $found->term_id, $lang);
            if ($translation < 1 || $translation === (int) $found->term_id) {
                continue;
            }

            $term = get_term($translation, $taxonomy);
            if ($term instanceof WP_Term && self::for_term($term) === $segment) {
                $segments[$i] = (string) $term->slug;
            }
        }

        return implode('/', $segments);
    }

    private static function default_term(WP_Term $term): ?WP_Term
    {
        $source_id = Terms::translation((int) $term->term_id, Registry::default_code());
        if ($source_id < 1 || $source_id === (int) $term->term_id) {
            return null;
        }

        $source = get_term($source_id, $term->taxonomy);

        return $source instanceof WP_Term ? $source : null;
    }

    /* ------------------------------------------------------------------ */
    /* Posts translated as separate rows                                   */
    /* ------------------------------------------------------------------ */

    public static function for_post(int $post_id): string
    {
        $name = (string) get_post_field('post_name', $post_id);
        $lang = Posts::language_of($post_id);

        if ('' === $name || Registry::is_default($lang)) {
            return $name;
        }

        // Straight from the group, not Posts::id(): the source's slug is
        // what matters here, whether or not it is published.
        $source_id = Groups::translation($post_id, Registry::default_code());
        if ($source_id < 1 || $source_id === $post_id) {
            return $name;
        }

        return self::public_slug($name, (string) get_post_field('post_name', $source_id), $lang);
    }

    /**
     * A permalink with every stored slug on its path made public.
     */
    public static function post_url(string $url, int $post_id): string
    {
        $chain = array_merge(array($post_id), array_map('intval', get_post_ancestors($post_id)));

        foreach ($chain as $id) {
            $url = self::swap_segment($url, (string) get_post_field('post_name', $id), self::for_post($id));
        }

        return $url;
    }

    /**
     * The path a post answers to, parents included, in public slugs.
     */
    public static function public_path(int $post_id): string
    {
        $ids = array_reverse(array_merge(array($post_id), array_map('intval', get_post_ancestors($post_id))));

        return implode('/', array_map(array(self::class, 'for_post'), $ids));
    }

    /**
     * A post's public URL, computed without the front-end link filters.
     */
    public static function public_post_url(int $post_id): string
    {
        $lang = Posts::language_of($post_id);

        if (Query::is_front_page_member($post_id)) {
            return Url::set_language(home_url('/'), $lang);
        }

        return Url::set_language(self::post_url((string) get_permalink($post_id), $post_id), $lang);
    }

    /**
     * Turn a requested post path back into the stored one.
     *
     * The request is matched against the *source's* path first. That is
     * not only convenient: with verbose page rules WordPress only lets
     * /es/terms/ through as a page request after get_page_by_path()
     * finds a page at 'terms' -- the English one -- so the source is
     * exactly what arrives here. Its translation is swapped in only when
     * the translation's own public path is the path that was asked for.
     */
    public static function stored_post_path(string $path, string $post_type, string $lang): string
    {
        $path = trim($path, '/');
        if ('' === $path) {
            return $path;
        }

        $source = get_page_by_path($path, OBJECT, $post_type);
        if (!$source || Posts::language_of((int) $source->ID) === $lang) {
            return $path;
        }

        $translation = Posts::id((int) $source->ID, $lang);
        if ($translation < 1 || $translation === (int) $source->ID || self::public_path($translation) !== $path) {
            return $path;
        }

        return is_post_type_hierarchical($post_type)
            ? (string) get_page_uri($translation)
            : (string) get_post_field('post_name', $translation);
    }

    /**
     * Replace one path segment, matched with its delimiters.
     *
     * Only the first occurrence: slugs are unique in their taxonomy (or
     * under their parent), so the first match is the segment, and a
     * second identical string elsewhere in the URL is something else.
     */
    private static function swap_segment(string $url, string $stored, string $public): string
    {
        if ('' === $stored || $stored === $public) {
            return $url;
        }

        return (string) preg_replace('#/' . preg_quote($stored, '#') . '(?=/|$|\?|\#)#', '/' . $public, $url, 1);
    }
}
