<?php

namespace Meridian\Multilingual\Admin;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Translations\Groups;
use Meridian\Multilingual\Translations\Modes;
use WP_Query;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps translations out of the admin list tables.
 *
 * A translation is a post of its own, so every language multiplies the
 * rows in Posts and Pages. The list opens on the default language, and
 * the Language column (TranslationsBox) already links each row to its
 * translations. A Language dropdown switches to one language or to all.
 *
 * Only on post types stored as separate posts, and only while more than
 * one language is active, so a monolingual site's lists are untouched.
 */
final class ListFilter
{
    const PARAM = 'meridian_lang';

    const ALL = 'all';

    public function __construct()
    {
        add_action('pre_get_posts', array($this, 'limit'));
        add_action('restrict_manage_posts', array($this, 'dropdown'), 10, 1);

        foreach (get_post_types(array('show_ui' => true), 'names') as $post_type) {
            if (Modes::SEPARATE === Modes::for_post_type($post_type)) {
                add_filter("views_edit-{$post_type}", array($this, 'keep_language_in_views'));
            }
        }
    }

    /**
     * @param WP_Query $query
     */
    public function limit($query): void
    {
        global $pagenow;

        if (!is_admin() || 'edit.php' !== $pagenow || !$query->is_main_query() || !Registry::is_multilingual()) {
            return;
        }

        $post_type = (string) $query->get('post_type');
        if ('' === $post_type) {
            $post_type = 'post';
        }
        if (Modes::SEPARATE !== Modes::for_post_type($post_type)) {
            return;
        }

        $choice = $this->choice();

        // A search looks across languages: someone typing an Arabic
        // title should find it without first switching the dropdown.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, to choose what a list shows.
        if (self::ALL === $choice || (!isset($_GET[self::PARAM]) && '' !== (string) $query->get('s'))) {
            return;
        }

        if (Registry::is_default($choice)) {
            $others = array();
            foreach (Registry::codes() as $code) {
                if (!Registry::is_default($code)) {
                    $others = array_merge($others, Groups::ids_in_language($code));
                }
            }
            if ($others) {
                $query->set('post__not_in', array_merge((array) $query->get('post__not_in'), $others));
            }
            return;
        }

        // 0 so that a language with no posts yet shows an empty list
        // rather than an unfiltered one (an empty post__in is ignored).
        $query->set('post__in', Groups::ids_in_language($choice) ?: array(0));
    }

    /**
     * @param string $post_type
     */
    public function dropdown($post_type): void
    {
        if (!Registry::is_multilingual() || Modes::SEPARATE !== Modes::for_post_type((string) $post_type)) {
            return;
        }

        $choice = $this->choice();

        echo '<label class="screen-reader-text" for="meridian-lang-filter">' . esc_html__('Filter by language', 'meridian') . '</label>';
        echo '<select name="' . esc_attr(self::PARAM) . '" id="meridian-lang-filter">';
        printf('<option value="%s"%s>%s</option>', esc_attr(self::ALL), selected($choice, self::ALL, false), esc_html__('All languages', 'meridian'));
        foreach (Registry::active() as $code => $language) {
            printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($choice, $code, false), esc_html($language->label()));
        }
        echo '</select>';
    }

    /**
     * The All / Published / Draft links drop the query string, which
     * would send the list back to the default language on every click.
     *
     * @param array $views
     * @return array
     */
    public function keep_language_in_views($views): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        if (!isset($_GET[self::PARAM])) {
            return (array) $views;
        }

        $choice = $this->choice();

        return array_map(static function ($link) use ($choice) {
            return preg_replace_callback('/href=(["\'])(.*?)\1/', static function ($m) use ($choice) {
                return 'href=' . $m[1] . esc_url(add_query_arg(self::PARAM, $choice, html_entity_decode($m[2]))) . $m[1];
            }, (string) $link);
        }, (array) $views);
    }

    /**
     * Which language the list is showing: 'all', or a language code.
     * The default language when nothing is chosen.
     */
    private function choice(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, to choose what a list shows.
        $raw = isset($_GET[self::PARAM]) ? sanitize_key(wp_unslash($_GET[self::PARAM])) : '';

        if (self::ALL === $raw) {
            return self::ALL;
        }

        return ('' !== $raw && Registry::exists($raw)) ? $raw : Registry::default_code();
    }
}
