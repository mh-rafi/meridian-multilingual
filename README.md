# Meridian

Multilingual content for WordPress: directory-prefixed URLs, correct `hreflang`, and a storage model that does not duplicate a product a customer bought once.

- **Version** 0.1.0 · **License** GPL-2.0-or-later
- **Requires** WordPress 6.4+, PHP 8.1+
- No Composer, no build step, no third-party dependencies

> **Status:** early. The version number is honest — this runs one production store and has not been exercised against the long tail of themes and plugins. The parts most likely to need work on a site unlike that one are the routing filters and the integration layer.

---

## Why another multilingual plugin

Most of the design follows from two decisions.

**A product is not an article.** Translating an article by duplicating it is fine — an article is prose and nothing points at its row. Duplicating a *product* creates three products where the customer bought one, and orders, reviews, ratings, and file delivery all record an ID. So post types are translated one of two ways, and which one is a setting rather than a guess:

| Mode | Storage | Default for |
|---|---|---|
| `separate` | A real post row per language, linked by a translation group | `post`, `page` |
| `single` | One row; translated values live in a table, keyed by the source ID | products (`download`) |
| `none` | Not translated | everything else |

In `single` mode the cart never carries a translation ID, because there is no translation ID. Every commerce operation resolves to the one row the translated values hang off.

**The URL decides the language. Nothing else.** Never a cookie, never `Accept-Language`, never an automatic redirect. A site behind a full-page cache that varies by URL will happily serve a cached Spanish page to an English visitor if the language came from anywhere the URL does not carry — and that failure is invisible to whoever deployed it.

Two smaller rules follow from the same instinct:

- **A missing translation is a missing page, not an English one.** A prefixed URL for untranslated content is a 404, because serving the default language under `/es/` is duplicate content with a lie in the URL, and the lie is the part search engines act on. There is a filter to turn this off for a site mid-translation — it exists so the trade can be made knowingly, not because it is a reasonable default.
- **One canonical URL per language per object.** Anything resolving to the right translation group but the wrong member redirects (301) to the address that actually belongs to that URL's language.

---

## Install

Copy the plugin directory into `wp-content/plugins/` and activate. Activation creates three tables and nothing else; a site with one active language is a monolingual site with this plugin installed, and every feature stays switched off until a second language is activated.

## Configure

**Meridian → Languages.** Add languages from a built-in locale catalogue. The first is the default: it has no URL prefix and its content lives on the post row, as it always did. Every other active language gets a prefix (`/es/`, `/fr/`) and a rewrite rule for everything WordPress can already route — including whatever a plugin registered, which a hand-written rule list would miss and keep missing.

**Meridian → Settings.** Per-post-type and per-taxonomy translation modes, whether unreviewed machine output is shown to visitors, and whether uninstalling deletes data.

## Translating content

Editing screens appear on the post types you have enabled.

- **Separate-mode posts** get a Translations box: create a translation, which starts as a draft copy so a half-written page is never live at a real URL. Non-prose meta (images, chosen products, section order) is copied from the source and can be re-synced later; which keys count as prose is a property of whatever registered the fields, so it is asked for through a filter rather than guessed.
- **Single-mode posts** get a per-language panel with the title, slug, body, excerpt and every field registered as translatable. Repeaters are structurally locked to the source — same rows, same order, no add, no remove — so row 2 means the same thing in every language.
- **Terms** are translated as separate terms, since a term is a name and a slug and there is no single-source shape for one.
- **Theme and plugin strings** are collected as the site renders and listed under **Meridian → Strings**, with a count of what is still untranslated. The list reflects what the site actually shows rather than what somebody remembered to register.

Renaming a translated slug records a redirect from the old one, through the site's existing SEO plugin where there is one. Meridian does not grow its own redirect table: two redirect tables means two places to look when a URL stops working, and the answer is in whichever one you checked second.

## Provenance

Every translated value records whether a person wrote it, a machine produced it, or it was imported — and when it was reviewed.

- Machine output never silently overwrites something a person wrote or approved. The write is refused and reported, not skipped, so a bulk pass can say what it did not touch instead of looking like it succeeded.
- Unreviewed machine output is **not shown to visitors** by default. A store shipping machine copy about what a customer is buying has a different problem from a blog.
- Editing screens always show the real stored value, including unreviewed machine output, flagged as such. Hiding it from the person meant to review it makes the form render blank for a value that exists, which then gets saved as an empty string over a real row.
- Each value stores a hash of the source it was written against, so a later edit to the source marks the translation stale rather than leaving it silently wrong.

## Import and export

**Meridian → Settings** exports everything as JSON and imports it back. Objects are exported by ID, which is honest about the limitation: this moves translations between copies of the same site, not between different sites.

## Integrations

Detected, never required. The plugin works with none of them present, and every integration is guarded on the class or function actually existing.

- **Easy Digital Downloads** — products default to single-source storage; checkout, receipt and account pages are marked as readable in every language rather than duplicated, so a prefix on a checkout link never drops a visitor out of their language mid-purchase.
- **An SEO plugin** — per-language search engine title and meta description, `inLanguage` on the page schema, one redirect rule covering every language, and one sitemap entry per language per object. The bundled integration targets Sightline SEO; the shape is small enough to copy for another.
- **A field framework** — asked which meta keys hold translatable copy, so the plugin does not have to know what any given key means.

---

## Hooks

### Filters

| Filter | Answers |
|---|---|
| `meridian_current_language` | The language this request is served in |
| `meridian_default_language` | The site's source language |
| `meridian_languages` | The configured language set |
| `meridian_locale_catalogue` | The locales offered when adding a language |
| `meridian_reserved_codes` | Codes that may not be used as a language |
| `meridian_post_type_mode` | `separate` \| `single` \| `none` for a post type |
| `meridian_taxonomy_mode` | `separate` \| `none` for a taxonomy |
| `meridian_post_language` | Which language a post is written in |
| `meridian_translated_post_id` | A post's translation in one language |
| `meridian_post_available_in_every_language` | Posts served from one row under every prefix |
| `meridian_term_available_in_language` | Whether a term is readable in a language |
| `meridian_functional_pages` | Pages whose output is their shortcodes', not their content |
| `meridian_untranslatable_paths` | Paths a prefix is never added to |
| `meridian_meta_key_is_translatable` | Whether a meta key holds prose |
| `meridian_meta_key_is_internal` | Meta keys never copied to a translation |
| `meridian_key_is_commerce` | Keys a translator may never write |
| `meridian_show_unreviewed_translations` | Whether unreviewed machine output reaches visitors |
| `meridian_alternates` | The `hreflang` set for the current request |
| `meridian_switcher_menu_locations` | Menus the language switcher is appended to |

`404`-ing untranslated content under a prefix is filterable too, via `meridian_404_untranslated`.

### Actions

| Action | Fires when |
|---|---|
| `meridian_languages_saved` | The language set changed |
| `meridian_object_linked` | An object joined a translation group |
| `meridian_translation_created` | A translation post was created |
| `meridian_term_translation_created` | A translation term was created |
| `meridian_field_saved` | A translated value was written |
| `meridian_slug_changed` | A translated slug changed (carries the old one) |

---

## Database

Three tables, prefixed like the rest of WordPress:

| Table | Holds |
|---|---|
| `meridian_translations` | Which object is which language, and which group it belongs to |
| `meridian_fields` | Translated values for objects that are never duplicated, with origin and review state |
| `meridian_strings` | Interface strings seen while rendering, and their translations |

## Uninstall

Keeps everything by default. Deleting a site's translations because somebody deactivated a plugin to test something is not a tidy-up, it is data loss with no undo — and the translations are the expensive part, not the code. Enable **delete data on uninstall** in Settings first if you want the tables dropped.

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
