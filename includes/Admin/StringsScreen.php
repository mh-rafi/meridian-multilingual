<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Fields;
use Meridian\Multilingual\Translations\Strings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meridian → Strings. (M10)
 *
 * The only way text that is not in a PHP file reaches production,
 * because deploys here are files only. A shipping surface.
 */
final class StringsScreen
{
    const SLUG = 'meridian-strings';
    const NONCE = 'meridian_save_strings';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register'), 12);
        add_action('admin_post_meridian_save_strings', array($this, 'save'));
    }

    public function register(): void
    {
        $untranslated = 0;
        foreach (Registry::codes() as $code) {
            if (!Registry::is_default($code)) {
                $untranslated += Strings::count_untranslated($code);
            }
        }

        add_submenu_page(
            LanguagesScreen::SLUG,
            __('Strings', 'meridian'),
            $untranslated > 0
                /* translators: %s: a count bubble showing how many strings are untranslated. */
                ? sprintf(__('Strings %s', 'meridian'), '<span class="awaiting-mod"><span class="pending-count">' . (int) $untranslated . '</span></span>')
                : __('Strings', 'meridian'),
            'manage_options',
            self::SLUG,
            array($this, 'render')
        );
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'meridian'));
        }
        check_admin_referer(self::NONCE);

        $lang = isset($_POST['meridian_lang']) ? sanitize_text_field(wp_unslash($_POST['meridian_lang'])) : '';
        $rows = isset($_POST['meridian_string']) && is_array($_POST['meridian_string']) ? wp_unslash($_POST['meridian_string']) : array();
        $saved = 0;

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['source'])) {
                continue;
            }

            $result = Strings::set(
                (string) $row['source'],
                $lang,
                (string) ($row['translation'] ?? ''),
                array('context' => (string) ($row['context'] ?? ''))
            );

            if (!is_wp_error($result)) {
                $saved++;
            }
        }

        wp_safe_redirect(add_query_arg(array(
            'page'           => self::SLUG,
            'lang'           => $lang,
            'meridian_saved' => $saved,
        ), admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $targets = array();
        foreach (Registry::active() as $code => $language) {
            if (!Registry::is_default($code)) {
                $targets[$code] = $language;
            }
        }

        $lang = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : (string) array_key_first($targets);
        $only = !empty($_GET['untranslated']);
        $rows = $lang ? Strings::all($lang, $only) : array();
?>
        <div class="wrap">
            <h1><?php esc_html_e('Strings', 'meridian'); ?></h1>

            <?php if (isset($_GET['meridian_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    printf(
                        /* translators: %d: how many strings were saved. */
                        esc_html(_n('%d string saved.', '%d strings saved.', (int) $_GET['meridian_saved'], 'meridian')),
                        (int) $_GET['meridian_saved']
                    );
                ?></p></div>
            <?php endif; ?>

            <p class="description" style="max-width:46em">
                <?php esc_html_e('Text that is not in a theme or plugin file, so it cannot be translated the usual way — labels typed into settings, and values stored in options. Strings appear here once the page that uses them has been rendered at least once.', 'meridian'); ?>
            </p>

            <?php if (!$targets) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('Add a second language first.', 'meridian'); ?></p></div>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($targets as $code => $language) : ?>
                    <a class="nav-tab <?php echo $code === $lang ? 'nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg(array('page' => self::SLUG, 'lang' => $code), admin_url('admin.php'))); ?>">
                        <?php echo esc_html($language->label()); ?>
                        <span class="count">(<?php echo (int) Strings::count_untranslated($code); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </h2>

            <p>
                <a href="<?php echo esc_url(add_query_arg(array('page' => self::SLUG, 'lang' => $lang) + ($only ? array() : array('untranslated' => 1)), admin_url('admin.php'))); ?>">
                    <?php echo $only ? esc_html__('Show all strings', 'meridian') : esc_html__('Show untranslated only', 'meridian'); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="meridian_save_strings">
                <input type="hidden" name="meridian_lang" value="<?php echo esc_attr($lang); ?>">
                <?php wp_nonce_field(self::NONCE); ?>

                <table class="widefat striped">
                    <thead><tr>
                        <th style="width:30%"><?php esc_html_e('Source', 'meridian'); ?></th>
                        <th style="width:14%"><?php esc_html_e('Where', 'meridian'); ?></th>
                        <th><?php esc_html_e('Translation', 'meridian'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$rows) : ?>
                        <tr><td colspan="3"><?php esc_html_e('Nothing here yet. Visit the pages that show this text on the front end, and it will be collected.', 'meridian'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $row) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html((string) $row['source']); ?>
                                <input type="hidden" name="<?php echo esc_attr("meridian_string[$i][source]"); ?>" value="<?php echo esc_attr((string) $row['source']); ?>">
                                <input type="hidden" name="<?php echo esc_attr("meridian_string[$i][context]"); ?>" value="<?php echo esc_attr((string) $row['context']); ?>">
                            </td>
                            <td><code style="font-size:11px"><?php echo esc_html((string) $row['context']); ?></code></td>
                            <td>
                                <input type="text" class="large-text" name="<?php echo esc_attr("meridian_string[$i][translation]"); ?>"
                                       value="<?php echo esc_attr((string) ($row['translation'] ?? '')); ?>">
                                <?php if (Fields::MACHINE === $row['origin'] && null === $row['reviewed_at'] && null !== $row['translation']) : ?>
                                    <span class="description" style="color:#b45309"><?php esc_html_e('Machine translation, not reviewed — not shown on the site until somebody approves it.', 'meridian'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Save translations', 'meridian')); ?>
            </form>
        </div>
<?php
    }
}
