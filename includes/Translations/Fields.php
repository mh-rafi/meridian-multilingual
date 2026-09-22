<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translated values for objects that are never duplicated. (M5 storage,
 * M13 provenance)
 *
 * The only thing that writes to meridian_fields. The admin form calls
 * set(); a future machine-translation pass calls the same method with a
 * different origin. That is the whole reason this is a service and not
 * a pair of $wpdb calls at each call site -- two write paths means two
 * places to remember the rules, and the rule that matters is that
 * machine output must never quietly replace something a person wrote.
 */
final class Fields
{
    const HUMAN = 'human';
    const MACHINE = 'machine';
    const IMPORTED = 'imported';

    /** object_type:object_id:lang => field => row */
    private static array $cache = array();

    /**
     * One translated value, or null when there is none.
     */
    public static function get(int $object_id, string $lang, string $field, string $object_type = 'post'): ?string
    {
        $row = self::row($object_id, $lang, $field, $object_type);

        if (null === $row) {
            return null;
        }

        // Unreviewed machine output is not shown unless the site says
        // so, and the default is no (M13). A store shipping machine copy
        // about what a customer is buying has a different problem from a
        // blog.
        if (self::MACHINE === $row['origin'] && null === $row['reviewed_at'] && !self::show_unreviewed()) {
            return null;
        }

        return (string) $row['value'];
    }

    /**
     * One translated value for an editing screen, or null when there is
     * none -- unlike get(), never hidden by the unreviewed-machine gate.
     *
     * get() protects visitors from unreviewed machine copy; it must not
     * also hide that same copy from the person meant to review it, or a
     * translation form renders blank for a value that still exists,
     * looking exactly like an empty field. Submitting that form then
     * writes an empty string over a real row, deleting it. Reviewing
     * needs the real stored value every time; is_unreviewed() below is
     * how a caller flags it as unapproved without hiding it.
     */
    public static function get_for_editor(int $object_id, string $lang, string $field, string $object_type = 'post'): ?string
    {
        $row = self::row($object_id, $lang, $field, $object_type);

        return null === $row ? null : (string) $row['value'];
    }

    /**
     * Whether a value is machine output nobody has approved yet, so an
     * editing screen can flag it instead of presenting it as if a
     * person already reviewed it.
     */
    public static function is_unreviewed(int $object_id, string $lang, string $field, string $object_type = 'post'): bool
    {
        $row = self::row($object_id, $lang, $field, $object_type);

        return null !== $row && self::MACHINE === $row['origin'] && null === $row['reviewed_at'];
    }

    /**
     * Every translated value for one object in one language.
     *
     * @return array field => value
     */
    public static function all(int $object_id, string $lang, string $object_type = 'post'): array
    {
        $rows = self::rows($object_id, $lang, $object_type);
        $out = array();

        foreach ($rows as $field => $row) {
            if (self::MACHINE === $row['origin'] && null === $row['reviewed_at'] && !self::show_unreviewed()) {
                continue;
            }
            $out[$field] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * Write one translated value.
     *
     * @param string $source The current default-language value, stored as a
     *                       hash so that a later change to it marks this
     *                       translation stale rather than leaving it
     *                       silently wrong (M13).
     * @return true|WP_Error
     */
    public static function set(int $object_id, string $lang, string $field, string $value, array $args = array())
    {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'object_type' => 'post',
            'origin'      => self::HUMAN,
            'source'      => null,
            'reviewed'    => null,
            'replace'     => false,
        ));

        if ($object_id < 1 || '' === $field) {
            return new WP_Error('meridian_bad_field_target', __('A translation needs an object and a field.', 'meridian'));
        }
        if (!Registry::exists($lang)) {
            return new WP_Error('meridian_unknown_language', __('That language is not configured.', 'meridian'));
        }
        if (Registry::is_default($lang)) {
            // The default language is the source. Writing it here would
            // create a second copy of the truth, and the two would drift.
            return new WP_Error('meridian_default_language', __('The default language is stored on the post itself, not as a translation.', 'meridian'));
        }
        if (!Schema::installed()) {
            return new WP_Error('meridian_no_table', __('Meridian’s tables are missing. Deactivate and reactivate the plugin.', 'meridian'));
        }

        $origin = in_array($args['origin'], array(self::HUMAN, self::MACHINE, self::IMPORTED), true) ? $args['origin'] : self::HUMAN;

        $existing = self::row($object_id, $lang, $field, $args['object_type']);

        // A machine translation never silently overwrites a human one.
        // Refused rather than skipped, so a bulk pass can report what it
        // did not touch instead of looking like it succeeded.
        if ($existing && self::MACHINE === $origin && !$args['replace']) {
            if (self::HUMAN === $existing['origin'] || null !== $existing['reviewed_at']) {
                return new WP_Error('meridian_would_overwrite_human', sprintf(
                    /* translators: %s: the field that was not overwritten. */
                    __('"%s" already has a translation a person wrote or approved. Pass replace to overwrite it.', 'meridian'),
                    $field
                ));
            }
        }

        $reviewed = $args['reviewed'];
        if (null === $reviewed) {
            // Human writes are reviewed by definition -- somebody typed
            // them. Machine writes are not, until somebody says so.
            $reviewed = self::HUMAN === $origin ? current_time('mysql', true) : null;
        }

        $row = array(
            'object_type' => (string) $args['object_type'],
            'object_id'   => $object_id,
            'lang'        => $lang,
            'field'       => $field,
            'value'       => $value,
            'origin'      => $origin,
            'reviewed_at' => $reviewed,
            'source_hash' => null === $args['source'] ? null : md5((string) $args['source']),
            'updated'     => current_time('mysql', true),
        );

        $formats = array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        if ($existing) {
            $wpdb->update(Schema::fields_table(), $row, array('id' => (int) $existing['id']), $formats, array('%d'));
        } else {
            $wpdb->insert(Schema::fields_table(), $row, $formats);
        }

        self::flush();

        /**
         * Fires after a translated value is written.
         *
         * @param int    $object_id
         * @param string $lang
         * @param string $field
         * @param string $value
         * @param array  $args
         */
        do_action('meridian_field_saved', $object_id, $lang, $field, $value, $args);

        return true;
    }

    public static function delete(int $object_id, string $lang, string $field, string $object_type = 'post'): bool
    {
        global $wpdb;

        if (!Schema::installed()) {
            return false;
        }

        $deleted = $wpdb->delete(Schema::fields_table(), array(
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'lang'        => $lang,
            'field'       => $field,
        ), array('%s', '%d', '%s', '%s'));

        self::flush();

        return (bool) $deleted;
    }

    /**
     * Whether a translation was written against a different source
     * value than the one there now.
     *
     * The failure mode that makes multilingual sites rot: the English
     * changes, the Spanish does not, and nothing says so.
     */
    public static function is_stale(int $object_id, string $lang, string $field, string $source, string $object_type = 'post'): bool
    {
        $row = self::row($object_id, $lang, $field, $object_type);

        if (!$row || null === $row['source_hash']) {
            return false;
        }

        return $row['source_hash'] !== md5($source);
    }

    /**
     * The object that holds a given translated value, or 0.
     *
     * Used by the router to turn a translated slug back into the post
     * it belongs to.
     */
    public static function find(string $lang, string $field, string $value, string $object_type = 'post'): int
    {
        global $wpdb;

        if (!Schema::installed() || '' === $value) {
            return 0;
        }

        $table = Schema::fields_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT object_id FROM {$table} WHERE object_type = %s AND lang = %s AND field = %s AND value = %s LIMIT 1",
            $object_type,
            $lang,
            $field,
            $value
        ));
    }

    /**
     * @return array|null
     */
    private static function row(int $object_id, string $lang, string $field, string $object_type)
    {
        $rows = self::rows($object_id, $lang, $object_type);
        return $rows[$field] ?? null;
    }

    /**
     * Every row for one object in one language, in one query.
     *
     * A product page reads dozens of fields; asking per field turns one
     * page into dozens of queries.
     */
    private static function rows(int $object_id, string $lang, string $object_type): array
    {
        $key = $object_type . ':' . $object_id . ':' . $lang;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (!Schema::installed() || $object_id < 1 || '' === $lang) {
            return self::$cache[$key] = array();
        }

        global $wpdb;
        $table = Schema::fields_table();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, field, value, origin, reviewed_at, source_hash FROM {$table} WHERE object_type = %s AND object_id = %d AND lang = %s",
            $object_type,
            $object_id,
            $lang
        ), ARRAY_A);

        $rows = array();
        foreach ((array) $results as $result) {
            $rows[$result['field']] = $result;
        }

        return self::$cache[$key] = $rows;
    }

    private static function show_unreviewed(): bool
    {
        /**
         * Filter whether unreviewed machine translations are served.
         *
         * @param bool $show
         */
        return (bool) apply_filters('meridian_show_unreviewed_translations', false);
    }

    public static function flush(): void
    {
        self::$cache = array();
    }
}
