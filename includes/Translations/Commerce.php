<?php

namespace Meridian\Multilingual\Translations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Values that must never be translated. (M5)
 *
 * Translation changes presentation. It never changes commerce (spec 2).
 * Anything a customer buys, owns, downloads or is rated on resolves to
 * exactly one value, in every language.
 *
 * This is an explicit deny-list rather than a comment in a commit
 * message, because the failure it prevents is silent: a translated
 * price is not an error, it is a different price, and nobody finds out
 * until a customer is charged it. Every entry says what breaks.
 *
 * The list is additive through a filter but never subtractive -- a
 * filter cannot remove a key from it. Something that looks like a
 * reasonable exception here is exactly what this exists to refuse.
 */
final class Commerce
{
    /**
     * Keys whose value is the same in every language, and why.
     */
    private const PROTECTED = array(
        'edd_price'                  => 'the price a customer is charged',
        'edd_variable_prices'        => 'the licence tiers and their prices',
        'edd_download_files'         => 'the files a purchase delivers',
        'edd_reviews_average_rating' => 'a rating computed from reviews of one product',
        'edd_reviews_count'          => 'the number of reviews of one product',
        'initial_sales'              => 'sales made before this store existed',
        'download_lifetime_access'   => 'whether a purchase expires',
        'download_access_duration'   => 'how long a purchase stays downloadable',
        'download_access_duration_unit' => 'the unit of the access window',
        'current_version'            => 'which build a customer receives',
        '_edd_download_sales'        => 'the sales count EDD maintains',
        '_edd_download_earnings'     => 'the earnings EDD maintains',
    );

    /**
     * Key prefixes that are protected wholesale.
     *
     * 'edd_' and '_edd_' cover every key Easy Digital Downloads adds,
     * now and in versions not written yet. 'external_proof_' is the
     * marketplace figures, which are one marketplace's numbers about
     * one product.
     */
    private const PREFIXES = array('edd_', '_edd_', 'external_proof_');

    public static function is_protected(string $key): bool
    {
        if (isset(self::PROTECTED[$key])) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (0 === strpos($key, $prefix)) {
                return true;
            }
        }

        /**
         * Filter additional keys that must never be translated.
         *
         * Additive only: a false returned here cannot unprotect a key
         * on the list above.
         *
         * @param bool   $protected
         * @param string $key
         */
        return (bool) apply_filters('meridian_key_is_commerce', false, $key);
    }

    /**
     * Why a key is protected, for an admin screen to explain itself.
     */
    public static function reason(string $key): string
    {
        return self::PROTECTED[$key] ?? '';
    }

    /**
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::PROTECTED);
    }
}
