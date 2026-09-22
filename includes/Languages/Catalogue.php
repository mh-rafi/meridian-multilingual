<?php

namespace Meridian\Multilingual\Languages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Known locales, for prefilling the admin form.
 *
 * Convenience only. Nothing here is authoritative and nothing reads it
 * at request time -- a language is whatever the registry stores, which
 * is why the form lets every field be overridden after a prefill.
 *
 * Two sources, in order:
 *
 *  1. wp_get_available_translations(), which is every locale
 *     wordpress.org publishes, with native names. It needs the network
 *     and is admin-only, and on this project it will usually be
 *     unavailable: translations are entered on production by hand and
 *     that host may not reach the API.
 *  2. The built-in list below, which always works.
 *
 * So the fallback is the one that has to be right, not the nice path.
 */
final class Catalogue
{
    /**
     * Enough to configure a site without the network.
     *
     * Spanish first because it is this project's launch language
     * (spec 3); the rest are the locales most likely to be asked for
     * next. A locale absent from here is still addable by typing it.
     */
    private const KNOWN = array(
        'es_ES' => array('code' => 'es', 'native_name' => 'Español',    'english_name' => 'Spanish',             'direction' => 'ltr'),
        'es_MX' => array('code' => 'es', 'native_name' => 'Español de México', 'english_name' => 'Spanish (Mexico)', 'direction' => 'ltr'),
        'en_US' => array('code' => 'en', 'native_name' => 'English',    'english_name' => 'English',             'direction' => 'ltr'),
        'en_GB' => array('code' => 'en', 'native_name' => 'English (UK)', 'english_name' => 'English (UK)',      'direction' => 'ltr'),
        'fr_FR' => array('code' => 'fr', 'native_name' => 'Français',   'english_name' => 'French',              'direction' => 'ltr'),
        'de_DE' => array('code' => 'de', 'native_name' => 'Deutsch',    'english_name' => 'German',              'direction' => 'ltr'),
        'pt_BR' => array('code' => 'pt', 'native_name' => 'Português do Brasil', 'english_name' => 'Portuguese (Brazil)', 'direction' => 'ltr'),
        'it_IT' => array('code' => 'it', 'native_name' => 'Italiano',   'english_name' => 'Italian',             'direction' => 'ltr'),
        'nl_NL' => array('code' => 'nl', 'native_name' => 'Nederlands', 'english_name' => 'Dutch',               'direction' => 'ltr'),
        'ru_RU' => array('code' => 'ru', 'native_name' => 'Русский',    'english_name' => 'Russian',             'direction' => 'ltr'),
        'tr_TR' => array('code' => 'tr', 'native_name' => 'Türkçe',     'english_name' => 'Turkish',             'direction' => 'ltr'),
        'ja'    => array('code' => 'ja', 'native_name' => '日本語',      'english_name' => 'Japanese',            'direction' => 'ltr'),
        'zh_CN' => array('code' => 'zh', 'native_name' => '简体中文',    'english_name' => 'Chinese (China)',     'direction' => 'ltr'),
        'ar'    => array('code' => 'ar', 'native_name' => 'العربية',     'english_name' => 'Arabic',              'direction' => 'rtl'),
        'he_IL' => array('code' => 'he', 'native_name' => 'עִבְרִית',       'english_name' => 'Hebrew',              'direction' => 'rtl'),
        'fa_IR' => array('code' => 'fa', 'native_name' => 'فارسی',      'english_name' => 'Persian',             'direction' => 'rtl'),
        'ur'    => array('code' => 'ur', 'native_name' => 'اردو',        'english_name' => 'Urdu',                'direction' => 'rtl'),
        'hi_IN' => array('code' => 'hi', 'native_name' => 'हिन्दी',        'english_name' => 'Hindi',               'direction' => 'ltr'),
        'bn_BD' => array('code' => 'bn', 'native_name' => 'বাংলা',       'english_name' => 'Bengali',             'direction' => 'ltr'),
        'id_ID' => array('code' => 'id', 'native_name' => 'Bahasa Indonesia', 'english_name' => 'Indonesian',    'direction' => 'ltr'),
        'pl_PL' => array('code' => 'pl', 'native_name' => 'Polski',     'english_name' => 'Polish',              'direction' => 'ltr'),
    );

    /**
     * Every locale worth offering, merged from both sources.
     *
     * @return array locale => array(code, native_name, english_name, direction)
     */
    public static function all(): array
    {
        $catalogue = self::KNOWN;

        if (function_exists('wp_get_available_translations')) {
            // Cached in a transient by core, so this is not a request to
            // the API on every admin load.
            $available = wp_get_available_translations();
            if (is_array($available)) {
                foreach ($available as $locale => $data) {
                    $catalogue[$locale] = array(
                        'code'         => self::code_for_locale($locale),
                        'native_name'  => $data['native_name'] ?? $locale,
                        'english_name' => $data['english_name'] ?? $locale,
                        // Core does not publish direction, and guessing
                        // it from the locale is a list this would then
                        // have to maintain. The built-in entries above
                        // keep theirs; anything else defaults to ltr and
                        // is a dropdown away from being corrected.
                        'direction'    => self::KNOWN[$locale]['direction'] ?? 'ltr',
                    );
                }
            }
        }

        uasort($catalogue, static fn($a, $b) => strcasecmp($a['english_name'], $b['english_name']));

        /**
         * Filter the locales offered in the admin.
         *
         * @param array $catalogue locale => language data.
         */
        $catalogue = apply_filters('meridian_locale_catalogue', $catalogue);

        return is_array($catalogue) ? $catalogue : self::KNOWN;
    }

    /**
     * What is known about one locale, or an empty array.
     */
    public static function find(string $locale): array
    {
        $catalogue = self::all();
        return $catalogue[$locale] ?? array();
    }

    /**
     * The URL prefix a locale suggests: es_ES -> es, pt_BR -> pt.
     *
     * A suggestion, not a rule. Two locales of one language collapse to
     * the same code, which is correct for a site serving one of them and
     * wrong for a site serving both -- so the admin can always override
     * it, and the registry rejects the duplicate if they do not.
     */
    public static function code_for_locale(string $locale): string
    {
        if (isset(self::KNOWN[$locale]['code'])) {
            return self::KNOWN[$locale]['code'];
        }

        $code = strtolower(substr($locale, 0, 2));
        return preg_match('/^[a-z]{2}$/', $code) ? $code : '';
    }
}
