<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Catalogue;
use Meridian\Multilingual\Languages\Language;
use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Posts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meridian → Languages. (M1)
 *
 * The only way the language set is configured. It matters more than an
 * admin screen usually does, because deploys on this project are files
 * only (spec 9): this screen is how the configuration reaches
 * production at all, not a convenience over editing an option.
 */
final class LanguagesScreen
{
    const SLUG = 'meridian-languages';
    const NONCE = 'meridian_save_languages';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register'));
        add_action('admin_post_meridian_save_languages', array($this, 'save'));
    }

    public function register(): void
    {
        add_menu_page(
            __('Meridian', 'meridian'),
            __('Meridian', 'meridian'),
            'manage_options',
            self::SLUG,
            array($this, 'render'),
            'dashicons-translation',
            58
        );

        add_submenu_page(
            self::SLUG,
            __('Languages', 'meridian'),
            __('Languages', 'meridian'),
            'manage_options',
            self::SLUG,
            array($this, 'render')
        );
    }

    /**
     * Handle the form, then redirect.
     *
     * Redirect rather than render in place so a refresh cannot resubmit
     * the whole language set.
     */
    public function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to configure languages.', 'meridian'));
        }
        check_admin_referer(self::NONCE);

        $submitted = isset($_POST['meridian_languages']) && is_array($_POST['meridian_languages'])
            ? wp_unslash($_POST['meridian_languages'])
            : array();
        $default = isset($_POST['meridian_default']) ? sanitize_text_field(wp_unslash($_POST['meridian_default'])) : '';

        $languages = array();
        foreach ($submitted as $row) {
            if (!is_array($row)) {
                continue;
            }

            // The template row the "add language" button clones is
            // submitted like any other and is blank unless it was used.
            if ('' === trim((string) ($row['code'] ?? '')) && '' === trim((string) ($row['locale'] ?? ''))) {
                continue;
            }

            $languages[] = new Language(array(
                'code'         => sanitize_text_field((string) ($row['code'] ?? '')),
                'locale'       => sanitize_text_field((string) ($row['locale'] ?? '')),
                'native_name'  => sanitize_text_field((string) ($row['native_name'] ?? '')),
                'english_name' => sanitize_text_field((string) ($row['english_name'] ?? '')),
                'direction'    => sanitize_text_field((string) ($row['direction'] ?? 'ltr')),
                'flag'         => sanitize_text_field((string) ($row['flag'] ?? '')),
                // An unchecked box submits nothing, so absence is false
                // -- except for the default, which validate() refuses to
                // let be inactive rather than silently reactivating.
                'active'       => !empty($row['active']),
            ));
        }

        $result = Registry::save($languages, $default);

        $args = array('page' => self::SLUG);
        if (is_wp_error($result)) {
            // Carried in the URL rather than a transient: the message is
            // about what was just typed, and a transient keyed on the
            // user would outlive the attempt and reappear later.
            $args['meridian_error'] = rawurlencode($result->get_error_message());
        } else {
            $args['meridian_saved'] = 1;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $languages = Registry::all();
        $default = Registry::default_code();
        $catalogue = Catalogue::all();
        $error = isset($_GET['meridian_error']) ? sanitize_text_field(wp_unslash($_GET['meridian_error'])) : '';
        $saved = !empty($_GET['meridian_saved']);
?>
        <div class="wrap meridian-languages">
            <h1><?php esc_html_e('Languages', 'meridian'); ?></h1>

            <?php if ($error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php elseif ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Languages saved.', 'meridian'); ?></p></div>
            <?php endif; ?>

            <?php $this->front_page_warning(); ?>

            <p class="description" style="max-width:46em">
                <?php esc_html_e('The default language is served at the site root with no prefix. Every other language is served under its own code, e.g. /es/. Changing which language is the default moves the prefix — it does not move any content.', 'meridian'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="meridian_save_languages">
                <?php wp_nonce_field(self::NONCE); ?>

                <table class="widefat striped meridian-languages-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width:5em"><?php esc_html_e('Default', 'meridian'); ?></th>
                            <th scope="col"><?php esc_html_e('Language', 'meridian'); ?></th>
                            <th scope="col" style="width:7em"><?php esc_html_e('Code', 'meridian'); ?></th>
                            <th scope="col" style="width:9em"><?php esc_html_e('Locale', 'meridian'); ?></th>
                            <th scope="col" style="width:7em"><?php esc_html_e('Direction', 'meridian'); ?></th>
                            <th scope="col" style="width:6em"><?php esc_html_e('Flag', 'meridian'); ?></th>
                            <th scope="col" style="width:6em"><?php esc_html_e('Active', 'meridian'); ?></th>
                            <th scope="col" style="width:8em"><?php esc_html_e('URL', 'meridian'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="meridian-language-rows">
                        <?php
                        $index = 0;
                        foreach ($languages as $language) {
                            $this->row($index, $language, $default);
                            $index++;
                        }
                        ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="meridian-add-language"><?php esc_html_e('Add language', 'meridian'); ?></button>
                </p>

                <?php submit_button(__('Save languages', 'meridian')); ?>

                <template id="meridian-language-template">
                    <?php $this->row('__i__', new Language(array('active' => true)), $default); ?>
                </template>
            </form>

            <?php $this->prefill_data($catalogue); ?>
        </div>

        <style>
            .meridian-languages-table input[type="text"] { width: 100%; }
            .meridian-languages-table th:nth-child(2) { min-width: 16em; }
            .meridian-languages-table td { vertical-align: middle; }
            .meridian-languages-url { font-family: monospace; color: #50575e; }
        </style>

        <script>
        (function () {
            var rows = document.getElementById('meridian-language-rows');
            var template = document.getElementById('meridian-language-template');
            var add = document.getElementById('meridian-add-language');
            var catalogue = window.meridianLocales || {};
            var home = <?php echo wp_json_encode(user_trailingslashit(home_url('/'))); ?>;

            // Row indexes only have to be unique within one submission;
            // the registry stores the set as a list and renumbers.
            var next = rows.querySelectorAll('tr').length;

            add.addEventListener('click', function () {
                var html = template.innerHTML.replace(/__i__/g, 'new' + (next++));
                var holder = document.createElement('tbody');
                holder.innerHTML = html;
                while (holder.firstElementChild) { rows.appendChild(holder.firstElementChild); }
            });

            // Picking a locale fills the row, but never overwrites
            // something already typed -- a corrected native name must
            // survive re-picking the locale it belongs to.
            rows.addEventListener('change', function (e) {
                if (!e.target.matches('.meridian-locale')) { return; }
                var known = catalogue[e.target.value];
                if (!known) { return; }
                var row = e.target.closest('tr');
                [['code', known.code], ['native_name', known.native_name],
                 ['english_name', known.english_name]].forEach(function (pair) {
                    var field = row.querySelector('.meridian-' + pair[0]);
                    if (field && !field.value) { field.value = pair[1]; }
                });
                var direction = row.querySelector('.meridian-direction');
                if (direction) { direction.value = known.direction || 'ltr'; }
                update(row);
            });

            rows.addEventListener('input', function (e) {
                if (e.target.matches('.meridian-code')) { update(e.target.closest('tr')); }
            });
            rows.addEventListener('change', function (e) {
                if (e.target.matches('.meridian-default')) {
                    rows.querySelectorAll('tr').forEach(update);
                }
            });

            // The prefix is derived, so the screen derives it too rather
            // than showing a stored value that could disagree.
            //
            // The radio is synced here rather than on the code field's
            // own event because the code is also written by the locale
            // prefill, which fires no input event. Syncing on typing
            // alone left a picked-then-defaulted row submitting an empty
            // code, and the save failed with nothing on screen to
            // explain it.
            function update(row) {
                var field = row.querySelector('.meridian-code');
                var code = field ? field.value.trim().toLowerCase() : '';
                var radio = row.querySelector('.meridian-default');
                if (radio) { radio.value = code; }
                var cell = row.querySelector('.meridian-languages-url');
                if (cell) { cell.textContent = (radio && radio.checked) ? home : home + code + '/'; }
            }

            rows.querySelectorAll('tr').forEach(update);
        })();
        </script>
<?php
    }

    /**
     * Warn about an active language whose front page is untranslated.
     *
     * Every translated page links home, and the home link is
     * structural -- it always takes the current language's prefix. But
     * /es/ only exists once the front page itself is translated. So
     * activating a language before translating its front page leaves
     * every translated page in it with a broken logo link.
     *
     * A warning rather than a fallback: linking home to the default
     * language instead would be a silent language switch, which is the
     * thing a switcher is forbidden from doing for the same reason.
     */
    private function front_page_warning(): void
    {
        if (!Registry::is_multilingual() || 'page' !== get_option('show_on_front')) {
            return;
        }

        $front = (int) get_option('page_on_front');
        if ($front < 1) {
            return;
        }

        $missing = array();
        foreach (Registry::active() as $code => $language) {
            if (!Registry::is_default($code) && !Posts::has($front, $code)) {
                $missing[] = $language->label();
            }
        }

        if (!$missing) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html(sprintf(
                /* translators: %s: a comma-separated list of language names. */
                _n('%s has no translated front page.', '%s have no translated front page.', count($missing), 'meridian'),
                implode(', ', $missing)
            )),
            esc_html__('Until it does, every page translated into that language links to a home page that does not exist. Translate the front page first, or leave the language inactive.', 'meridian')
        );
    }

    /**
     * One editable language.
     *
     * @param int|string $index Row index, or the template placeholder.
     */
    private function row($index, Language $language, string $default): void
    {
        $name = 'meridian_languages[' . $index . ']';
        $is_default = '' !== $language->code && $language->code === $default;
?>
        <tr>
            <td>
                <input type="radio" class="meridian-default" name="meridian_default" value="<?php echo esc_attr($language->code); ?>" <?php checked($is_default); ?>
                    aria-label="<?php esc_attr_e('Make this the default language', 'meridian'); ?>">
            </td>
            <td>
                <input type="text" class="meridian-native_name" name="<?php echo esc_attr($name . '[native_name]'); ?>"
                    value="<?php echo esc_attr($language->native_name); ?>"
                    placeholder="<?php esc_attr_e('Native name, e.g. Español', 'meridian'); ?>">
                <input type="text" class="meridian-english_name" name="<?php echo esc_attr($name . '[english_name]'); ?>"
                    value="<?php echo esc_attr($language->english_name); ?>"
                    placeholder="<?php esc_attr_e('English name, e.g. Spanish', 'meridian'); ?>" style="margin-top:4px">
            </td>
            <td>
                <input type="text" class="meridian-code" name="<?php echo esc_attr($name . '[code]'); ?>"
                    value="<?php echo esc_attr($language->code); ?>" placeholder="es" maxlength="5">
            </td>
            <td>
                <input type="text" class="meridian-locale" name="<?php echo esc_attr($name . '[locale]'); ?>"
                    value="<?php echo esc_attr($language->locale); ?>" placeholder="es_ES"
                    list="meridian-locale-list" autocomplete="off">
            </td>
            <td>
                <select class="meridian-direction" name="<?php echo esc_attr($name . '[direction]'); ?>">
                    <option value="ltr" <?php selected($language->direction, 'ltr'); ?>><?php esc_html_e('LTR', 'meridian'); ?></option>
                    <option value="rtl" <?php selected($language->direction, 'rtl'); ?>><?php esc_html_e('RTL', 'meridian'); ?></option>
                </select>
            </td>
            <td>
                <input type="text" class="meridian-flag" name="<?php echo esc_attr($name . '[flag]'); ?>"
                    value="<?php echo esc_attr($language->flag); ?>" placeholder="<?php esc_attr_e('none', 'meridian'); ?>" maxlength="2"
                    aria-label="<?php esc_attr_e('Country code for a flag, or leave empty', 'meridian'); ?>">
            </td>
            <td>
                <input type="checkbox" name="<?php echo esc_attr($name . '[active]'); ?>" value="1" <?php checked($language->active); ?>
                    aria-label="<?php esc_attr_e('Serve this language', 'meridian'); ?>">
            </td>
            <td class="meridian-languages-url"></td>
        </tr>
<?php
    }

    /**
     * The locale list the form autocompletes against, plus the prefill
     * data its script reads.
     */
    private function prefill_data(array $catalogue): void
    {
        $data = array();
        foreach ($catalogue as $locale => $language) {
            $data[$locale] = array(
                'code'         => $language['code'] ?? '',
                'native_name'  => $language['native_name'] ?? '',
                'english_name' => $language['english_name'] ?? '',
                'direction'    => $language['direction'] ?? 'ltr',
            );
        }
?>
        <datalist id="meridian-locale-list">
            <?php foreach ($catalogue as $locale => $language) : ?>
                <option value="<?php echo esc_attr($locale); ?>"><?php echo esc_html($language['english_name'] ?? $locale); ?></option>
            <?php endforeach; ?>
        </datalist>
        <script>window.meridianLocales = <?php echo wp_json_encode($data); ?>;</script>
<?php
    }
}
