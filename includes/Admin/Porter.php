<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export and import everything, as JSON. (M12)
 *
 * The production database is never replaced from local, so every
 * translation is entered on the live site by hand. That makes a backup
 * of them something the site owner owns rather than something the
 * hosting provider might have.
 *
 * Objects are exported by ID, which is honest about the limitation:
 * this moves translations between copies of the same site, not between
 * different sites.
 */
final class Porter
{
    const NONCE = 'meridian_port';

    public function __construct()
    {
        add_action('admin_post_meridian_export', array($this, 'export'));
        add_action('admin_post_meridian_import', array($this, 'import'));
    }

    public function export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'meridian'));
        }
        check_admin_referer(self::NONCE);

        global $wpdb;

        $payload = array(
            'meridian' => MERIDIAN_VERSION,
            'exported' => gmdate('c'),
            'home'     => home_url('/'),
            'settings' => get_option(Registry::OPTION, array()),
        );

        foreach (array(
            'fields'       => Schema::fields_table(),
            'translations' => Schema::translations_table(),
            'strings'      => Schema::strings_table(),
        ) as $key => $table) {
            $payload[$key] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) ?: array();
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=meridian-' . gmdate('Y-m-d') . '.json');

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'meridian'));
        }
        check_admin_referer(self::NONCE);

        $error = '';
        $counts = array('fields' => 0, 'translations' => 0, 'strings' => 0);

        if (empty($_FILES['meridian_import']['tmp_name'])) {
            $error = __('Choose a file to import.', 'meridian');
        } else {
            $raw = file_get_contents((string) $_FILES['meridian_import']['tmp_name']);
            $data = json_decode((string) $raw, true);

            if (!is_array($data) || !isset($data['meridian'])) {
                $error = __('That does not look like a Meridian export.', 'meridian');
            } else {
                $counts = $this->restore($data);
            }
        }

        wp_safe_redirect(add_query_arg(array_filter(array(
            'page'             => SettingsScreen::SLUG,
            'meridian_error'   => $error ? rawurlencode($error) : null,
            'meridian_imported' => $error ? null : array_sum($counts),
        )), admin_url('admin.php')));
        exit;
    }

    /**
     * @param array $data
     * @return array
     */
    private function restore(array $data): array
    {
        global $wpdb;

        $counts = array('fields' => 0, 'translations' => 0, 'strings' => 0);

        if (isset($data['settings']) && is_array($data['settings'])) {
            update_option(Registry::OPTION, $data['settings']);
            Registry::flush();
        }

        $tables = array(
            'fields'       => Schema::fields_table(),
            'translations' => Schema::translations_table(),
            'strings'      => Schema::strings_table(),
        );

        foreach ($tables as $key => $table) {
            if (empty($data[$key]) || !is_array($data[$key])) {
                continue;
            }

            foreach ($data[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                // The id is the exporting site's, not this one's.
                unset($row['id']);

                // REPLACE rather than INSERT: every one of these tables
                // has a unique key on what identifies the row, so an
                // import over an existing set updates rather than
                // duplicating or failing halfway.
                $wpdb->replace($table, $row);
                $counts[$key]++;
            }
        }

        Fields::flush();

        return $counts;
    }
}
