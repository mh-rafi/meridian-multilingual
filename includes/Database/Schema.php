<?php

namespace Meridian\Multilingual\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's own tables. (spec 5.5)
 */
final class Schema
{
    const VERSION = '1.2.0';
    const OPTION = 'meridian_db_version';

    public static function fields_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meridian_fields';
    }

    public static function translations_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meridian_translations';
    }

    public static function strings_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'meridian_strings';
    }

    /**
     * Create or update the tables, if anything changed.
     */
    public static function install(): void
    {
        if (get_option(self::OPTION) === self::VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $fields = self::fields_table();

        // Translated values for objects that are NOT duplicated per
        // language -- downloads today (spec 5.2). object_id is always
        // the source, which is the invariant the whole storage model
        // rests on: every commerce operation resolves to one ID.
        //
        // 'field' is any meta key, including a repeater's
        // 'product_modules_2_text' (spec 5.6), so it is far wider than
        // the title/content/excerpt/slug the first draft assumed.
        //
        // origin, reviewed_at and source_hash are M13's, and they are
        // here from the first row rather than added later: retrofitting
        // provenance into a table that already holds a thousand rows
        // means guessing which of them a person approved.
        dbDelta("CREATE TABLE {$fields} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(20) NOT NULL DEFAULT 'post',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            lang varchar(10) NOT NULL DEFAULT '',
            field varchar(191) NOT NULL DEFAULT '',
            value longtext NULL,
            origin varchar(20) NOT NULL DEFAULT 'human',
            reviewed_at datetime NULL DEFAULT NULL,
            source_hash char(32) NULL DEFAULT NULL,
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY object_field (object_type,object_id,lang,field),
            KEY lang_field (lang,field(64)),
            KEY object_lang (object_type,object_id,lang)
        ) {$charset};");

        $translations = self::translations_table();

        // Objects sharing a group_id are translations of one another
        // (M4). A group has no privileged member in the schema -- no
        // "source" column -- which is what keeps "change the default
        // language" from being a migration rather than a setting.
        //
        // UNIQUE on (object_type, object_id) because an object belongs
        // to exactly one group in exactly one language. That constraint
        // is the whole integrity model: it makes "this page is the
        // Spanish one" a fact rather than a convention.
        dbDelta("CREATE TABLE {$translations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(20) NOT NULL DEFAULT 'post',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            lang varchar(10) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY object (object_type,object_id),
            KEY group_lang (group_id,lang),
            KEY lang_type (lang,object_type)
        ) {$charset};");

        $strings = self::strings_table();

        // Text that is not in a PHP file and so cannot be gettext: a
        // label somebody typed into a settings screen, an option's
        // value (M10). Keyed on a hash of the source rather than on the
        // source, because a source string can be a paragraph and an
        // index cannot be.
        dbDelta("CREATE TABLE {$strings} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            context varchar(120) NOT NULL DEFAULT '',
            source longtext NULL,
            source_hash char(32) NOT NULL DEFAULT '',
            lang varchar(10) NOT NULL DEFAULT '',
            translation longtext NULL,
            origin varchar(20) NOT NULL DEFAULT 'human',
            reviewed_at datetime NULL DEFAULT NULL,
            updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY string_lang (context,source_hash,lang),
            KEY lang_reviewed (lang,reviewed_at)
        ) {$charset};");

        update_option(self::OPTION, self::VERSION);
    }

    public static function installed(): bool
    {
        return self::table_exists(self::fields_table());
    }

    public static function translations_installed(): bool
    {
        return self::table_exists(self::translations_table());
    }

    public static function strings_installed(): bool
    {
        return self::table_exists(self::strings_table());
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }
}
