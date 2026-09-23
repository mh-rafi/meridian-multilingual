<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Terms;
use Meridian\Multilingual\Translations\UrlSlugs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translating terms from the taxonomy screens. (M7)
 *
 * A column with one link per missing language, and a panel on the term
 * editor. No separate screen: a taxonomy with 10 terms does not need
 * one, and a taxonomy with 152 is one this site has turned off.
 */
final class TermTranslations
{
    const NONCE = 'meridian_translate_term';

    public function __construct()
    {
        add_action('admin_init', array($this, 'hook_taxonomies'));
        add_action('admin_post_meridian_create_term_translation', array($this, 'create'));
        // Priority -1. Easy Digital Downloads hooks admin_notices at
        // priority 0 and unsets every callback whose class or function
        // name does not contain "edd", on every screen
        // edd_is_admin_page() claims -- which includes the download
        // editor and the download taxonomy screens. It is decluttering
        // its own pages of other plugins' promos, but it cannot tell a
        // promo from "your translation was created", so this feedback
        // vanished on exactly the screens that produce it. Running
        // before the stripper is the fix that does not involve naming a
        // class after another plugin.
        add_action('admin_notices', array($this, 'notice'), -1);
    }

    public function hook_taxonomies(): void
    {
        if (!Registry::is_multilingual()) {
            return;
        }

        foreach (get_taxonomies(array('show_ui' => true), 'names') as $taxonomy) {
            if (!Modes::is_translated_taxonomy($taxonomy)) {
                continue;
            }

            add_filter("manage_edit-{$taxonomy}_columns", array($this, 'column'));
            // Priority 20, not 10. Easy Digital Downloads registers
            // its featured-image callback on this filter with
            // add_action() and returns a bare `return;` -- null -- for
            // every column that is not its own. At the same priority it
            // runs after this one and discards whatever this returned,
            // so the column rendered as an empty cell with no error
            // anywhere. Running after it, and treating a null as empty,
            // survives that.
            add_filter("manage_{$taxonomy}_custom_column", array($this, 'column_value'), 20, 3);
            add_action("{$taxonomy}_edit_form", array($this, 'edit_form'), 10, 2);
        }
    }

    /**
     * @param array $columns
     * @return array
     */
    public function column($columns): array
    {
        return (array) $columns + array('meridian_language' => __('Languages', 'meridian'));
    }

    /**
     * @param string $content
     * @param string $column
     * @param int    $term_id
     */
    public function column_value($content, $column, $term_id): string
    {
        if ('meridian_language' !== $column) {
            return (string) $content;
        }

        return $this->links((int) $term_id, false);
    }


    /**
     * @param mixed  $term
     * @param string $taxonomy
     */
    public function edit_form($term, $taxonomy = ''): void
    {
        $term_id = is_object($term) ? (int) $term->term_id : (int) $term;
        $lang = Terms::language_of($term_id);
        $language = Registry::get($lang);
?>
        <h2><?php esc_html_e('Translations', 'meridian'); ?></h2>
        <table class="form-table" role="presentation"><tbody>
            <tr>
                <th scope="row"><?php esc_html_e('This term', 'meridian'); ?></th>
                <td><strong><?php echo esc_html($language ? $language->label() : $lang); ?></strong></td>
            </tr>
            <?php $term_object = get_term($term_id); ?>
            <?php if ($term_object instanceof \WP_Term && '' !== ($public = UrlSlugs::public_term_url($term_object))) : ?>
            <tr>
                <th scope="row"><?php esc_html_e('Public URL', 'meridian'); ?></th>
                <td>
                    <a href="<?php echo esc_url($public); ?>" target="_blank" rel="noopener"><?php echo esc_html($public); ?></a>
                    <?php if (UrlSlugs::for_term($term_object) !== $term_object->slug) : ?>
                        <p class="description" style="max-width:40em">
                            <?php
                            printf(
                                /* translators: 1: the stored slug, e.g. business-es. 2: the language code. */
                                esc_html__('The slug above is stored as %1$s only because WordPress needs every slug in a taxonomy to be unique. The language prefix already makes the URL unique, so the "-%2$s" ending is left out of it. To use a slug in this language instead, replace the whole slug, for example with a translated word.', 'meridian'),
                                '<code>' . esc_html($term_object->slug) . '</code>',
                                esc_html($lang)
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e('Other languages', 'meridian'); ?></th>
                <td>
                    <?php echo wp_kses_post($this->links($term_id, true)); ?>
                    <p class="description" style="max-width:40em">
                        <?php esc_html_e('A translated term keeps its own name and slug, and lists exactly the same items as this one — nothing is re-assigned.', 'meridian'); ?>
                    </p>
                </td>
            </tr>
        </tbody></table>
<?php
    }

    /**
     * One entry per language: a link to the translation, or to create it.
     */
    private function links(int $term_id, bool $verbose): string
    {
        $lang = Terms::language_of($term_id);
        $siblings = Terms::siblings($term_id);
        $out = array();

        foreach (Registry::active() as $code => $language) {
            if ($code === $lang) {
                continue;
            }

            $translation = (int) ($siblings[$code] ?? 0);
            $label = $verbose ? $language->label() : $language->code;

            if ($translation > 0) {
                $term = get_term($translation);
                $out[] = sprintf(
                    '<a href="%s">%s</a>%s',
                    esc_url((string) get_edit_term_link($translation)),
                    esc_html($label),
                    ($verbose && $term && !is_wp_error($term)) ? ' <span class="description">(' . esc_html($term->name) . ')</span>' : ''
                );
            } else {
                $out[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($this->create_url($term_id, $code)),
                    esc_html($verbose ? sprintf(
                        /* translators: %s: a language name. */
                        __('Add %s', 'meridian'),
                        $language->label()
                    ) : '+' . $language->code)
                );
            }
        }

        return $out ? implode($verbose ? '<br>' : ' · ', $out) : '—';
    }

    public function create(): void
    {
        $term_id = isset($_GET['term']) ? absint($_GET['term']) : 0;
        $lang = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';

        $term = $term_id ? get_term($term_id) : null;
        if (!$term || is_wp_error($term)) {
            wp_die(esc_html__('That term does not exist.', 'meridian'));
        }

        $taxonomy = get_taxonomy($term->taxonomy);
        if (!$taxonomy || !current_user_can($taxonomy->cap->edit_terms)) {
            wp_die(esc_html__('You are not allowed to do that.', 'meridian'));
        }
        check_admin_referer(self::NONCE . $term_id);

        $result = Terms::create($term_id, $lang);

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('meridian_error', rawurlencode($result->get_error_message()), (string) get_edit_term_link($term_id)));
            exit;
        }

        // Into the new term's editor: it needs a name in its own
        // language, and the copied one is only a starting point.
        wp_safe_redirect(add_query_arg('meridian_term_created', 1, (string) get_edit_term_link($result)));
        exit;
    }

    public function notice(): void
    {
        if (isset($_GET['meridian_error'])) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sanitize_text_field(wp_unslash($_GET['meridian_error']))));
        }
        if (isset($_GET['meridian_term_created'])) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__('Translated term created. Give it a name in its own language — it already lists the same items as its source, and is published under the same slug with the language prefix. Replace the slug only if you want a translated one.', 'meridian')
            );
        }
    }

    private function create_url(int $term_id, string $lang): string
    {
        $url = add_query_arg(array(
            'action' => 'meridian_create_term_translation',
            'term'   => $term_id,
            'lang'   => $lang,
        ), admin_url('admin-post.php'));

        return wp_nonce_url($url, self::NONCE . $term_id);
    }
}
