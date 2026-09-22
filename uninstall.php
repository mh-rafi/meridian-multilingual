<?php

/**
 * Uninstall. (M12)
 *
 * Keeps everything by default. Deleting a site's translations because
 * somebody deactivated a plugin to test something is not a tidy-up, it
 * is data loss with no undo -- and the translations are the expensive
 * part, not the code. The delete happens only when the setting below
 * was explicitly turned on first.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('meridian_settings', array());

if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;

foreach (array('meridian_fields', 'meridian_translations', 'meridian_strings') as $table) {
    $name = $wpdb->prefix . $table;
    $wpdb->query("DROP TABLE IF EXISTS {$name}");
}

delete_option('meridian_settings');
delete_option('meridian_db_version');
