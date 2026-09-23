<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which language an item's terms are in.
 *
 * A product is one row in every language, so it is filed under the
 * source categories. The Spanish "Business" is that same category's
 * Spanish face, and it already lists the source's products because the
 * archive query is widened to the whole group (Queries::widen_term_query()).
 * Nothing stopped an editor ticking it as well, though. The product
 * screen listed both terms, both named "Business" and indistinguishable,
 * and two products ended up filed under both (found 23 September 2026).
 *
 * Three layers, because each one covers a gap in the one before it:
 *
 *  - Editing screens offer one term per translation group, in the
 *    language of the item being edited. For a product that is always
 *    the default language.
 *  - Saving swaps any term in the wrong language for its sibling in the
 *    item's language, whatever did the assigning: an old editor tab,
 *    the REST API, an import.
 *  - repair() fixes assignments made before either of those existed. It
 *    runs from Settings because a deploy here carries files and not the
 *    database, so production's existing assignments have to be put
 *    right on production.
 *
 * The Categories screen itself is untouched: it is where translations
 * are made and managed, and it shows every term.
 */
final class TermAssignment
{
    private static bool $saving = false;

    public static function register(): void
    {
        add_action('rest_api_init', array(self::class, 'hook_rest'));
        add_filter('get_terms_args', array(self::class, 'screen_args'), 10, 2);
        add_action('set_object_terms', array(self::class, 'on_set'), 10, 4);
    }

    public static function hook_rest(): void
    {
        if (!Registry::is_multilingual()) {
            return;
        }

        foreach (get_taxonomies(array('show_in_rest' => true)) as $taxonomy) {
            if (Modes::is_translated_taxonomy($taxonomy)) {
                add_filter("rest_{$taxonomy}_query", array(self::class, 'rest_args'), 10, 2);
            }
        }
    }

    /**
     * The block editor's category panel.
     *
     * That panel loads its list over REST, so a filter on the edit screen
     * never sees it. Only requests the editor makes on a person's behalf
     * are narrowed. apiFetch marks every one of those `_locale=user`, and
     * a public or API consumer of the taxonomy still gets every term, so
     * nothing the site publishes changes.
     *
     * An explicit `include` is left alone too: it asks for particular
     * terms by ID, such as the ones already assigned, and those must
     * come back even if they are in the wrong language.
     *
     * @param array                $args
     * @param WP_REST_Request|null $request
     * @return array
     */
    public static function rest_args($args, $request = null): array
    {
        $args = (array) $args;

        if (!$request instanceof WP_REST_Request || 'user' !== $request->get_param('_locale') || !empty($args['include'])) {
            return $args;
        }

        $taxonomy = (string) ($args['taxonomy'] ?? '');
        $object = get_taxonomy($taxonomy);
        if (!$object || !current_user_can($object->cap->assign_terms)) {
            return $args;
        }

        return self::limit($args, $taxonomy, self::language_for(self::referer_post_id()));
    }

    /**
     * The classic term checklist, and Quick and Bulk Edit.
     *
     * @param array $args
     * @param array $taxonomies
     * @return array
     */
    public static function screen_args($args, $taxonomies = array()): array
    {
        $args = (array) $args;

        if (!is_admin() || wp_doing_ajax() || !Registry::is_multilingual() || !empty($args['include'])) {
            return $args;
        }

        $taxonomies = array_values((array) $taxonomies);
        if (1 !== count($taxonomies) || !Modes::is_translated_taxonomy((string) $taxonomies[0])) {
            return $args;
        }
        $taxonomy = (string) $taxonomies[0];

        global $pagenow;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, to choose what a list shows.
        if ('post.php' === $pagenow) {
            return self::limit($args, $taxonomy, self::language_for(isset($_GET['post']) ? (int) $_GET['post'] : 0));
        }
        if ('post-new.php' === $pagenow) {
            // Anything new starts in the default language, and becomes a
            // translation only when it is linked to a group.
            return self::limit($args, $taxonomy, Registry::default_code());
        }
        if ('edit.php' === $pagenow) {
            // Quick and Bulk Edit share one checklist across the whole
            // table, so there is no single item to take a language from.
            // On a single-source type every row is the default language.
            // On a separate type the rows differ, so the list stays whole
            // and saving puts each row's terms right.
            $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
            if (Modes::is_single_source($type)) {
                return self::limit($args, $taxonomy, Registry::default_code());
            }
        }
        // phpcs:enable

        return $args;
    }

    /**
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     */
    public static function on_set($object_id, $terms = array(), $tt_ids = array(), $taxonomy = ''): void
    {
        if (!self::$saving) {
            self::reconcile((int) $object_id, (string) $taxonomy);
        }
    }

    /**
     * Put an item's terms in the item's own language.
     *
     * A term in another language becomes its sibling in the item's
     * language. Where there is none, it becomes its source. A term that
     * belongs to no group is the default language already and is kept.
     *
     * @return bool Whether anything changed.
     */
    public static function reconcile(int $object_id, string $taxonomy): bool
    {
        if (!Registry::is_multilingual() || !Modes::is_translated_taxonomy($taxonomy) || !get_post($object_id)) {
            return false;
        }

        $lang = self::language_for($object_id);
        $current = wp_get_object_terms($object_id, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($current) || !$current) {
            return false;
        }

        $wanted = array();
        foreach (array_map('intval', $current) as $id) {
            $target = Terms::language_of($id) === $lang ? $id : Terms::translation($id, $lang);
            if ($target < 1) {
                $source = Terms::translation($id, Registry::default_code());
                $target = $source > 0 ? $source : $id;
            }
            $wanted[] = $target;
        }

        $wanted = array_values(array_unique($wanted));
        $had = array_map('intval', $current);
        sort($wanted);
        sort($had);

        if ($wanted === $had) {
            return false;
        }

        self::$saving = true;
        wp_set_object_terms($object_id, $wanted, $taxonomy);
        self::$saving = false;

        return true;
    }

    /**
     * Reconcile every item filed under a translated term.
     *
     * Only items related to a term that belongs to a translation group
     * are looked at. A term in no group is in the default language, so
     * an item filed only under such terms cannot be wrong.
     *
     * @return int[] IDs of the items that changed.
     */
    public static function repair(): array
    {
        global $wpdb;

        if (!Schema::translations_installed()) {
            return array();
        }

        $table = Schema::translations_table();
        $changed = array();

        foreach (get_taxonomies() as $taxonomy) {
            if (!Modes::is_translated_taxonomy($taxonomy)) {
                continue;
            }

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                 INNER JOIN {$table} m ON m.object_id = tt.term_id AND m.object_type = %s",
                $taxonomy,
                Terms::TYPE
            ));

            foreach (array_map('intval', (array) $ids) as $id) {
                if (self::reconcile($id, $taxonomy)) {
                    $changed[$id] = $id;
                }
            }
        }

        return array_values($changed);
    }

    /**
     * The language an item's terms belong in.
     *
     * A post translated as separate rows is in its own language. Anything
     * else, whether single-source or not translated at all, is one row
     * serving the default language.
     */
    private static function language_for(int $post_id): string
    {
        if ($post_id > 0 && Modes::SEPARATE === Modes::for_post_type((string) get_post_type($post_id))) {
            return Posts::language_of($post_id);
        }

        return Registry::default_code();
    }

    /**
     * The post the block editor is editing, read from the page that made
     * the request. The REST request itself carries no post ID.
     */
    private static function referer_post_id(): int
    {
        $referer = (string) wp_get_raw_referer();
        if ('' === $referer || '/wp-admin/post.php' !== substr((string) wp_parse_url($referer, PHP_URL_PATH), -strlen('/wp-admin/post.php'))) {
            return 0;
        }

        parse_str((string) wp_parse_url($referer, PHP_URL_QUERY), $query);
        $id = isset($query['post']) ? (int) $query['post'] : 0;

        return ($id > 0 && current_user_can('edit_post', $id)) ? $id : 0;
    }

    /**
     * @return array $args with the terms not offered in $lang excluded.
     */
    private static function limit(array $args, string $taxonomy, string $lang): array
    {
        $hidden = Terms::hidden_for($taxonomy, $lang);

        if ($hidden) {
            $args['exclude'] = array_values(array_unique(array_merge(wp_parse_id_list($args['exclude'] ?? array()), $hidden)));
        }

        return $args;
    }
}
