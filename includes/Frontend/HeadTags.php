<?php

namespace Meridian\Multilingual\Frontend;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * hreflang, x-default and og:locale. (M8)
 *
 * Meridian owns these because it is the only thing that knows the
 * translation set. It emits them through Sightline's head when Sightline
 * is present and on its own wp_head when it is not, so there is one
 * block of head output rather than two competing ones (spec 7).
 *
 * Never a canonical. Each language self-canonicalises, which falls out
 * of M2's link filters for free; canonicalling a translation to the
 * default language de-indexes it.
 */
final class HeadTags
{
    public static function register(): void
    {
        if (has_filter('sightline_head_tags') || class_exists('Sightline\\SEO\\Plugin')) {
            add_filter('sightline_head_tags', array(self::class, 'add_to_sightline'));
            return;
        }

        add_action('wp_head', array(self::class, 'output'), 1);
    }

    /**
     * Drop another plugin's og:locale before adding ours.
     *
     * Sightline emits og:locale from get_locale(), which is the site's
     * locale and not this request's language. Two og:locale tags on one
     * page is worse than either alone: a crawler takes the first, and
     * the first is the wrong one. Meridian owns this tag (spec 7), so
     * it replaces rather than appends.
     *
     * @param array $tags
     * @return array
     */
    private static function without_locale(array $tags): array
    {
        return array_values(array_filter($tags, static function ($tag) {
            return false === strpos((string) $tag, 'property="og:locale"');
        }));

        add_action('wp_head', array(self::class, 'output'), 1);
    }

    /**
     * @param array $tags
     * @return array
     */
    public static function add_to_sightline($tags): array
    {
        $ours = self::tags();

        if (!$ours) {
            return (array) $tags;
        }

        return array_merge(self::without_locale((array) $tags), $ours);
    }

    public static function output(): void
    {
        foreach (self::tags() as $tag) {
            echo $tag . "\n";
        }
    }

    /**
     * @return string[]
     */
    public static function tags(): array
    {
        if (!Registry::is_multilingual()) {
            return array();
        }

        $alternates = Alternates::all();

        // One language is not an alternate set. A page that exists only
        // in English does not announce itself as the English version of
        // anything -- there is nothing to be the other version of.
        if (count($alternates) < 2) {
            return array();
        }

        $tags = array();
        $default = Registry::default_code();

        foreach ($alternates as $code => $url) {
            $language = Registry::get($code);
            if (!$language) {
                continue;
            }

            $tags[] = sprintf(
                '<link rel="alternate" hreflang="%s" href="%s" />',
                esc_attr(self::tag_for($language->locale ?: $code)),
                esc_url($url)
            );
        }

        // x-default points at the default language, where it exists.
        if (isset($alternates[$default])) {
            $tags[] = sprintf('<link rel="alternate" hreflang="x-default" href="%s" />', esc_url($alternates[$default]));
        }

        $current = Registry::get(Request::code());
        if ($current) {
            $tags[] = sprintf('<meta property="og:locale" content="%s" />', esc_attr($current->locale ?: $current->code));

            foreach ($alternates as $code => $url) {
                if ($code === $current->code) {
                    continue;
                }
                $other = Registry::get($code);
                if ($other) {
                    $tags[] = sprintf('<meta property="og:locale:alternate" content="%s" />', esc_attr($other->locale ?: $other->code));
                }
            }
        }

        return $tags;
    }

    /**
     * es_ES is a WordPress locale; es-ES is the BCP 47 tag hreflang wants.
     */
    private static function tag_for(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
