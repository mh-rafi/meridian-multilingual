<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which objects are translations of one another. (M4)
 *
 * A group is a set of rows sharing a group_id, one per language. No row
 * is privileged: there is no "source" column, so the default language is
 * a property of the site rather than of the group, and changing it is a
 * setting rather than a migration.
 *
 * An object with no row is in the default language and has no
 * translations. That is the state of every post on a site that just
 * installed this plugin, and it is why installing it changes nothing.
 */
final class Groups
{
    /** object_type:object_id => array{group:int,lang:string}|false */
    private static array $cache = array();

    public static function register(): void
    {
        // Deleted, not trashed. A trashed post can be restored, and
        // Posts::id() already refuses to treat a non-published row as a
        // translation, so a trashed translation is correctly invisible
        // while it is still recoverable -- forgetting the link there
        // would turn restoring it into an orphan instead of a
        // translation.
        add_action('deleted_post', array(self::class, 'forget_post'));
        add_action('delete_term', array(self::class, 'forget_term'));
    }

    /**
     * @param int $post_id
     */
    public static function forget_post($post_id): void
    {
        self::unlink((int) $post_id, 'post');
    }

    /**
     * @param int $term_id
     */
    public static function forget_term($term_id): void
    {
        self::unlink((int) $term_id, 'term');
    }

    /**
     * The group an object belongs to, or 0.
     */
    public static function group_of(int $object_id, string $object_type = 'post'): int
    {
        $row = self::row($object_id, $object_type);
        return $row ? $row['group'] : 0;
    }

    /**
     * The language an object is written in.
     *
     * The default language when it is in no group -- untranslated
     * content is the site's own language, not an error.
     */
    public static function language_of(int $object_id, string $object_type = 'post'): string
    {
        $row = self::row($object_id, $object_type);
        $lang = $row ? $row['lang'] : '';

        return ($lang && Registry::exists($lang)) ? $lang : Registry::default_code();
    }

    /**
     * Every object in this one's group, including itself.
     *
     * @return array lang => object_id
     */
    public static function siblings(int $object_id, string $object_type = 'post'): array
    {
        $group = self::group_of($object_id, $object_type);
        if ($group < 1) {
            return array(self::language_of($object_id, $object_type) => $object_id);
        }

        return self::members($group, $object_type);
    }

    /**
     * @return array lang => object_id
     */
    public static function members(int $group_id, string $object_type = 'post'): array
    {
        global $wpdb;

        if ($group_id < 1 || !Schema::translations_installed()) {
            return array();
        }

        $table = Schema::translations_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lang, object_id FROM {$table} WHERE group_id = %d AND object_type = %s",
            $group_id,
            $object_type
        ), ARRAY_A);

        $members = array();
        foreach ((array) $rows as $row) {
            $object_id = (int) $row['object_id'];

            // A row whose object no longer exists is not a translation.
            // Until deletion was hooked (register() above) every deleted
            // translation left one of these behind, and because a group
            // holds one object per language, the stale row went on
            // claiming that language for ever: link() answered
            // "this set already has a Spanish version" and
            // Duplicator::create() refused to make a replacement, with
            // nothing in the admin able to clear it.
            //
            // Skipped on read rather than migrated away, because a
            // deploy here carries files and not the database -- the
            // rows already sitting in production have to heal
            // themselves.
            if (!self::object_exists($object_id, $object_type)) {
                continue;
            }

            $members[$row['lang']] = $object_id;
        }

        return $members;
    }

    /**
     * Whether a group row still points at something real.
     */
    private static function object_exists(int $object_id, string $object_type): bool
    {
        if ($object_id < 1) {
            return false;
        }

        if ('term' === $object_type) {
            $term = get_term($object_id);
            return $term instanceof \WP_Term;
        }

        return null !== get_post($object_id);
    }

    /**
     * The object's translation in one language, or 0.
     */
    public static function translation(int $object_id, string $lang, string $object_type = 'post'): int
    {
        $siblings = self::siblings($object_id, $object_type);
        return (int) ($siblings[$lang] ?? 0);
    }

    /**
     * Put an object in a group, in a language.
     *
     * Passing no group starts one. Passing a group an object of that
     * language is already in is refused: two Spanish pages in one group
     * makes "the Spanish version of this" ambiguous, and every caller
     * downstream assumes it is not.
     *
     * @return int|\WP_Error The group id.
     */
    public static function link(int $object_id, string $lang, int $group_id = 0, string $object_type = 'post')
    {
        global $wpdb;

        if ($object_id < 1) {
            return new \WP_Error('meridian_bad_object', __('Nothing to link.', 'meridian'));
        }
        if (!Registry::exists($lang)) {
            return new \WP_Error('meridian_unknown_language', __('That language is not configured.', 'meridian'));
        }
        if (!Schema::translations_installed()) {
            return new \WP_Error('meridian_no_table', __('Meridian’s tables are missing. Deactivate and reactivate the plugin.', 'meridian'));
        }

        if ($group_id > 0) {
            $members = self::members($group_id, $object_type);
            if (isset($members[$lang]) && $members[$lang] !== $object_id) {
                return new \WP_Error('meridian_language_taken', sprintf(
                    /* translators: %s: the language that already has a translation in this group. */
                    __('This set already has a %s version.', 'meridian'),
                    Registry::get($lang)?->label() ?? $lang
                ));
            }
        } else {
            $group_id = self::next_group_id();
        }

        $table = Schema::translations_table();
        $existing = self::row($object_id, $object_type);

        if ($existing) {
            $wpdb->update($table, array('group_id' => $group_id, 'lang' => $lang), array('object_type' => $object_type, 'object_id' => $object_id), array('%d', '%s'), array('%s', '%d'));
        } else {
            $wpdb->insert($table, array('group_id' => $group_id, 'object_type' => $object_type, 'object_id' => $object_id, 'lang' => $lang), array('%d', '%s', '%d', '%s'));
        }

        self::flush();

        do_action('meridian_object_linked', $object_id, $lang, $group_id, $object_type);

        return $group_id;
    }

    /**
     * Take an object out of its group.
     *
     * The last member's group disappears with it; a group of one is the
     * same thing as no group, and leaving it behind would slowly fill
     * the table with sets nobody is in.
     */
    public static function unlink(int $object_id, string $object_type = 'post'): bool
    {
        global $wpdb;

        if (!Schema::translations_installed()) {
            return false;
        }

        $group = self::group_of($object_id, $object_type);

        $deleted = $wpdb->delete(Schema::translations_table(), array(
            'object_type' => $object_type,
            'object_id'   => $object_id,
        ), array('%s', '%d'));

        self::flush();

        if ($group > 0 && count(self::members($group, $object_type)) < 2) {
            $wpdb->delete(Schema::translations_table(), array('group_id' => $group, 'object_type' => $object_type), array('%d', '%s'));
            self::flush();
        }

        return (bool) $deleted;
    }

    /**
     * Object IDs in one language, for a whole post type.
     *
     * @return int[]
     */
    public static function ids_in_language(string $lang, string $object_type = 'post'): array
    {
        global $wpdb;

        if (!Schema::translations_installed()) {
            return array();
        }

        $table = Schema::translations_table();

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$table} WHERE lang = %s AND object_type = %s",
            $lang,
            $object_type
        )));
    }

    private static function next_group_id(): int
    {
        global $wpdb;
        $table = Schema::translations_table();

        return 1 + (int) $wpdb->get_var("SELECT COALESCE(MAX(group_id), 0) FROM {$table}");
    }

    /**
     * @return array{group:int,lang:string}|null
     */
    private static function row(int $object_id, string $object_type)
    {
        $key = $object_type . ':' . $object_id;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?: null;
        }

        if ($object_id < 1 || !Schema::translations_installed()) {
            self::$cache[$key] = false;
            return null;
        }

        global $wpdb;
        $table = Schema::translations_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT group_id, lang FROM {$table} WHERE object_type = %s AND object_id = %d",
            $object_type,
            $object_id
        ), ARRAY_A);

        if (!$row) {
            self::$cache[$key] = false;
            return null;
        }

        self::$cache[$key] = array('group' => (int) $row['group_id'], 'lang' => (string) $row['lang']);

        return self::$cache[$key];
    }

    public static function flush(): void
    {
        self::$cache = array();
        Posts::flush();
    }
}
