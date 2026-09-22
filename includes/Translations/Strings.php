<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Request;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Text that is not in a PHP file. (M10)
 *
 * M0 covered everything gettext can reach. What is left is
 * site-specific text: a label typed into a settings screen, an option's
 * value, a string a theme lets an editor change. gettext cannot see it,
 * because it was not there when the .pot was built.
 *
 * Because deploys here are files only, the screen on top of this is the
 * only way these translations reach production. It is a shipping
 * surface, not a debugging tool.
 */
final class Strings
{
    /** @var array<string,array<string,string>> lang => hash => translation */
    private static array $cache = array();

    /** @var array<string,array{context:string,source:string}> hash => registration */
    private static array $seen = array();

    /**
     * Translate a string, registering it so it appears on the screen.
     *
     * The registration is the point: a string nobody can see is a
     * string nobody translates. Calling this is what puts it on the
     * list, so the list is built from what the site actually renders
     * rather than from somebody remembering to add it.
     */
    public static function get(string $source, string $context = '', string $lang = ''): string
    {
        if ('' === trim($source)) {
            return $source;
        }

        $hash = md5($source);
        self::$seen[$context . '|' . $hash] = array('context' => $context, 'source' => $source);

        $lang = '' !== $lang ? $lang : Request::code();

        if (Registry::is_default($lang) || !Registry::exists($lang)) {
            return $source;
        }

        $translations = self::for_language($lang);
        $key = $context . '|' . $hash;

        return isset($translations[$key]) && '' !== $translations[$key] ? $translations[$key] : $source;
    }

    /**
     * Register a string without translating it now.
     *
     * For strings a site knows about at load time and wants on the
     * screen before anybody visits the page that renders them.
     */
    public static function register(string $source, string $context = ''): void
    {
        if ('' !== trim($source)) {
            self::$seen[$context . '|' . md5($source)] = array('context' => $context, 'source' => $source);
        }
    }

    /**
     * Persist everything seen this request, so the screen can list it.
     *
     * Rows are created with a null translation, which is what "not
     * translated yet" looks like and what the screen's filter finds.
     */
    public static function flush_seen(): void
    {
        global $wpdb;

        if (!self::$seen || !Schema::strings_installed() || !Registry::is_multilingual()) {
            return;
        }

        $table = Schema::strings_table();
        $languages = array_diff(Registry::codes(), array(Registry::default_code()));

        foreach (self::$seen as $key => $entry) {
            $hash = md5($entry['source']);

            foreach ($languages as $lang) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE context = %s AND source_hash = %s AND lang = %s",
                    $entry['context'],
                    $hash,
                    $lang
                ));

                if ($exists) {
                    continue;
                }

                $wpdb->insert($table, array(
                    'context'     => $entry['context'],
                    'source'      => $entry['source'],
                    'source_hash' => $hash,
                    'lang'        => $lang,
                    'translation' => null,
                    'origin'      => Fields::HUMAN,
                    'reviewed_at' => null,
                    'updated'     => current_time('mysql', true),
                ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
            }
        }

        self::$seen = array();
    }

    /**
     * Write a translation. The one write path, as M13 requires.
     *
     * @return true|WP_Error
     */
    public static function set(string $source, string $lang, string $translation, array $args = array())
    {
        global $wpdb;

        $args = wp_parse_args($args, array('context' => '', 'origin' => Fields::HUMAN, 'replace' => false));

        if (!Registry::exists($lang) || Registry::is_default($lang)) {
            return new WP_Error('meridian_bad_language', __('Pick a language other than the default one.', 'meridian'));
        }
        if (!Schema::strings_installed()) {
            return new WP_Error('meridian_no_table', __('Meridian’s tables are missing. Deactivate and reactivate the plugin.', 'meridian'));
        }

        $origin = in_array($args['origin'], array(Fields::HUMAN, Fields::MACHINE, Fields::IMPORTED), true) ? $args['origin'] : Fields::HUMAN;
        $hash = md5($source);
        $table = Schema::strings_table();

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, origin, reviewed_at, translation FROM {$table} WHERE context = %s AND source_hash = %s AND lang = %s",
            $args['context'],
            $hash,
            $lang
        ), ARRAY_A);

        // Same rule as the field table: machine output never silently
        // replaces something a person wrote or approved.
        if ($existing && Fields::MACHINE === $origin && !$args['replace']
            && (Fields::HUMAN === $existing['origin'] || null !== $existing['reviewed_at'])
            && null !== $existing['translation']) {
            return new WP_Error('meridian_would_overwrite_human', __('That string already has a translation a person wrote or approved.', 'meridian'));
        }

        $row = array(
            'context'     => (string) $args['context'],
            'source'      => $source,
            'source_hash' => $hash,
            'lang'        => $lang,
            'translation' => '' === $translation ? null : $translation,
            'origin'      => $origin,
            'reviewed_at' => Fields::HUMAN === $origin ? current_time('mysql', true) : null,
            'updated'     => current_time('mysql', true),
        );

        if ($existing) {
            $wpdb->update($table, $row, array('id' => (int) $existing['id']));
        } else {
            $wpdb->insert($table, $row);
        }

        self::$cache = array();

        return true;
    }

    /**
     * Every stored string for a language.
     *
     * @return array rows
     */
    public static function all(string $lang, bool $untranslated_only = false): array
    {
        global $wpdb;

        if (!Schema::strings_installed()) {
            return array();
        }

        $table = Schema::strings_table();
        $sql = "SELECT * FROM {$table} WHERE lang = %s";
        if ($untranslated_only) {
            $sql .= " AND (translation IS NULL OR translation = '')";
        }
        $sql .= ' ORDER BY context ASC, id ASC';

        return (array) $wpdb->get_results($wpdb->prepare($sql, $lang), ARRAY_A);
    }

    public static function count_untranslated(string $lang): int
    {
        global $wpdb;

        if (!Schema::strings_installed()) {
            return 0;
        }

        $table = Schema::strings_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE lang = %s AND (translation IS NULL OR translation = '')",
            $lang
        ));
    }

    /**
     * @return array key => translation
     */
    private static function for_language(string $lang): array
    {
        if (isset(self::$cache[$lang])) {
            return self::$cache[$lang];
        }

        if (!Schema::strings_installed()) {
            return self::$cache[$lang] = array();
        }

        global $wpdb;
        $table = Schema::strings_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT context, source_hash, translation, origin, reviewed_at FROM {$table} WHERE lang = %s AND translation IS NOT NULL",
            $lang
        ), ARRAY_A);

        $out = array();
        foreach ((array) $rows as $row) {
            if (Fields::MACHINE === $row['origin'] && null === $row['reviewed_at']
                && !apply_filters('meridian_show_unreviewed_translations', false)) {
                continue;
            }
            $out[$row['context'] . '|' . $row['source_hash']] = (string) $row['translation'];
        }

        return self::$cache[$lang] = $out;
    }
}
