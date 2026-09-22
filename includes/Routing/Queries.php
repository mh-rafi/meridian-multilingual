<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Terms;
use WP_Query;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeping one language's content out of another's listings. (M4)
 *
 * A Spanish archive that lists English pages is the same failure as an
 * English link on a Spanish page, one step further in: the visitor
 * clicks something that looked translated and it is not.
 *
 * Applied to the main query automatically. Custom queries opt in, by
 * passing 'meridian_language' => 'current' or a code -- the documented
 * helper this requirement asks for. They are not filtered by default
 * because this plugin has no idea what a given custom query is for, and
 * silently narrowing, say, the query behind a sitemap or a related-items
 * block would be a bug nobody could find from the outside.
 */
final class Queries
{
    public static function register(): void
    {
        add_action('pre_get_posts', array(self::class, 'mark_main_query'));
        add_action('pre_get_posts', array(self::class, 'widen_term_query'), 11);
        add_filter('posts_where', array(self::class, 'restrict'), 10, 2);
    }

    /**
     * The front end's main query is filtered without being asked.
     */
    public static function mark_main_query($query): void
    {
        if (!$query instanceof WP_Query || is_admin() || !$query->is_main_query()) {
            return;
        }

        // Singular requests were already resolved by the router, and a
        // language clause on them can only turn a page that was found
        // into a 404 for a second reason.
        if ($query->is_singular() || $query->is_404()) {
            return;
        }

        if (null === $query->get('meridian_language', null)) {
            $query->set('meridian_language', 'current');
        }
    }

    /**
     * A translated term archive lists its source's objects. (M7)
     *
     * The Spanish product category has no products assigned to it, and
     * assigning them would mean maintaining 34 relationships twice and
     * splitting every count the first time somebody forgot. So the
     * query is widened to the whole translation group instead: the
     * Spanish term for display and in the URL, its source's objects in
     * the results.
     *
     * Widening rather than substituting, so a taxonomy whose objects
     * ARE duplicated per language -- posts in a translated category --
     * still finds the ones assigned to the translated term. One rule
     * covers both storage models.
     */
    public static function widen_term_query($query): void
    {
        if (!$query instanceof WP_Query || is_admin() || !$query->is_main_query()) {
            return;
        }
        if (!$query->is_tax() && !$query->is_category() && !$query->is_tag()) {
            return;
        }
        if (!Registry::is_multilingual()) {
            return;
        }

        // From the query vars, not get_queried_object(): on
        // pre_get_posts the query has not run, so the queried object is
        // not resolved yet and asking for it returns null. The archive
        // rendered with the right Spanish heading and no products,
        // which looks like a data problem rather than a timing one.
        $found = self::queried_term($query);
        if (!$found) {
            return;
        }

        list($term, $var) = $found;
        if (!$term instanceof \WP_Term || !Modes::is_translated_taxonomy($term->taxonomy)) {
            return;
        }

        $ids = Terms::group_ids((int) $term->term_id);
        if (count($ids) < 2) {
            return;
        }

        // The queried term first. WP_Query::get_queried_object() takes
        // the first term of the first clause, so ordering decides which
        // term the archive believes it is showing -- and with the
        // source first, a Spanish archive rendered under the English
        // term's name and heading.
        $ids = array_merge(array((int) $term->term_id), array_diff($ids, array((int) $term->term_id)));

        $query->set('tax_query', array(array(
            'taxonomy'         => $term->taxonomy,
            'field'            => 'term_id',
            'terms'            => $ids,
            'include_children' => true,
        )));

        // And clear the taxonomy's own query var. parse_tax_query()
        // runs again after pre_get_posts and builds a clause from both
        // sources, ANDed -- so the slug clause (this term only) and the
        // widened clause (the group) intersect back down to this term,
        // which has nothing assigned to it. The archive came out empty
        // under the right heading.
        $query->set($var, '');
    }

    /**
     * The term a taxonomy archive is for, and the var that named it.
     *
     * @return array{0:\WP_Term,1:string}|null
     */
    private static function queried_term(WP_Query $query)
    {
        foreach (get_taxonomies(array(), 'objects') as $taxonomy) {
            if (!Modes::is_translated_taxonomy($taxonomy->name)) {
                continue;
            }

            $var = $taxonomy->query_var ?: $taxonomy->name;
            $slug = $query->get($var);

            // 'category' answers to category_name, not to its own name.
            if (!$slug && 'category' === $taxonomy->name) {
                $slug = $query->get('category_name');
            }

            if (!$slug || !is_string($slug)) {
                continue;
            }

            // A hierarchical archive passes the full path; the term is
            // the last segment.
            $slug = (string) substr(strrchr('/' . $slug, '/'), 1);

            $term = get_term_by('slug', $slug, $taxonomy->name);
            if ($term instanceof \WP_Term) {
                return array($term, ('category' === $taxonomy->name && !$query->get($var)) ? 'category_name' : $var);
            }
        }

        return null;
    }

    /**
     * @param string $where
     * @param mixed  $query
     */
    public static function restrict($where, $query = null): string
    {
        global $wpdb;

        if (!$query instanceof WP_Query || !Registry::is_multilingual() || !Schema::translations_installed()) {
            return (string) $where;
        }

        $requested = $query->get('meridian_language', null);
        if (null === $requested || false === $requested || '' === $requested) {
            return (string) $where;
        }

        $lang = ('current' === $requested) ? Request::code() : (string) $requested;
        if (!Registry::exists($lang)) {
            return (string) $where;
        }

        $types = self::separate_types();
        if (!$types) {
            return (string) $where;
        }

        $table = Schema::translations_table();
        $list = "'" . implode("','", array_map('esc_sql', $types)) . "'";

        // Scoped to the post types that are actually duplicated per
        // language. A search matches pages, posts and downloads at
        // once, and a download is one row shared by every language --
        // filtering it out of a Spanish search would hide the catalogue.
        if (Registry::is_default($lang)) {
            // The default language is also everything nobody has
            // assigned a language to, which on a site that just
            // installed this plugin is everything. That is what keeps
            // the English site identical.
            $clause = $wpdb->prepare(
                "( {$wpdb->posts}.post_type NOT IN ({$list})
                   OR {$wpdb->posts}.ID NOT IN (SELECT object_id FROM {$table} WHERE object_type = 'post')
                   OR {$wpdb->posts}.ID IN (SELECT object_id FROM {$table} WHERE object_type = 'post' AND lang = %s) )",
                $lang
            );
        } else {
            $clause = $wpdb->prepare(
                "( {$wpdb->posts}.post_type NOT IN ({$list})
                   OR {$wpdb->posts}.ID IN (SELECT object_id FROM {$table} WHERE object_type = 'post' AND lang = %s) )",
                $lang
            );
        }

        return $where . ' AND ' . $clause;
    }

    /**
     * Query arguments that restrict a custom query to one language.
     *
     * The documented helper. Merge it into a WP_Query's args:
     *
     *     new WP_Query( array( 'post_type' => 'page' ) + meridian_language_args() );
     *
     * @param string $lang 'current', or a language code.
     * @return array
     */
    public static function args(string $lang = 'current'): array
    {
        return array('meridian_language' => $lang);
    }

    /**
     * @return string[] Post types duplicated per language.
     */
    private static function separate_types(): array
    {
        static $types = null;

        if (null !== $types) {
            return $types;
        }

        $types = array();
        foreach (get_post_types(array(), 'names') as $post_type) {
            if (Modes::SEPARATE === Modes::for_post_type($post_type)) {
                $types[] = $post_type;
            }
        }

        return $types;
    }
}
