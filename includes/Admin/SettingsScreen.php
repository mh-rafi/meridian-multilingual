<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Functional;
use Meridian\Multilingual\Translations\Modes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meridian → Settings.
 *
 * Three settings that are site configuration rather than plugin
 * behaviour, and each of which is a wrong answer away from a broken
 * site. Deploys here are files only, so a setting with no screen is a
 * setting that cannot reach production at all.
 */
final class SettingsScreen
{
    const SLUG = 'meridian-settings';
    const NONCE = 'meridian_save_settings';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register'), 11);
        add_action('admin_post_meridian_save_settings', array($this, 'save'));
    }

    public function register(): void
    {
        add_submenu_page(
            LanguagesScreen::SLUG,
            __('Settings', 'meridian'),
            __('Settings', 'meridian'),
            'manage_options',
            self::SLUG,
            array($this, 'render')
        );
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'meridian'));
        }
        check_admin_referer(self::NONCE);

        $shared = isset($_POST['meridian_shared_pages']) && is_array($_POST['meridian_shared_pages'])
            ? array_map('absint', wp_unslash($_POST['meridian_shared_pages']))
            : array();
        Functional::save_stored_ids($shared);

        $settings = get_option(Registry::OPTION, array());
        $settings = is_array($settings) ? $settings : array();

        $raw_paths = isset($_POST['meridian_excluded_paths']) ? (string) wp_unslash($_POST['meridian_excluded_paths']) : '';
        $paths = array();
        foreach (preg_split('/[\r\n]+/', $raw_paths) as $line) {
            $line = trim(sanitize_text_field($line), " \t/");
            if ('' !== $line) {
                $paths[] = $line;
            }
        }
        $settings['excluded_paths'] = array_values(array_unique($paths));

        $modes = isset($_POST['meridian_modes']) && is_array($_POST['meridian_modes']) ? wp_unslash($_POST['meridian_modes']) : array();
        $clean = array();
        foreach ($modes as $post_type => $mode) {
            $mode = sanitize_text_field((string) $mode);
            if (in_array($mode, array(Modes::SEPARATE, Modes::SINGLE, Modes::NONE), true)) {
                $clean[sanitize_key($post_type)] = $mode;
            }
        }
        $settings['post_type_modes'] = $clean;

        $tax_modes = isset($_POST['meridian_tax_modes']) && is_array($_POST['meridian_tax_modes']) ? wp_unslash($_POST['meridian_tax_modes']) : array();
        $tax_clean = array();
        foreach ($tax_modes as $taxonomy => $mode) {
            $mode = sanitize_text_field((string) $mode);
            if (in_array($mode, array(Modes::SEPARATE, Modes::NONE), true)) {
                $tax_clean[sanitize_key($taxonomy)] = $mode;
            }
        }
        $settings['taxonomy_modes'] = $tax_clean;
        $settings['delete_data_on_uninstall'] = !empty($_POST['meridian_delete_on_uninstall']);
        $settings['show_unreviewed'] = !empty($_POST['meridian_show_unreviewed']);

        update_option(Registry::OPTION, $settings);

        wp_safe_redirect(add_query_arg(array('page' => self::SLUG, 'meridian_saved' => 1), admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option(Registry::OPTION, array());
        $paths = isset($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
        $stored_shared = Functional::stored_ids();
        $detected = Functional::all();
?>
        <div class="wrap">
            <h1><?php esc_html_e('Meridian settings', 'meridian'); ?></h1>

            <?php if (!empty($_GET['meridian_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'meridian'); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['meridian_error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['meridian_error']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['meridian_imported'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    printf(
                        /* translators: %d: how many rows were imported. */
                        esc_html(_n('%d row imported.', '%d rows imported.', (int) $_GET['meridian_imported'], 'meridian')),
                        (int) $_GET['meridian_imported']
                    );
                ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="meridian_save_settings">
                <?php wp_nonce_field(self::NONCE); ?>

                <h2><?php esc_html_e('Shared pages', 'meridian'); ?></h2>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('Pages that are the same page in every language. They are never duplicated — a plugin that finds a page by ID would stop recognising a copy — but they are reachable under every language prefix, and what a visitor reads is their shortcodes’ output.', 'meridian'); ?>
                </p>

                <?php if ($detected) : ?>
                    <p class="description"><strong><?php esc_html_e('Detected automatically:', 'meridian'); ?></strong></p>
                    <ul style="margin:0 0 12px 1em; list-style:disc">
                        <?php foreach ($detected as $id => $reason) : ?>
                            <?php if (in_array((int) $id, $stored_shared, true)) { continue; } ?>
                            <li><?php printf('%s — <span class="description">%s</span>', esc_html(get_the_title($id) ?: '#' . $id), esc_html($reason)); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="description"><strong><?php esc_html_e('Also treat these pages as shared:', 'meridian'); ?></strong></p>
                <div style="max-height:18em; overflow:auto; border:1px solid #dcdcde; padding:8px 12px; background:#fff; max-width:46em">
                    <?php
                    foreach (get_posts(array('post_type' => 'page', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC')) as $page) {
                        // A page EDD already binds is shown above and
                        // cannot be unticked here -- its exclusion
                        // follows that setting, not this one.
                        $auto = isset($detected[$page->ID]) && !in_array((int) $page->ID, $stored_shared, true);
                        printf(
                            '<label style="display:block; margin:2px 0%s"><input type="checkbox" name="meridian_shared_pages[]" value="%d" %s %s> %s</label>',
                            $auto ? '; opacity:.5' : '',
                            (int) $page->ID,
                            checked(in_array((int) $page->ID, $stored_shared, true) || $auto, true, false),
                            disabled($auto, true, false),
                            esc_html($page->post_title ?: '#' . $page->ID)
                        );
                    }
                    ?>
                </div>

                <h2><?php esc_html_e('Paths that never take a language prefix', 'meridian'); ?></h2>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('One per line, relative to the site root. This is where a path owned by something else on the same domain goes — another WordPress installation in a subdirectory, for instance. WordPress cannot detect one, so it has to be declared, or links to it get a language prefix that belongs to neither site.', 'meridian'); ?>
                </p>
                <p><textarea name="meridian_excluded_paths" rows="4" class="large-text code" placeholder="blog"><?php echo esc_textarea(implode("\n", $paths)); ?></textarea></p>

                <h2><?php esc_html_e('How each post type is translated', 'meridian'); ?></h2>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('A duplicated post is a real post per language. A single-source post stays one post — right for anything a customer buys, owns or is rated on, because orders and reviews record one ID.', 'meridian'); ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach (get_post_types(array('public' => true, 'show_ui' => true), 'objects') as $type) : ?>
                        <?php if (in_array($type->name, array('attachment'), true)) { continue; } ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($type->labels->name ?? $type->name); ?></th>
                            <td>
                                <select name="<?php echo esc_attr('meridian_modes[' . $type->name . ']'); ?>">
                                    <option value="<?php echo esc_attr(Modes::SEPARATE); ?>" <?php selected(Modes::for_post_type($type->name), Modes::SEPARATE); ?>><?php esc_html_e('A post per language', 'meridian'); ?></option>
                                    <option value="<?php echo esc_attr(Modes::SINGLE); ?>" <?php selected(Modes::for_post_type($type->name), Modes::SINGLE); ?>><?php esc_html_e('One post, translated fields', 'meridian'); ?></option>
                                    <option value="<?php echo esc_attr(Modes::NONE); ?>" <?php selected(Modes::for_post_type($type->name), Modes::NONE); ?>><?php esc_html_e('Not translated', 'meridian'); ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>

                <h2><?php esc_html_e('Which taxonomies are translated', 'meridian'); ?></h2>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('A translated taxonomy gets its own terms per language, each with its own name and slug. They list exactly the same items as their source — nothing is re-assigned. Leave a long tail of tags untranslated rather than facing somebody with hundreds of empty boxes.', 'meridian'); ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach (get_taxonomies(array('public' => true, 'show_ui' => true), 'objects') as $taxonomy) : ?>
                        <?php $count = (int) wp_count_terms(array('taxonomy' => $taxonomy->name, 'hide_empty' => false)); ?>
                        <tr>
                            <th scope="row">
                                <?php echo esc_html($taxonomy->labels->name ?? $taxonomy->name); ?>
                                <span class="description" style="font-weight:400"><?php
                                    printf(
                                        /* translators: %d: how many terms the taxonomy has. */
                                        esc_html(_n('%d term', '%d terms', $count, 'meridian')),
                                        (int) $count
                                    );
                                ?></span>
                            </th>
                            <td>
                                <select name="<?php echo esc_attr('meridian_tax_modes[' . $taxonomy->name . ']'); ?>">
                                    <option value="<?php echo esc_attr(Modes::NONE); ?>" <?php selected(Modes::for_taxonomy($taxonomy->name), Modes::NONE); ?>><?php esc_html_e('Not translated — one set of terms for every language', 'meridian'); ?></option>
                                    <option value="<?php echo esc_attr(Modes::SEPARATE); ?>" <?php selected(Modes::for_taxonomy($taxonomy->name), Modes::SEPARATE); ?>><?php esc_html_e('Translated — its own terms per language', 'meridian'); ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>

                <h2><?php esc_html_e('Machine translations', 'meridian'); ?></h2>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('Machine translation is not built yet, but its rules are. A machine translation never replaces one a person wrote or approved, and unreviewed machine output is not shown to visitors unless you say so here.', 'meridian'); ?>
                </p>
                <p><label>
                    <input type="checkbox" name="meridian_show_unreviewed" value="1" <?php checked(!empty($settings['show_unreviewed'])); ?>>
                    <?php esc_html_e('Show unreviewed machine translations on the site', 'meridian'); ?>
                </label></p>

                <h2><?php esc_html_e('Uninstalling', 'meridian'); ?></h2>
                <p><label>
                    <input type="checkbox" name="meridian_delete_on_uninstall" value="1" <?php checked(!empty($settings['delete_data_on_uninstall'])); ?>>
                    <?php esc_html_e('Delete all translations when this plugin is deleted', 'meridian'); ?>
                </label></p>
                <p class="description" style="max-width:46em">
                    <?php esc_html_e('Off by default. Translations are the expensive part, not the code — deleting them because somebody deactivated a plugin to test something is data loss with no undo.', 'meridian'); ?>
                </p>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Backup', 'meridian'); ?></h2>
            <p class="description" style="max-width:46em">
                <?php esc_html_e('Every translation is typed into this site by hand, so it exists only here. Export moves them between copies of the same site — objects are matched by ID, not by title.', 'meridian'); ?>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'meridian_export', admin_url('admin-post.php')), Porter::NONCE)); ?>">
                    <?php esc_html_e('Export JSON', 'meridian'); ?>
                </a>
            </p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="meridian_import">
                <?php wp_nonce_field(Porter::NONCE); ?>
                <p>
                    <input type="file" name="meridian_import" accept="application/json,.json">
                    <?php submit_button(__('Import', 'meridian'), 'secondary', 'submit', false); ?>
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Feed the stored paths to the routing exclusion.
     */
    public static function register_paths(): void
    {
        add_filter('meridian_untranslatable_paths', static function ($paths) {
            $settings = get_option(Registry::OPTION, array());
            $stored = isset($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();

            foreach ($stored as $path) {
                $paths[] = trim((string) $path, '/') . '/';
                // Without a trailing slash too: a menu link to /blog has
                // no slash, and that is the link this exists for.
                $paths[] = trim((string) $path, '/');
            }

            return $paths;
        });
    }
}
