<?php

namespace Meridian\Multilingual\Integrations;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Request;
use Meridian\Multilingual\Translations\Commerce;
use Meridian\Multilingual\Translations\Fields;
use Meridian\Multilingual\Translations\Groups;
use Meridian\Multilingual\Translations\Modes;
use Meridian\Multilingual\Translations\Posts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The edd-widgets field layer, where it is present.
 *
 * Detected, never required -- this plugin is built to be published and
 * must work on a site that has never heard of edd-widgets. What that
 * plugin provides is the one thing Meridian cannot work out for itself:
 * which of a post's ~190 meta keys hold prose and which hold an image
 * ID, a link or a price.
 *
 * It also owns the two editing screens that have to learn about
 * translations: the Homepage Content box is gated on a list of front
 * page IDs, and a translated front page is not on it by default.
 */
final class EddWidgets
{
    public static function register(): void
    {
        if (!self::available()) {
            return;
        }

        add_filter('meridian_meta_key_is_translatable', array(self::class, 'is_translatable'), 10, 2);
        add_filter('ul_front_page_ids', array(self::class, 'front_page_ids'));
        add_filter('ul_pre_field_read', array(self::class, 'field_read'), 10, 3);
    }

    public static function available(): bool
    {
        return function_exists('ul_field_definitions') && function_exists('ul_field_is_translatable');
    }

    /**
     * Answer from the field schema rather than by guessing.
     *
     * Repeater sub-values are stored as '<name>_<index>_<sub>', which is
     * not a key the schema lists, so the index is stripped and the
     * sub-field looked up on its parent. Without this every repeater
     * row would be treated as shared and a resync would overwrite the
     * translator's work on all of them.
     *
     * @param bool   $translatable
     * @param string $key
     */
    public static function is_translatable($translatable, $key): bool
    {
        $definitions = ul_field_definitions();

        if (isset($definitions[$key])) {
            return ul_field_is_translatable($definitions[$key]);
        }

        if (preg_match('/^(.+)_(\d+)_(.+)$/', (string) $key, $m)) {
            $parent = $definitions[$m[1]] ?? null;
            if ($parent && isset($parent['sub_fields'][$m[3]])) {
                return ul_field_is_translatable($parent['sub_fields'][$m[3]]);
            }
        }

        return (bool) $translatable;
    }

    /**
     * Serve a translated custom field. (M5)
     *
     * The one seam edd-widgets exposes for this, and it is enough for
     * all ~190 content fields because every one of them is read through
     * ul_field_read() -- repeater sub-values included, since a repeater
     * resolves one sub-key at a time.
     *
     * Two kinds of caller:
     *
     *   An int post ID, from the front end. Answered in the current
     *   language for a single-source post, and left alone otherwise.
     *
     *   A '<lang>:<id>' string, from the translation editor. Answered
     *   in that language whatever the request's own language is. The
     *   claimant owns every key read against its context, so anything
     *   untranslated falls back by re-reading against the source ID --
     *   which is why the fallback passes an int, or it would claim its
     *   own fallback and never terminate.
     *
     * @param mixed  $pre
     * @param string $selector
     * @param mixed  $post_id
     * @return mixed
     */
    public static function field_read($pre, $selector, $post_id)
    {
        if (null !== $pre) {
            return $pre;
        }

        // Never. A translated price is not an error, it is a different
        // price, and nobody finds out until a customer is charged it.
        if (Commerce::is_protected((string) $selector)) {
            return null;
        }

        if (is_string($post_id) && preg_match('/^([a-z]{2}(?:-[a-z]{2})?):(\d+)$/', $post_id, $m)) {
            return self::translated_field((int) $m[2], $m[1], (string) $selector, true);
        }

        if (is_admin() || !is_numeric($post_id) || !Registry::is_multilingual()) {
            return null;
        }

        $lang = Request::code();
        if (Registry::is_default($lang)) {
            return null;
        }

        return self::translated_field((int) $post_id, $lang, (string) $selector, false);
    }

    /**
     * @param bool $fall_back_to_source Whether an untranslated key reads
     *                                  the source value rather than null.
     * @return mixed
     */
    private static function translated_field(int $post_id, string $lang, string $selector, bool $fall_back_to_source)
    {
        if ($post_id < 1 || !Registry::exists($lang) || Registry::is_default($lang)) {
            return $fall_back_to_source ? ul_field_read($selector, $post_id) : null;
        }

        if (!Modes::is_single_source((string) get_post_type($post_id))) {
            return $fall_back_to_source ? ul_field_read($selector, $post_id) : null;
        }

        $value = Fields::get($post_id, $lang, $selector);

        if (null !== $value && '' !== $value) {
            return $value;
        }

        // Untranslated. On the front end that means "not mine" so the
        // stored source value is read normally; in the editor it means
        // showing the source so a translator can see what they are
        // translating.
        return $fall_back_to_source ? ul_field_read($selector, $post_id) : null;
    }

    /**
     * Give every translated front page the Homepage Content box.
     *
     * ul_is_front_page_id() gates both the meta box and its saver, so
     * without this a translated front page gets no editor and anything
     * typed into it is silently discarded on save (spec 5.6).
     *
     * @param array $ids
     * @return array
     */
    public static function front_page_ids($ids): array
    {
        $ids = (array) $ids;

        if (!Registry::is_multilingual() || 'page' !== get_option('show_on_front')) {
            return $ids;
        }

        // The stored option, not ul_front_page_ids() and not a filtered
        // read: this runs inside that filter.
        $front = (int) get_option('page_on_front');
        if ($front < 1) {
            return $ids;
        }

        foreach (Groups::siblings($front) as $translation_id) {
            $ids[] = (int) $translation_id;
        }

        return $ids;
    }
}
