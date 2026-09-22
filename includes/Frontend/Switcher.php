<?php

namespace Meridian\Multilingual\Frontend;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The language switcher. (M9)
 *
 * Offers only languages that have a translation of the current page,
 * and links to that translation. Never to the language home as a silent
 * fallback: a switcher that always "works" hides the fact that a page
 * was never translated, and the person it hides it from is the one who
 * could have fixed it.
 */
final class Switcher
{
    public static function register(): void
    {
        add_shortcode('meridian_switcher', array(self::class, 'shortcode'));
        add_filter('wp_nav_menu_items', array(self::class, 'nav_menu'), 10, 2);
    }

    /**
     * @param array $atts
     */
    public static function shortcode($atts = array()): string
    {
        $atts = shortcode_atts(array(
            'show'      => 'native',   // native | english | code
            'flags'     => 'no',
            'current'   => 'yes',      // include the current language
            'separator' => ' · ',
            'class'     => '',
        ), $atts, 'meridian_switcher');

        return self::render($atts);
    }

    /**
     * The template function themes call.
     *
     * @param array $args
     */
    public static function render(array $args = array()): string
    {
        $args = wp_parse_args($args, array(
            'show'      => 'native',
            'flags'     => 'no',
            'current'   => 'yes',
            'separator' => ' · ',
            'class'     => '',
        ));

        $items = self::items();

        if (count($items) < 2) {
            // One language is not a choice. Rendering a switcher with a
            // single entry says the site is multilingual and this page
            // is not, which is true but is not what a switcher is for.
            return '';
        }

        $links = array();
        foreach ($items as $item) {
            if ('yes' !== $args['current'] && $item['current']) {
                continue;
            }

            $label = esc_html($item[$args['show']] ?? $item['native']);

            if ('yes' === $args['flags'] && '' !== $item['flag']) {
                $label = '<span class="meridian-switcher__flag" aria-hidden="true">' . esc_html($item['flag']) . '</span> ' . $label;
            }

            $links[] = sprintf(
                '<a class="meridian-switcher__link%s" href="%s" hreflang="%s" lang="%s"%s>%s</a>',
                $item['current'] ? ' is-current' : '',
                esc_url($item['url']),
                esc_attr($item['hreflang']),
                esc_attr($item['hreflang']),
                $item['current'] ? ' aria-current="true"' : '',
                $label
            );
        }

        if (!$links) {
            return '';
        }

        return sprintf(
            '<nav class="meridian-switcher %s" aria-label="%s">%s</nav>',
            esc_attr($args['class']),
            esc_attr__('Language', 'meridian'),
            implode(esc_html($args['separator']), $links)
        );
    }

    /**
     * One entry per language this page can actually be read in.
     *
     * @return array<int,array{code:string,url:string,native:string,english:string,flag:string,hreflang:string,current:bool}>
     */
    public static function items(): array
    {
        $items = array();
        $current = Request::code();

        foreach (Alternates::all() as $code => $url) {
            $language = Registry::get($code);
            if (!$language || !$language->active) {
                continue;
            }

            $items[] = array(
                'code'     => $code,
                'url'      => $url,
                'native'   => $language->display_name(),
                'english'  => $language->label(),
                'flag'     => $language->flag,
                'hreflang' => str_replace('_', '-', $language->locale ?: $code),
                'current'  => $code === $current,
            );
        }

        return $items;
    }

    /**
     * Append the switcher to a nav menu.
     *
     * Opt in per menu location through the filter, because a site with
     * three menus does not want three switchers.
     *
     * @param string $items
     * @param mixed  $args
     */
    public static function nav_menu($items, $args = null): string
    {
        $location = is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '';

        /**
         * Filter which menu locations get a language switcher appended.
         *
         * @param string[] $locations
         */
        $locations = (array) apply_filters('meridian_switcher_menu_locations', array());

        if (!$location || !in_array($location, $locations, true)) {
            return (string) $items;
        }

        $switcher = self::render(array('current' => 'no'));

        return $switcher ? $items . '<li class="menu-item meridian-switcher-item">' . $switcher . '</li>' : (string) $items;
    }
}
