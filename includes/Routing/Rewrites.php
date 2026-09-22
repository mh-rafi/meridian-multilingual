<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The prefix going in. (M2)
 *
 * Rather than writing rules for pages, posts, downloads, taxonomies,
 * archives, pagination, feeds and search by hand, this takes the rules
 * WordPress already built and adds a language-prefixed copy of each.
 * Everything WordPress can route, it can route under /es/ -- including
 * whatever a plugin registered, which a hand-written list would miss
 * and keep missing.
 */
final class Rewrites
{
    public static function register(): void
    {
        add_filter('query_vars', array(self::class, 'query_var'));
        add_filter('rewrite_rules_array', array(self::class, 'prefix_rules'));

        // The rules are built from the language set, so they are stale
        // the moment it changes. Flushing here rather than on admin_init
        // keeps the expensive part tied to the rare event.
        add_action('meridian_languages_saved', array(self::class, 'flush'));

        // The prefix owns its path segment, so no post may take it.
        // Core's own extension point for exactly this: it already uses
        // these two filters to keep a slug off the rewrite front and
        // off the pagination base, and answering true makes it append
        // -2, -3 until the slug is free -- the same correction, and the
        // same wording in the editor, that any other slug collision
        // gets.
        add_filter('wp_unique_post_slug_is_bad_hierarchical_slug', array(self::class, 'is_reserved_hierarchical_slug'), 10, 4);
        add_filter('wp_unique_post_slug_is_bad_flat_slug', array(self::class, 'is_reserved_flat_slug'), 10, 3);
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public static function query_var(array $vars): array
    {
        $vars[] = 'lang';
        return $vars;
    }

    /**
     * Add a language-prefixed copy of every rule.
     *
     * @param array $rules pattern => query
     * @return array
     */
    public static function prefix_rules(array $rules): array
    {
        $codes = self::prefixed_codes();
        if (!$codes) {
            return $rules;
        }

        $group = '(' . implode('|', array_map(static fn($code) => preg_quote($code, '#'), $codes)) . ')';

        $prefixed = array();
        foreach ($rules as $pattern => $query) {
            if (self::is_infrastructure($pattern)) {
                continue;
            }

            $prefixed[$group . '/' . ltrim($pattern, '^')] = self::shift_matches($query) . '&lang=$matches[1]';
        }

        // The language home. WordPress's own root rule is '$' with no
        // capture, so the generic pass above produces a usable rule for
        // it too -- but only this one is guaranteed present, and /es/
        // has to work even on a site whose root rule a plugin replaced.
        $prefixed[$group . '/?$'] = 'index.php?lang=$matches[1]';

        // Prefixed rules first. Otherwise 'es/about' can be matched by
        // the page rule as a page named 'about' nested under a page
        // named 'es', which resolves to a 404 that looks like a routing
        // bug rather than like rule ordering.
        return $prefixed + $rules;
    }

    /**
     * Whether a rule is site infrastructure rather than content.
     *
     * Anchored rules -- the ones WordPress writes with a leading ^ --
     * are the REST API and the sitemaps, core's and Sightline's. None of
     * them is language-routed by path: REST takes its own parameters,
     * and per-language sitemap children belong to Sightline, driven by
     * this plugin (spec 7). Prefixing them would publish /es/wp-json/
     * and a second set of sitemap URLs that nothing links to.
     */
    private static function is_infrastructure(string $pattern): bool
    {
        return str_starts_with($pattern, '^');
    }

    /**
     * Renumber $matches[N] for the capture group added at the front.
     *
     * Descending, so that rewriting 1 into 2 cannot then be rewritten
     * again when the pass reaches 2.
     */
    private static function shift_matches(string $query): string
    {
        for ($i = 9; $i >= 1; $i--) {
            $query = str_replace('$matches[' . $i . ']', '$matches[' . ($i + 1) . ']', $query);
        }
        return $query;
    }

    /**
     * Active languages that actually have a prefix.
     *
     * @return string[]
     */
    /**
     * A top-level page may not be named after a language.
     *
     * /es/ is the Spanish site, so a page whose slug is 'es' has no
     * address of its own. Worse than unreachable: Url::prefix_of()
     * reads that first segment as a prefix and strips it, so the page's
     * permalink came back as the site root and it displaced the
     * homepage in every menu and in the sitemap.
     *
     * Only at the top level -- /about/es/ is a path the prefix never
     * claims.
     *
     * @param bool   $bad
     * @param string $slug
     * @param string $post_type
     * @param int    $post_parent
     * @return bool
     */
    public static function is_reserved_hierarchical_slug($bad, $slug = '', $post_type = '', $post_parent = 0)
    {
        if ($bad || (int) $post_parent > 0) {
            return (bool) $bad;
        }

        return self::is_reserved_slug((string) $slug, (string) $post_type) ? true : (bool) $bad;
    }

    /**
     * The same rule for a post type that is not hierarchical.
     *
     * Real here rather than theoretical: this site's permalinks are
     * /%postname%/, so a post slugged 'fr' sits at the root exactly as
     * a page would. A download does not -- its permastruct opens with
     * a literal 'downloads/'.
     *
     * @param bool   $bad
     * @param string $slug
     * @param string $post_type
     * @return bool
     */
    public static function is_reserved_flat_slug($bad, $slug = '', $post_type = '')
    {
        if ($bad) {
            return (bool) $bad;
        }

        return self::is_reserved_slug((string) $slug, (string) $post_type) ? true : (bool) $bad;
    }

    /**
     * Whether this slug would collide with a language prefix.
     */
    private static function is_reserved_slug(string $slug, string $post_type): bool
    {
        $slug = strtolower(trim($slug));

        if ('' === $slug || !Registry::is_multilingual() || !self::type_lives_at_root($post_type)) {
            return false;
        }

        foreach (self::prefixed_codes() as $code) {
            if ($slug === strtolower(Registry::prefix($code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a post type's slug is the first segment of its URL.
     *
     * A permastruct opening with a literal segment -- 'downloads/%download%'
     * -- puts the slug one level down, where a prefix never reaches it.
     * One opening with a tag is at the root, where it does.
     */
    private static function type_lives_at_root(string $post_type): bool
    {
        global $wp_rewrite;

        if (!$wp_rewrite instanceof \WP_Rewrite) {
            // Too early to tell. Reserving the slug is the safe answer:
            // the cost is a '-2' on a slug nobody wanted, against a
            // page that displaces the homepage.
            return true;
        }

        if ('page' === $post_type) {
            $struct = (string) $wp_rewrite->get_page_permastruct();
        } elseif ('post' === $post_type) {
            $struct = (string) get_option('permalink_structure');
        } else {
            $struct = (string) $wp_rewrite->get_extra_permastruct($post_type);
        }

        $struct = ltrim($struct, '/');

        return '' === $struct || '%' === substr($struct, 0, 1);
    }

    private static function prefixed_codes(): array
    {
        $codes = array();
        foreach (Registry::active() as $code => $language) {
            if ('' !== Registry::prefix($code)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * Rebuild the rules.
     *
     * Deferred to shutdown: save() may be called from anywhere, and
     * flushing rules mid-request is slow and, in the admin, happens
     * before the response the user is waiting for.
     */
    public static function flush(): void
    {
        add_action('shutdown', static function () {
            flush_rewrite_rules(false);
        });
    }
}
