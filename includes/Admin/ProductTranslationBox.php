<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Commerce;
use Meridian\Multilingual\Translations\Content;
use Meridian\Multilingual\Translations\Fields;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Slugs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translating a single-source product. (M5)
 *
 * Built on the field UI that already exists (ul_render_field), not a
 * second field system: one schema, one set of controls, and a field
 * added to the schema tomorrow appears here without being added twice.
 *
 * Only translatable fields appear. Commerce values are not editable
 * here and not shown as empty boxes either -- an empty box is an
 * invitation.
 *
 * Two layers of grouping, because a product's translatable surface is
 * ~80 fields and a site can have many languages:
 *
 *   Language -- a dropdown, not a tab per language. Ten languages as
 *   ten buttons wraps and crowds the box; ten `<option>`s do not. Only
 *   the selected language's fields exist in a visible state; the rest
 *   are `display: none`, not absent, so nothing here needs an AJAX
 *   round trip to switch.
 *
 *   Within one language, by heading -- reusing edd-widgets' own
 *   `.ul-content-tabs` markup and its already-enqueued delegated click
 *   handler (registered on `download_page`), rather than a second flat
 *   list. A heading whose fields are all commerce or non-translatable
 *   (Marketplace proof, Download access) is dropped rather than shown
 *   empty.
 */
final class ProductTranslationBox
{
    const NONCE = 'meridian_save_product_translation';

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register'), 10, 2);
        add_action('save_post', array($this, 'save'), 20, 2);
    }

    /**
     * @param string $post_type
     */
    public function register($post_type, $post = null): void
    {
        if (!Registry::is_multilingual() || !Modes::is_single_source((string) $post_type)) {
            return;
        }
        if (!function_exists('ul_render_field') || !function_exists('ul_field_schema')) {
            return;
        }

        add_meta_box(
            'meridian-product-translation',
            __('Translations', 'meridian'),
            array($this, 'render'),
            $post_type,
            'normal',
            'low'
        );
    }

    public function render($post): void
    {
        $post_id = (int) $post->ID;
        $languages = $this->target_languages();

        if (!$languages) {
            echo '<p>' . esc_html__('Only one language is active, so there is nothing to translate yet.', 'meridian') . '</p>';
            return;
        }

        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<p class="description" style="margin-top:0">' . esc_html__('This product stays one product in every language — one ID, one price, one set of files, one rating. Only the words below change.', 'meridian') . '</p>';

        echo '<p class="meridian-lang-picker"><label for="meridian-lang-select"><strong>' . esc_html__('Language', 'meridian') . '</strong></label> ';
        echo '<select id="meridian-lang-select">';
        foreach ($languages as $code => $language) {
            printf('<option value="%s">%s</option>', esc_attr($code), esc_html($language->label()));
        }
        echo '</select></p>';

        $first = true;
        foreach ($languages as $code => $language) {
            printf('<div class="meridian-lang-panel%s" data-lang="%s">', $first ? ' is-active' : '', esc_attr($code));
            $this->panel($post_id, $code);
            echo '</div>';
            $first = false;
        }

        $this->assets();
    }

    /**
     * One language's fields, grouped by the schema's own headings and
     * rendered as edd-widgets' own tab markup.
     */
    private function panel(int $post_id, string $lang): void
    {
        $post = get_post($post_id);
        $prefix = 'meridian_translation[' . $lang . ']';

        // Title/slug/description/excerpt are post columns, not schema
        // fields, so they get a panel of their own, always first.
        $panels = array(
            '_content' => array('label' => __('Content', 'meridian'), 'body' => $this->content_fields($post_id, $lang, $prefix, $post)),
        );

        // Sightline's own title/description are post meta on the
        // English row (_sightline_title, _sightline_description) and
        // Sightline already reads them per language through its
        // sightline_title / sightline_description filters
        // (Integrations\Sightline). What was missing was anywhere to
        // type the Spanish side of that -- this panel is it. Detected,
        // not required: no panel, no fields, when Sightline is absent.
        if (class_exists('Sightline\\SEO\\Meta')) {
            $panels['_seo'] = array('label' => __('Search appearance', 'meridian'), 'body' => $this->seo_fields($post_id, $lang, $prefix));
        }

        $schema = ul_field_schema();
        $fields = $schema['download_page']['fields'] ?? array();

        $current = null;
        foreach ($fields as $name => $definition) {
            $type = $definition['type'] ?? 'text';

            if ('heading' === $type) {
                $current = $name;
                if (!isset($panels[$current])) {
                    $panels[$current] = array('label' => $definition['label'] ?? $name, 'body' => '');
                }
                continue;
            }

            $body = '';

            if ('repeater' === $type) {
                $body = $this->repeater($post_id, $lang, $name, $definition, $prefix);
            } elseif (!Commerce::is_protected($name) && ul_field_is_translatable($definition)) {
                $body = $this->field_row_html(
                    $definition['label'] ?? $name,
                    $definition,
                    Fields::get_for_editor($post_id, $lang, $name),
                    $prefix . '[' . $name . ']',
                    (string) ul_field_read($name, $post_id),
                    Fields::is_unreviewed($post_id, $lang, $name)
                );
            }

            if ('' === $body) {
                continue;
            }

            $key = $current ?? '_general';
            if (!isset($panels[$key])) {
                $panels[$key] = array('label' => __('General', 'meridian'), 'body' => '');
            }
            $panels[$key]['body'] .= $body;
        }

        // A heading whose fields are all commerce or non-translatable
        // (Marketplace proof, Download access, Free & pro versions) has
        // nothing to show. An empty tab is worse than no tab.
        $panels = array_filter($panels, static function ($panel) {
            return '' !== trim($panel['body']);
        });

        $this->render_tabs($panels, 'meridian-fields-' . $lang);
    }

    private function content_fields(int $post_id, string $lang, string $prefix, $post): string
    {
        ob_start();
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            __('Title', 'meridian'),
            array('type' => 'text'),
            Fields::get_for_editor($post_id, $lang, Content::TITLE),
            $prefix . '[' . Content::TITLE . ']',
            $post ? $post->post_title : '',
            Fields::is_unreviewed($post_id, $lang, Content::TITLE)
        );
        $this->row(
            __('URL slug', 'meridian'),
            array('type' => 'text', 'description' => __('Leave empty to use the source slug. Changing this records a redirect from the old one.', 'meridian')),
            Fields::get_for_editor($post_id, $lang, Slugs::FIELD),
            $prefix . '[' . Slugs::FIELD . ']',
            $post ? $post->post_name : '',
            Fields::is_unreviewed($post_id, $lang, Slugs::FIELD)
        );
        $this->row(
            __('Description', 'meridian'),
            array('type' => 'textarea', 'rows' => 8),
            Fields::get_for_editor($post_id, $lang, Content::CONTENT),
            $prefix . '[' . Content::CONTENT . ']',
            '',
            Fields::is_unreviewed($post_id, $lang, Content::CONTENT)
        );
        $this->row(
            __('Excerpt', 'meridian'),
            array('type' => 'textarea', 'rows' => 3),
            Fields::get_for_editor($post_id, $lang, Content::EXCERPT),
            $prefix . '[' . Content::EXCERPT . ']',
            $post ? $post->post_excerpt : '',
            Fields::is_unreviewed($post_id, $lang, Content::EXCERPT)
        );
        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    /**
     * Sightline's search engine title and meta description, per
     * language.
     *
     * The keys match Sightline's own (`Meta::key()`) on purpose --
     * two different storage locations (the post row for English,
     * meridian_fields for everything else) unified only by the field
     * name string, the same pattern Content::TITLE/CONTENT/EXCERPT
     * already use. Only these two: `robots`, `canonical`, `og_title`,
     * `og_description`, `og_image` and `focus_keyword` also exist on
     * Sightline's side but are either not language-dependent text
     * (robots, canonical, focus_keyword) or fall back to these two
     * (og_title, og_description) when left unset in every language --
     * add a row here for one of those if a site wants it typed
     * separately.
     */
    private function seo_fields(int $post_id, string $lang, string $prefix): string
    {
        ob_start();
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(
            __('Search engine title', 'meridian'),
            array('type' => 'text', 'description' => __('Leave empty to fall back to the translated title above.', 'meridian')),
            Fields::get_for_editor($post_id, $lang, '_sightline_title'),
            $prefix . '[_sightline_title]',
            (string) get_post_meta($post_id, '_sightline_title', true),
            Fields::is_unreviewed($post_id, $lang, '_sightline_title')
        );
        $this->row(
            __('Meta description', 'meridian'),
            array('type' => 'textarea', 'rows' => 3, 'description' => __('Leave empty to fall back to the translated excerpt above.', 'meridian')),
            Fields::get_for_editor($post_id, $lang, '_sightline_description'),
            $prefix . '[_sightline_description]',
            (string) get_post_meta($post_id, '_sightline_description', true),
            Fields::is_unreviewed($post_id, $lang, '_sightline_description')
        );
        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    /**
     * A repeater, structurally locked to the source.
     *
     * Same rows, same order, no add, no remove. The row count is not
     * translatable, so row 2 means the same thing in every language --
     * otherwise a translated row 2's text ends up paired with source
     * row 2's screenshot describing a different module.
     *
     * @return string '' when the repeater has no translatable sub-fields.
     */
    private function repeater(int $post_id, string $lang, string $name, array $definition, string $prefix): string
    {
        $count = (int) ul_field_read($name, $post_id);
        $subs = array();
        foreach ($definition['sub_fields'] as $sub => $sub_definition) {
            if (!Commerce::is_protected($sub) && ul_field_is_translatable($sub_definition)) {
                $subs[$sub] = $sub_definition;
            }
        }

        if ($count < 1 || !$subs) {
            return '';
        }

        ob_start();

        printf(
            '<p class="description" style="margin:0 0 8px">%s</p>',
            esc_html(sprintf(
                /* translators: %d: number of rows on the source product. */
                _n('%d row, matching the source. Rows are added and removed on the source, not here.', '%d rows, matching the source. Rows are added and removed on the source, not here.', $count, 'meridian'),
                $count
            ))
        );

        for ($i = 0; $i < $count; $i++) {
            echo '<table class="form-table meridian-repeater-row" role="presentation"><tbody>';
            foreach ($subs as $sub => $sub_definition) {
                $key = $name . '_' . $i . '_' . $sub;
                $this->row(
                    sprintf('%s %d — %s', esc_html($definition['label'] ?? $name), $i + 1, esc_html($sub_definition['label'] ?? $sub)),
                    $sub_definition,
                    Fields::get_for_editor($post_id, $lang, $key),
                    $prefix . '[' . $key . ']',
                    (string) ul_field_read($key, $post_id),
                    Fields::is_unreviewed($post_id, $lang, $key)
                );
            }
            echo '</tbody></table>';
        }

        return (string) ob_get_clean();
    }

    /**
     * One field wrapped in its own one-row table, as a string.
     */
    private function field_row_html(string $label, array $definition, $value, string $input_name, string $source, bool $unreviewed = false): string
    {
        ob_start();
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row($label, $definition, $value, $input_name, $source, $unreviewed);
        echo '</tbody></table>';

        return (string) ob_get_clean();
    }

    /**
     * One field, with the source value shown beside it.
     *
     * @param mixed $value  Stored translation, or null.
     * @param string $source The default language's value, for reference.
     * @param bool $unreviewed Machine output nobody has approved yet
     *                         (M13) -- flagged rather than hidden, so
     *                         saving this screen never mistakes "not
     *                         reviewed" for "empty" and deletes it.
     */
    private function row(string $label, array $definition, $value, string $input_name, string $source, bool $unreviewed = false): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';

        // Reuse edd-widgets' own control so a field type added there
        // renders here too, with the same markup and the same JS.
        ul_render_field('', array('type' => $definition['type'] ?? 'text') + $definition, $value, $input_name);

        if ($unreviewed) {
            printf(
                '<p class="description meridian-unreviewed" style="margin-top:4px;color:#b32d2e">%s</p>',
                esc_html__('Machine translation, not yet reviewed. Saving this screen approves it as-is.', 'meridian')
            );
        }

        if ('' !== trim($source)) {
            printf(
                '<p class="description" style="margin-top:4px"><strong>%s</strong> %s</p>',
                esc_html__('Source:', 'meridian'),
                esc_html(wp_trim_words(wp_strip_all_tags($source), 40))
            );
        }

        echo '</td></tr>';
    }

    /**
     * Heading-grouped tabs, in edd-widgets' own markup.
     *
     * `.ul-content-tabs` / `.ul-content-tab` / `.ul-content-panel` are
     * edd-widgets' classes (inc/fields-admin.php), already styled and
     * already wired to a click handler delegated on `document` -- both
     * enqueued on this screen for the Product Page meta box. Reusing
     * them exactly, rather than a second set of tab CSS/JS, is what
     * lets this need no script of its own for the inner layer.
     *
     * @param array  $panels  key => array{label, body}
     * @param string $id_seed Unique per language, so two languages'
     *                        tabs in the same hidden DOM do not collide.
     */
    private function render_tabs(array $panels, string $id_seed): void
    {
        if (!$panels) {
            echo '<p class="description">' . esc_html__('Nothing on this product is translatable beyond its title and description.', 'meridian') . '</p>';
            return;
        }

        $uid = wp_unique_id($id_seed . '-');

        echo '<div class="ul-fields ul-content-tabs" id="' . esc_attr($uid) . '">';
        echo '<div class="ul-content-tabs-nav" role="tablist">';
        $first = true;
        foreach ($panels as $key => $panel) {
            printf(
                '<button type="button" class="ul-content-tab" aria-selected="%s" data-panel="%s">%s</button>',
                $first ? 'true' : 'false',
                esc_attr($uid . '-' . $key),
                esc_html($panel['label'])
            );
            $first = false;
        }
        echo '</div><div class="ul-content-tabs-panels">';

        $first = true;
        foreach ($panels as $key => $panel) {
            printf('<section class="ul-content-panel%s" id="%s" role="tabpanel">', $first ? ' is-active' : '', esc_attr($uid . '-' . $key));
            echo '<h3 class="ul-content-panel-title">' . esc_html($panel['label']) . '</h3>';
            echo $panel['body']; // Built entirely from row()/ul_render_field(), already escaped there.
            echo '</section>';
            $first = false;
        }

        echo '</div></div>';
    }

    /**
     * @param int $post_id
     */
    public function save($post_id, $post = null): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $submitted = isset($_POST['meridian_translation']) && is_array($_POST['meridian_translation'])
            ? wp_unslash($_POST['meridian_translation'])
            : array();

        foreach ($submitted as $lang => $values) {
            $lang = sanitize_text_field((string) $lang);
            if (!Registry::exists($lang) || Registry::is_default($lang) || !is_array($values)) {
                continue;
            }

            foreach ($values as $field => $value) {
                $field = sanitize_text_field((string) $field);
                $value = is_string($value) ? $value : '';

                if (Commerce::is_protected($field)) {
                    // Cannot happen from this screen, which never
                    // renders one. It can happen from a crafted post,
                    // and the deny-list is the answer to that too.
                    continue;
                }

                if (Slugs::FIELD === $field) {
                    // Through Slugs, not Fields: it sanitises, checks
                    // the slug is free and records the redirect.
                    if ('' !== trim($value)) {
                        Slugs::set((int) $post_id, $lang, $value);
                    } else {
                        Fields::delete((int) $post_id, $lang, Slugs::FIELD);
                    }
                    continue;
                }

                if ('' === trim($value)) {
                    Fields::delete((int) $post_id, $lang, $field);
                    continue;
                }

                // Every write goes through the translation service, so
                // origin and the source hash are recorded (M13).
                Fields::set((int) $post_id, $lang, $field, $value, array(
                    'source' => (string) $this->source_value((int) $post_id, $field),
                ));
            }
        }
    }

    /**
     * The default-language value a translation was written against.
     */
    private function source_value(int $post_id, string $field)
    {
        $post = get_post($post_id);

        if (Content::TITLE === $field) {
            return $post ? $post->post_title : '';
        }
        if (Content::CONTENT === $field) {
            return $post ? $post->post_content : '';
        }
        if (Content::EXCERPT === $field) {
            return $post ? $post->post_excerpt : '';
        }

        return function_exists('ul_field_read') ? ul_field_read($field, $post_id) : '';
    }

    /**
     * @return array code => Language
     */
    private function target_languages(): array
    {
        $languages = array();
        foreach (Registry::active() as $code => $language) {
            if (!Registry::is_default($code)) {
                $languages[$code] = $language;
            }
        }
        return $languages;
    }

    private function assets(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
?>
        <style>
            .meridian-lang-picker select { min-width: 220px; }
            .meridian-lang-panel { display: none; }
            .meridian-lang-panel.is-active { display: block; }
            .meridian-lang-panel .form-table th { width: 200px; font-weight: 600; }
            .meridian-lang-panel .form-table td input[type="text"],
            .meridian-lang-panel .form-table td textarea { width: 100%; }
            .meridian-repeater-row { border-left: 3px solid #dcdcde; padding-left: 12px; margin-bottom: 4px; }
        </style>
        <script>
        (function () {
            // Only the language layer needs its own script -- the
            // heading layer inside each panel reuses edd-widgets'
            // .ul-content-tab click handler, already delegated on
            // document for the Product Page meta box on this screen.
            document.addEventListener('change', function (e) {
                if (!e.target.matches('#meridian-lang-select')) { return; }
                var box = document.getElementById('meridian-product-translation');
                if (!box) { return; }
                box.querySelectorAll('.meridian-lang-panel').forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.dataset.lang === e.target.value);
                });
            });
        })();
        </script>
<?php
    }
}
