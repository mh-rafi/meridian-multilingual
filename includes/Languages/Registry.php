<?php

namespace Meridian\Multilingual\Languages;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The configured languages, and which one is the default. (M1)
 *
 * The default language is stored as a *code*, never as a position and
 * never as a flag on a member. Two things follow, and both are the
 * point of the requirement:
 *
 *  - Changing the default is a one-value write. The old default gains a
 *    prefix, the new one loses it, and nothing else in storage moves.
 *  - "Has no prefix" is derived by prefix(), never stored, so it cannot
 *    drift out of step with the default.
 *
 * Every read goes through a filter, because that is the plugin's API
 * (spec 2) -- a site can add a language from code, or force a default
 * per request, without this class knowing.
 */
final class Registry
{
    const OPTION = 'meridian_settings';

    /** Cleared on save; a request never reads the option twice. */
    private static ?array $cache = null;

    /**
     * Every configured language, active or not, in stored order.
     *
     * @return Language[] code => Language
     */
    public static function all(): array
    {
        if (null === self::$cache) {
            $settings = get_option(self::OPTION, array());
            $stored = isset($settings['languages']) && is_array($settings['languages']) ? $settings['languages'] : array();

            $languages = array();
            foreach ($stored as $data) {
                $language = new Language((array) $data);
                if ('' !== $language->code) {
                    $languages[$language->code] = $language;
                }
            }
            self::$cache = $languages;
        }

        /**
         * Filter the configured languages.
         *
         * @param Language[] $languages code => Language, in display order.
         */
        $languages = apply_filters('meridian_languages', self::$cache);

        // A filter that returns something unusable must not take the
        // site down: fall back rather than trusting the return blindly.
        return is_array($languages) ? $languages : self::$cache;
    }

    /**
     * Only the languages that actually serve content.
     *
     * @return Language[]
     */
    public static function active(): array
    {
        return array_filter(self::all(), static fn(Language $language) => $language->active);
    }

    /**
     * @return string[] Active language codes, default first.
     */
    public static function codes(): array
    {
        $codes = array_keys(self::active());
        $default = self::default_code();

        // Default first is not cosmetic: callers that build hreflang or
        // a switcher want x-default's language at a predictable index.
        if (in_array($default, $codes, true)) {
            $codes = array_merge(array($default), array_values(array_diff($codes, array($default))));
        }

        return $codes;
    }

    public static function get(string $code): ?Language
    {
        $languages = self::all();
        return $languages[strtolower($code)] ?? null;
    }

    public static function exists(string $code): bool
    {
        return null !== self::get($code);
    }

    /**
     * The language a bare URL serves.
     */
    public static function default_code(): string
    {
        $settings = get_option(self::OPTION, array());
        $default = isset($settings['default']) ? (string) $settings['default'] : '';

        $languages = self::all();
        if ('' === $default || !isset($languages[$default])) {
            // Never return a code that is not configured: a caller that
            // trusts this would build URLs for a language that cannot
            // be served. First configured language is the safe answer.
            $default = (string) (array_key_first($languages) ?? '');
        }

        /**
         * Filter which language a bare URL serves.
         *
         * @param string $default Language code.
         */
        return (string) apply_filters('meridian_default_language', $default);
    }

    public static function default_language(): ?Language
    {
        return self::get(self::default_code());
    }

    public static function is_default(string $code): bool
    {
        return strtolower($code) === self::default_code();
    }

    /**
     * The URL prefix for a language: '' for the default, else its code.
     *
     * Derived, never stored. Routing (M2) is the only consumer that
     * matters, but the admin shows it too so that "which one has no
     * prefix" is visible on the screen that sets it.
     */
    public static function prefix(string $code): string
    {
        return self::is_default($code) ? '' : strtolower($code);
    }

    /**
     * Whether the site has anything to be multilingual about.
     *
     * One active language is a monolingual site with this plugin
     * installed, and every feature keyed on this stays switched off.
     */
    public static function is_multilingual(): bool
    {
        return count(self::active()) > 1;
    }

    /**
     * Replace the whole set.
     *
     * Whole-set rather than per-language writes: the default must name
     * a language that exists and is active, which is a property of the
     * set, so a partial write could leave the option in a state no
     * single validation pass would accept.
     *
     * @param Language[]|array[] $languages
     * @param string             $default   Code of the default language.
     * @return true|WP_Error
     */
    public static function save(array $languages, string $default)
    {
        $objects = array();
        foreach ($languages as $language) {
            $objects[] = $language instanceof Language ? $language : new Language((array) $language);
        }

        $valid = self::validate($objects, $default);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $settings = get_option(self::OPTION, array());
        $settings = is_array($settings) ? $settings : array();
        $settings['languages'] = array_map(static fn(Language $language) => $language->to_array(), $objects);
        $settings['default'] = strtolower(trim($default));

        update_option(self::OPTION, $settings);
        self::flush();

        /**
         * Fires after the language set changes.
         *
         * Routing rules are registered from the language set, so
         * anything that caches them re-registers here. M2 flushes
         * rewrite rules on this hook rather than on every admin load.
         *
         * @param Language[] $objects
         * @param string     $default
         */
        do_action('meridian_languages_saved', $objects, $settings['default']);

        return true;
    }

    /**
     * @param Language[] $languages
     * @return true|WP_Error
     */
    public static function validate(array $languages, string $default)
    {
        $default = strtolower(trim($default));

        if (!$languages) {
            return new WP_Error('meridian_no_languages', __('Add at least one language.', 'meridian'));
        }

        $seen = array();
        $reserved = self::reserved_codes();

        foreach ($languages as $language) {
            $label = $language->label() !== '' ? $language->label() : __('(unnamed)', 'meridian');

            // Two letters, optionally a regional suffix. The routing
            // rule in M2 captures the prefix from the URL, so a code
            // that is not URL-safe is not a naming preference -- it is
            // a rule that never matches.
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language->code)) {
                return new WP_Error('meridian_bad_code', sprintf(
                    /* translators: %s: the language code that was rejected. */
                    __('"%s" is not a usable language code. Use two letters, optionally with a region, e.g. es or pt-br.', 'meridian'),
                    $language->code
                ));
            }

            if (isset($seen[$language->code])) {
                return new WP_Error('meridian_duplicate_code', sprintf(
                    /* translators: %s: the duplicated language code. */
                    __('The code "%s" is used twice. Each language needs its own.', 'meridian'),
                    $language->code
                ));
            }
            $seen[$language->code] = true;

            // A prefix that shadows a real path is the failure that
            // looks like a routing bug months later: /es/ would stop
            // being a language and start being that page.
            if (isset($reserved[$language->code])) {
                return new WP_Error('meridian_reserved_code', sprintf(
                    /* translators: 1: language code, 2: what already uses that path. */
                    __('The code "%1$s" cannot be used: %2$s already answers to that path.', 'meridian'),
                    $language->code,
                    $reserved[$language->code]
                ));
            }

            if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $language->locale)) {
                return new WP_Error('meridian_bad_locale', sprintf(
                    /* translators: 1: the locale that was rejected, 2: the language it belongs to. */
                    __('"%1$s" is not a WordPress locale (%2$s). Use the form es_ES.', 'meridian'),
                    $language->locale,
                    $label
                ));
            }

            if ('' === $language->native_name) {
                return new WP_Error('meridian_no_native_name', sprintf(
                    /* translators: %s: the language being configured. */
                    __('Give %s a native name — it is what the language switcher shows.', 'meridian'),
                    $label
                ));
            }
        }

        if (!isset($seen[$default])) {
            return new WP_Error('meridian_bad_default', __('The default language must be one of the languages listed.', 'meridian'));
        }

        foreach ($languages as $language) {
            if ($language->code === $default && !$language->active) {
                return new WP_Error('meridian_inactive_default', __('The default language cannot be inactive — it is what a URL with no prefix serves.', 'meridian'));
            }
        }

        return true;
    }

    /**
     * Paths a language code must not shadow.
     *
     * Checked at save time rather than at request time: this is a
     * configuration mistake, and the only useful moment to report it is
     * while somebody is looking at the screen that caused it.
     *
     * @return array code => human description of what owns that path
     */
    public static function reserved_codes(): array
    {
        $reserved = array();

        // WordPress's own and the conventional two-letter traps.
        foreach (array('wp', 'rss', 'api') as $code) {
            $reserved[$code] = __('WordPress or a common endpoint', 'meridian');
        }

        // Top-level pages. A page at /es/ and a Spanish site at /es/ are
        // the same URL, and the page loses.
        foreach (get_posts(array(
            'post_type'        => 'page',
            'post_status'      => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page'   => -1,
            'post_parent'      => 0,
            'fields'           => 'ids',
            'suppress_filters' => true,
        )) as $page_id) {
            $slug = get_post_field('post_name', $page_id);
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', (string) $slug)) {
                $reserved[$slug] = sprintf(
                    /* translators: %s: the title of an existing page. */
                    __('the page "%s"', 'meridian'),
                    get_the_title($page_id)
                );
            }
        }

        // Post type archives and taxonomy bases, which are rewrite
        // fragments rather than posts and so are not covered above.
        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            $slug = is_array($type->rewrite) && !empty($type->rewrite['slug']) ? $type->rewrite['slug'] : $type->name;
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', (string) $slug)) {
                $reserved[$slug] = sprintf(
                    /* translators: %s: the name of a post type. */
                    __('the "%s" archive', 'meridian'),
                    $type->labels->name ?? $type->name
                );
            }
        }

        foreach (get_taxonomies(array('public' => true), 'objects') as $taxonomy) {
            $slug = is_array($taxonomy->rewrite) && !empty($taxonomy->rewrite['slug']) ? $taxonomy->rewrite['slug'] : $taxonomy->name;
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', (string) $slug)) {
                $reserved[$slug] = sprintf(
                    /* translators: %s: the name of a taxonomy. */
                    __('the "%s" taxonomy', 'meridian'),
                    $taxonomy->labels->name ?? $taxonomy->name
                );
            }
        }

        /**
         * Filter the codes a language may not use.
         *
         * The blog install owns /blog on this site (spec 8); another
         * site will have its own reserved paths.
         *
         * @param array $reserved code => description.
         */
        $reserved = apply_filters('meridian_reserved_codes', $reserved);

        return is_array($reserved) ? $reserved : array();
    }

    /**
     * Forget the cached set. Tests and the admin screen use it; nothing
     * on the front end should need to.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
