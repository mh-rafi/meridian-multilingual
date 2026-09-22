<?php

namespace Meridian\Multilingual\Languages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One configured language.
 *
 * A value object, not a record: the registry owns storage, this owns
 * shape. Nothing here knows which language is the default, because that
 * is a property of the set rather than of a member -- the same reason
 * the translations table keys on a group_id rather than a "source"
 * column (spec 5.5). Ask the registry.
 */
final class Language
{
    /** URL prefix and the value stored in the `lang` columns, e.g. 'es'. */
    public string $code = '';

    /** WordPress locale used to load .mo files, e.g. 'es_ES'. */
    public string $locale = '';

    /** What speakers call it, e.g. 'Español'. Shown in the switcher. */
    public string $native_name = '';

    /** What the admin calls it, e.g. 'Spanish'. Shown in the admin. */
    public string $english_name = '';

    /** 'ltr' or 'rtl'. */
    public string $direction = 'ltr';

    /**
     * An ISO country code for a flag, or '' for none.
     *
     * A code rather than an image path: flags are a rendering choice the
     * switcher makes, and a site that would rather show no flags at all
     * (the honest default, since a flag is a country and a language is
     * not) should not have image paths in its settings to clean up.
     */
    public string $flag = '';

    /** Inactive languages are configured but serve nothing. */
    public bool $active = true;

    /**
     * @param array $data Stored or submitted values.
     */
    public function __construct(array $data = array())
    {
        $this->code         = isset($data['code']) ? strtolower(trim((string) $data['code'])) : '';
        $this->locale       = isset($data['locale']) ? trim((string) $data['locale']) : '';
        $this->native_name  = isset($data['native_name']) ? trim((string) $data['native_name']) : '';
        $this->english_name = isset($data['english_name']) ? trim((string) $data['english_name']) : '';
        $this->direction    = isset($data['direction']) && 'rtl' === $data['direction'] ? 'rtl' : 'ltr';
        $this->flag         = isset($data['flag']) ? strtolower(trim((string) $data['flag'])) : '';
        $this->active       = !isset($data['active']) || (bool) $data['active'];
    }

    /**
     * The name to show an admin: English name, falling back to native.
     */
    public function label(): string
    {
        return $this->english_name !== '' ? $this->english_name : ($this->native_name !== '' ? $this->native_name : $this->code);
    }

    /**
     * The name to show a visitor. Always the native name where there is
     * one -- a switcher that offers "Spanish" to a Spanish speaker is
     * written for the site owner, not the reader.
     */
    public function display_name(): string
    {
        return $this->native_name !== '' ? $this->native_name : $this->label();
    }

    /**
     * @return array Storage shape.
     */
    public function to_array(): array
    {
        return array(
            'code'         => $this->code,
            'locale'       => $this->locale,
            'native_name'  => $this->native_name,
            'english_name' => $this->english_name,
            'direction'    => $this->direction,
            'flag'         => $this->flag,
            'active'       => $this->active,
        );
    }
}
