<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Url;
use Meridian\Multilingual\Translations\Duplicator;
use Meridian\Multilingual\Translations\Functional;
use Meridian\Multilingual\Translations\Groups;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Posts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Translations box on the editor, and the list-table column. (M4)
 *
 * Deploys on this project are files only, so this screen is how a
 * translation comes into existence on production at all.
 */
final class TranslationsBox
{
    const NONCE = 'meridian_translate';

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register'), 10, 2);
        add_action('admin_post_meridian_create_translation', array($this, 'create'));
        add_action('admin_post_meridian_resync_shared', array($this, 'resync'));
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

        foreach (self::translatable_types() as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", array($this, 'column'));
            add_action("manage_{$post_type}_posts_custom_column", array($this, 'column_value'), 10, 2);
        }
    }

    /**
     * @param string $post_type
     * @param mixed  $post
     */
    public function register($post_type, $post = null): void
    {
        if (!Registry::is_multilingual() || Modes::SEPARATE !== Modes::for_post_type((string) $post_type)) {
            return;
        }

        add_meta_box('meridian-translations', __('Translations', 'meridian'), array($this, 'render'), $post_type, 'side', 'high');
    }

    public function render($post): void
    {
        $post_id = (int) $post->ID;

        if (Functional::is_functional($post_id)) {
            $this->render_functional($post_id);
            return;
        }

        $lang = Posts::language_of($post_id);
        $siblings = Groups::siblings($post_id);
        $language = Registry::get($lang);

        echo '<p style="margin-top:0">' . sprintf(
            /* translators: %s: the language this post is written in. */
            esc_html__('This is the %s version.', 'meridian'),
            '<strong>' . esc_html($language ? $language->label() : $lang) . '</strong>'
        ) . '</p>';

        echo '<table class="widefat striped" style="margin-bottom:10px"><tbody>';

        foreach (Registry::active() as $code => $other) {
            if ($code === $lang) {
                continue;
            }

            $translation = (int) ($siblings[$code] ?? 0);

            echo '<tr><td>' . esc_html($other->label()) . '</td><td style="text-align:right">';

            if ($translation > 0) {
                printf(
                    '<a href="%s">%s</a> <span class="description">(%s)</span>',
                    esc_url((string) get_edit_post_link($translation)),
                    esc_html__('Edit', 'meridian'),
                    esc_html(get_post_status($translation))
                );
            } else {
                printf(
                    '<a href="%s" class="button button-small">%s</a>',
                    esc_url($this->action_url('meridian_create_translation', $post_id, array('lang' => $code))),
                    esc_html__('Create', 'meridian')
                );
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        if (!Registry::is_default($lang) && count($siblings) > 1) {
            printf(
                '<p><a href="%s" class="button button-small">%s</a><br><span class="description">%s</span></p>',
                esc_url($this->action_url('meridian_resync_shared', $post_id)),
                esc_html__('Re-copy shared fields', 'meridian'),
                esc_html__('Rewrites images, links, IDs and settings from the source, leaving translated text alone. The copy made when this translation was created is a snapshot, and the source keeps changing.', 'meridian')
            );
        }

        if (Registry::is_default($lang)) {
            echo '<p class="description">' . esc_html__('Creating a translation copies this post’s fields, so the new page starts with the same images, links and layout — only the text needs rewriting.', 'meridian') . '</p>';
        }
    }

    /**
     * A page that is the same page in every language. (M6)
     */
    private function render_functional(int $post_id): void
    {
        $reason = Functional::reason($post_id);

        echo '<p style="margin-top:0">' . esc_html__('This page is the same page in every language.', 'meridian') . '</p>';

        if ($reason) {
            echo '<p class="description">' . esc_html(ucfirst($reason)) . '.</p>';
        }

        echo '<p class="description">' . esc_html__('Duplicating it would break what it does, because the plugin that owns it finds it by ID. It is reachable under every language prefix, and what a visitor reads is its shortcodes output, translated as text rather than as a page.', 'meridian') . '</p>';

        if (!Registry::is_multilingual()) {
            return;
        }

        echo '<p class="description"><strong>' . esc_html__('Reachable at:', 'meridian') . '</strong><br>';
        foreach (Registry::active() as $code => $language) {
            printf('<code style="font-size:11px">%s</code><br>', esc_html(Url::set_language((string) get_permalink($post_id), $code)));
        }
        echo '</p>';
    }

    public function create(): void
    {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $lang = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';

        $this->check($post_id);

        $result = Duplicator::create($post_id, $lang);

        if (is_wp_error($result)) {
            $this->back($post_id, array('meridian_error' => rawurlencode($result->get_error_message())));
        }

        // Straight into the new draft: the next thing anybody wants to
        // do is write it.
        wp_safe_redirect(add_query_arg('meridian_created', 1, (string) get_edit_post_link($result, 'raw')));
        exit;
    }

    public function resync(): void
    {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $this->check($post_id);

        $count = Duplicator::resync_shared($post_id);

        $this->back($post_id, array('meridian_resynced' => $count));
    }

    public function notice(): void
    {
        foreach (array('meridian_error' => 'error', 'meridian_created' => 'success', 'meridian_resynced' => 'success') as $key => $type) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $raw = sanitize_text_field(wp_unslash($_GET[$key]));

            if ('meridian_error' === $key) {
                $message = $raw;
            } elseif ('meridian_created' === $key) {
                $message = __('Translation created as a draft, with the source’s fields copied. Rewrite the text and publish when it is ready.', 'meridian');
            } else {
                $message = sprintf(
                    /* translators: %d: number of fields rewritten from the source. */
                    _n('%d shared field re-copied from the source.', '%d shared fields re-copied from the source.', (int) $raw, 'meridian'),
                    (int) $raw
                );
            }

            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($message));
        }
    }

    /**
     * @param array $columns
     * @return array
     */
    public function column($columns): array
    {
        if (!Registry::is_multilingual()) {
            return (array) $columns;
        }

        return (array) $columns + array('meridian_language' => __('Language', 'meridian'));
    }

    /**
     * @param string $column
     * @param int    $post_id
     */
    public function column_value($column, $post_id): void
    {
        if ('meridian_language' !== $column) {
            return;
        }

        $lang = Posts::language_of((int) $post_id);
        $language = Registry::get($lang);
        $siblings = Groups::siblings((int) $post_id);

        echo '<strong>' . esc_html($language ? $language->label() : $lang) . '</strong>';

        $others = array();
        foreach ($siblings as $code => $id) {
            if ($code === $lang) {
                continue;
            }
            $other = Registry::get($code);
            $others[] = sprintf('<a href="%s">%s</a>', esc_url((string) get_edit_post_link((int) $id)), esc_html($other ? $other->code : $code));
        }

        if ($others) {
            echo '<br><span class="description">' . wp_kses_post(implode(', ', $others)) . '</span>';
        }
    }

    private function action_url(string $action, int $post_id, array $extra = array()): string
    {
        $url = add_query_arg(array('action' => $action, 'post' => $post_id) + $extra, admin_url('admin-post.php'));
        return wp_nonce_url($url, self::NONCE . $post_id);
    }

    private function check(int $post_id): void
    {
        if ($post_id < 1 || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to do that.', 'meridian'));
        }
        check_admin_referer(self::NONCE . $post_id);
    }

    /**
     * @param array $args
     */
    private function back(int $post_id, array $args): void
    {
        wp_safe_redirect(add_query_arg($args, (string) get_edit_post_link($post_id, 'raw')));
        exit;
    }

    /**
     * @return string[]
     */
    private static function translatable_types(): array
    {
        $types = array();
        foreach (get_post_types(array('show_ui' => true), 'names') as $post_type) {
            if (Modes::SEPARATE === Modes::for_post_type($post_type)) {
                $types[] = $post_type;
            }
        }
        return $types;
    }
}
