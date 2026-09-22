<?php

namespace Meridian\Multilingual;

use Meridian\Multilingual\Admin\LanguagesScreen;
use Meridian\Multilingual\Admin\Porter;
use Meridian\Multilingual\Admin\ProductTranslationBox;
use Meridian\Multilingual\Admin\SettingsScreen;
use Meridian\Multilingual\Admin\StringsScreen;
use Meridian\Multilingual\Admin\TermTranslations;
use Meridian\Multilingual\Admin\TranslationsBox;
use Meridian\Multilingual\Languages\Catalogue;
use Meridian\Multilingual\Languages\Language;
use Meridian\Multilingual\Database\Schema;
use Meridian\Multilingual\Integrations\Edd;
use Meridian\Multilingual\Integrations\EddWidgets;
use Meridian\Multilingual\Integrations\Sightline;
use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Frontend\HeadTags;
use Meridian\Multilingual\Frontend\Switcher;
use Meridian\Multilingual\Translations\Content;
use Meridian\Multilingual\Translations\Strings;
use Meridian\Multilingual\Translations\Functional;
use Meridian\Multilingual\Translations\Groups;
use Meridian\Multilingual\Routing\Links;
use Meridian\Multilingual\Routing\Queries;
use Meridian\Multilingual\Routing\Query;
use Meridian\Multilingual\Routing\Request;
use Meridian\Multilingual\Routing\Rewrites;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the plugin together.
 *
 * Deliberately thin: it constructs components and nothing else, so that
 * what a component does is readable in that component rather than here.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Rewrite rules are registered in the admin too: the rules array
        // is rebuilt whenever permalinks are saved, and a set built
        // without the language rules would drop them until the next
        // flush.
        Rewrites::register();
        Functional::register();

        // Above the admin branch: a post is deleted from the admin, from
        // WP-CLI and over REST, and a group row outliving its object is
        // the same bug in all three.
        Groups::register();
        SettingsScreen::register_paths();

        // M13's one switch: whether unreviewed machine output reaches a
        // visitor. Defaults to no, and a store selling software has a
        // different answer from a blog.
        add_filter('meridian_show_unreviewed_translations', static function ($show) {
            $settings = get_option(Registry::OPTION, array());
            return !empty($settings['show_unreviewed']) ? true : $show;
        });
        Sightline::register();
        Edd::register();
        EddWidgets::register();

        if (is_admin()) {
            new LanguagesScreen();
            new SettingsScreen();
            new StringsScreen();
            new Porter();
            new TranslationsBox();
            new ProductTranslationBox();
            new TermTranslations();
            return;
        }

        Query::register();
        Queries::register();
        Links::register();
        Content::register();
        HeadTags::register();
        Switcher::register();

        // Strings seen while rendering are persisted once, at the end,
        // so the screen lists what the site actually shows rather than
        // what somebody remembered to register.
        add_action('shutdown', array(Strings::class, 'flush_seen'));

        // <html lang="es">. Part of M8's signals, but it is the one that
        // falls straight out of knowing the language and would look
        // broken in its absence long before M8 lands.
        add_filter('language_attributes', array($this, 'language_attributes'));
    }

    /**
     * @param string $output
     */
    public function language_attributes($output): string
    {
        if (!Registry::is_multilingual()) {
            return (string) $output;
        }

        $language = Registry::get(Request::code());
        if (!$language) {
            return (string) $output;
        }

        // The locale, hyphenated, is what belongs in lang= -- es_ES is a
        // WordPress locale, es-ES is a BCP 47 language tag.
        $tag = str_replace('_', '-', $language->locale ?: $language->code);

        $output = preg_replace('/\blang="[^"]*"/', 'lang="' . esc_attr($tag) . '"', (string) $output);

        if ('rtl' === $language->direction && !str_contains($output, 'dir=')) {
            $output .= ' dir="rtl"';
        }

        return $output;
    }

    /**
     * Seed the registry so the plugin is never configured with nothing.
     *
     * The site's current locale becomes the default language. That is
     * the honest starting state: before anybody adds a second language
     * the site already has one, it has no prefix, and every URL on it
     * is unchanged. Nothing is overwritten if a set already exists --
     * reactivating a plugin must not reset its configuration.
     */
    public static function activate(): void
    {
        Schema::install();

        if (Registry::all()) {
            return;
        }

        $locale = get_locale() ?: 'en_US';
        $code = Catalogue::code_for_locale($locale);
        $known = Catalogue::find($locale);

        $language = new Language(array(
            'code'         => $code,
            'locale'       => $locale,
            'native_name'  => $known['native_name'] ?? $locale,
            'english_name' => $known['english_name'] ?? $locale,
            'direction'    => $known['direction'] ?? 'ltr',
            'active'       => true,
        ));

        // A seeded default could still collide with an existing page
        // slug, and failing activation over it would be worse than
        // storing it: the admin screen reports the clash and the site
        // is monolingual until it is fixed, which changes no URL.
        $settings = get_option(Registry::OPTION, array());
        $settings = is_array($settings) ? $settings : array();
        $settings['languages'] = array($language->to_array());
        $settings['default'] = $language->code;
        update_option(Registry::OPTION, $settings);
        Registry::flush();
    }
}
