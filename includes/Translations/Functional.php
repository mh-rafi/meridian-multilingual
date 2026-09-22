<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pages that are never duplicated per language. (M6)
 *
 * A checkout page is not content. Easy Digital Downloads decides
 * edd_is_checkout() by comparing against the page ID in its own
 * settings, so a duplicated checkout is not a checkout -- it is a page
 * with a checkout shortcode on it and none of EDD's behaviour (spec
 * 5.3). The same is true of the receipt, purchase history, registration
 * and invoice pages.
 *
 * They hold 14-268 bytes of content each. What a visitor reads is the
 * *output* of their shortcodes, which is PHP and therefore gettext.
 *
 * Two consequences, and both are the point:
 *
 *   They are excluded from translation. No "create translation", and
 *   the duplicator refuses them even if something asks.
 *
 *   They are reachable under every language prefix. One page, many
 *   URLs -- /es/checkout/ resolves to the same page ID, so
 *   edd_is_checkout() still matches, Sightline still noindexes it, and
 *   a Spanish visitor's session does not end in English mid-purchase.
 *
 * Detection follows whatever setting binds the page, never a list of
 * IDs: an admin who repoints EDD's checkout at a different page has
 * moved the exclusion with it.
 */
final class Functional
{
    /**
     * @return array id => reason, for the admin to show
     */
    public static function all(): array
    {
        static $pages = null;

        if (null !== $pages) {
            return $pages;
        }

        $pages = array();

        foreach (self::stored_ids() as $id) {
            $pages[$id] = __('marked as a shared page in Meridian’s settings', 'meridian');
        }

        /**
         * Filter the pages that are never duplicated per language.
         *
         * Integrations add the pages their own settings bind. Keyed by
         * post ID, valued with a short human reason so the editor can
         * say why translation is not offered.
         *
         * @param array $pages id => reason
         */
        $pages = (array) apply_filters('meridian_functional_pages', $pages);

        $clean = array();
        foreach ($pages as $id => $reason) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = (string) $reason;
            }
        }

        return $pages = $clean;
    }

    public static function is_functional(int $post_id): bool
    {
        return $post_id > 0 && isset(self::all()[$post_id]);
    }

    /**
     * Why this page is not translated, for the editor.
     */
    public static function reason(int $post_id): string
    {
        return self::all()[$post_id] ?? '';
    }

    /**
     * @return int[]
     */
    public static function stored_ids(): array
    {
        $settings = get_option(Registry::OPTION, array());
        $ids = isset($settings['shared_pages']) && is_array($settings['shared_pages']) ? $settings['shared_pages'] : array();

        return array_values(array_filter(array_map('absint', $ids)));
    }

    /**
     * @param int[] $ids
     */
    public static function save_stored_ids(array $ids): void
    {
        $settings = get_option(Registry::OPTION, array());
        $settings = is_array($settings) ? $settings : array();
        $settings['shared_pages'] = array_values(array_unique(array_filter(array_map('absint', $ids))));

        update_option(Registry::OPTION, $settings);
    }

    /**
     * Wire the consequences.
     */
    public static function register(): void
    {
        add_filter('meridian_post_available_in_every_language', array(self::class, 'available_everywhere'), 10, 2);
    }

    /**
     * @param bool $available
     * @param int  $post_id
     */
    public static function available_everywhere($available, $post_id): bool
    {
        return $available || self::is_functional((int) $post_id);
    }
}
