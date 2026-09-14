# GoHighLevel Integration

Pulls contacts out of a GoHighLevel (LeadConnector) sub-account and renders them
as a filterable directory on any page, via `[ghl_directory]`.

Out of the box it is configured for this site: it lists only contacts tagged
**`member - physician`**, and takes each headshot from the
**`contact.member_profile_photo`** custom field. Both are editable under
Settings → GoHighLevel, and either can be overridden per shortcode.

```
.
├── build-zip.sh                  # packages the plugin into an installable zip
├── tests/                        # stubs.php + run-tests.php (logic suite), test_plugin.py
└── gohighlevel-integration/      # the plugin itself
    ├── gohighlevel-integration.php   # bootstrap: constants, includes, cron, activation
    ├── uninstall.php
    ├── readme.txt                    # WordPress-style plugin readme
    ├── includes/
    │   ├── class-ghld-settings.php   # the ghld_settings option + constant overrides
    │   ├── class-ghld-client.php     # GoHighLevel REST client (v2 and legacy v1)
    │   ├── class-ghld-contact.php    # raw API payload -> normalized contact
    │   ├── class-ghld-repository.php # cache, sync, filter/sort/paginate, facets
    │   ├── class-ghld-template.php   # template loading with theme overrides
    │   ├── class-ghld-shortcode.php  # [ghl_directory] + scope/request plumbing
    │   ├── class-ghld-rest.php       # /wp-json/gohighlevel-integration/v1/contacts
    │   └── class-ghld-admin.php      # Settings -> GoHighLevel
    ├── templates/                    # directory, filter-bar, results, contact-card, pagination
    └── assets/                       # css + js
```

Internals keep the `GHLD_` / `ghld_` / `.ghld-` prefixes (GoHighLevel Directory)
— class names, option keys and CSS classes. Renaming them would churn every file
without changing behaviour, and they still describe what the feature is.

## Install

1. `./build-zip.sh` (or zip the `gohighlevel-integration` directory yourself), then upload
   it under Plugins → Add New → Upload Plugin, and activate.
2. In GoHighLevel, in the **sub-account** you want to list: Settings → Private
   Integrations → create a token with the `contacts.readonly` scope. Add
   `locations/customFields.readonly` too if you want custom fields (job title,
   headshot URL, …) available for mapping and filtering.
3. In WordPress: Settings → GoHighLevel. Choose API v2, paste the token, enter
   the Location ID, **Save**, then **Sync now**.
4. Put `[ghl_directory]` on a page.

Legacy sub-accounts that still use a v1 API key (Settings → Business Info → API
Key) can pick "v1" instead; the key is already location-scoped so no Location ID
is needed.

### Keeping the token out of the database

```php
// wp-config.php
define( 'GHLD_API_TOKEN', 'pit-...' );
define( 'GHLD_LOCATION_ID', 'abc123...' );
```

When the constant is set the admin field is replaced with a note, and the token
never appears in a database dump or in the settings form.

## Shortcode

```
[ghl_directory]

[ghl_directory tags="member - physician" columns="4" per_page="36"
               filters="search,tag,city,sort"
               show="photo,title,company,location,tags"
               layout="grid" orderby="name" order="asc"]
```

| Attribute | Default | What it does |
| --- | --- | --- |
| `tags` | setting | Only list contacts carrying any of these GoHighLevel tags (added on top of the global include list). |
| `exclude_tags` | setting | Never list contacts carrying these tags. |
| `filters` | setting | Filter-bar controls: `search`, `tag`, `city`, `state`, `company`, `sort`, `cf:<field_key>`. `none` hides the bar. |
| `show` | setting | Card contents: `photo`, `title`, `company`, `location`, `tags`, `bio`, `email`, `phone`, `website`, `cf:<field_key>`. |
| `columns` | 3 | Grid columns on wide screens (1–6); narrower screens auto-fit. |
| `per_page` | 24 | Contacts per page (1–200). |
| `orderby` / `order` | `first_name` / `asc` | `first_name` (the name as printed), `name` (surname), `company` or `date_added`; `asc` or `desc`. |
| `layout` | `grid` | `grid` or `list`. |
| `name_format` | `name_title` | `name_title` prints "Emily Billingsley, MD"; `name` puts the title on its own line. |
| `modal` | `yes` | `no` makes cards non-clickable and ships no detail markup. |
| `modal_show` | setting | What the detail modal lists — same element names as `show`. |
| `search` | — | A search term always applied to this directory, on top of whatever a visitor types. |
| `empty` | — | Message shown when nothing matches. |

Custom field keys come from GoHighLevel's `fieldKey` with the `contact.` prefix
stripped — `contact.job_title` is used as `cf:job_title`. The Settings screen
lists the discovered fields by name after the first sync.

## How it fits together

- **Sync.** `GHLD_Repository::sync()` pulls every contact through the paginated
  `/contacts/` endpoint, normalizes each one (`GHLD_Contact`), and stores the set
  in the non-autoloaded `ghld_contacts` option. WP-Cron re-runs it hourly; a page
  view triggers it when the cache is older than the configured lifetime.
- **Never blocks the page.** A failed sync is recorded and the previously cached
  set keeps rendering, with a 5-minute back-off before the next attempt. A
  partial API response is discarded rather than replacing a full directory.
- **Filtering.** Filtering, sorting, faceting and pagination all run in PHP over
  the cached array — no API call per request.
- **Filter bar.** The bar is a plain GET form that works with JavaScript off. With
  JavaScript on, `assets/js/gohighlevel-integration.js` fetches
  `/wp-json/gohighlevel-integration/v1/contacts` and swaps in server-rendered fragments, so
  cards have exactly one implementation (`templates/contact-card.php`).
- **Scope safety.** The REST endpoint doesn't accept tag arguments. Each rendered
  shortcode registers its resolved scope under an opaque instance key, and the
  endpoint only ever queries within the scope that key resolves to — a visitor
  can't widen a deliberately narrowed directory by editing the request.
- **Re-mapping is free.** Changing which field holds the photo/title/bio
  re-derives those values from the cached contacts; only credential changes force
  a re-fetch.
- **Detail modal.** Each card carries its own detail panel, rendered server-side
  by `templates/contact-detail.php` and hidden until the card is clicked. Opening
  a contact costs no request, survives the grid being swapped out by a filter,
  and can never reach a contact outside the directory's scope. Without
  JavaScript the cards are simply not clickable; nothing else changes.
- **Specialty.** A mapped *Primary specialty* field prints as an italic
  subtitle under the name in the modal. GoHighLevel multi-select values arrive
  as a comma-separated list, so one field covers "Infectious Disease, Internal
  Medicine".
- **The name line.** By default the card reads "Name, Title" — the title being
  whatever field is mapped as *Job title*, with nothing printed when that field
  is unmapped or empty for a contact.
- **Names.** GoHighLevel records are often imported all-lowercase, and its own
  `fullNameLowerCase` field is lowercase by definition. Names with no capital at
  all are title-cased for display; any name that already carries one is printed
  exactly as stored.

## Privacy

A GoHighLevel location is a CRM, so most of its contacts are not meant to be
public. The defaults are deliberately conservative:

- **"Only include tags" ships set to `member - physician`**, so only contacts
  carrying that tag are ever listed. If you clear it, *every* synced contact
  becomes listable — don't, unless that is really what you want.
- Email and phone are **off** by default in the card settings.
- The Gravatar fallback is **off** by default, because it sends a hash of each
  listed contact's email address to gravatar.com.

## Customizing

Copy any file from `templates/` into `your-theme/gohighlevel-integration/` to override it.
Styling is driven by custom properties on `.ghld-directory`
(`--ghld-columns`, `--ghld-gap`, `--ghld-radius`, `--ghld-border`,
`--ghld-surface`, `--ghld-muted`, `--ghld-avatar-size`), so a theme can restyle
the directory without fighting selectors.

Hooks:

| Hook | Type | Purpose |
| --- | --- | --- |
| `ghld_normalize_contact` | filter | Adjust a contact after normalization, before caching. |
| `ghld_directory_scope` | filter | Adjust a shortcode's resolved scope. |
| `ghld_template_candidates` | filter | Change the template lookup order. |
| `ghld_after_sync` | action | Runs after a successful sync with the normalized set. |

## Tests

```
python3 -m pytest tests/
```

`tests/test_plugin.py` lints every PHP file and checks the plugin's structural
invariants (direct-access guards, version consistency across the header/constant/
readme.txt, escaped template output, a REST route that takes no tag scope of its
own). It also runs `tests/run-tests.php`, the logic suite: 126 assertions driving
the real classes against stubbed WordPress functions in `tests/stubs.php` — no
WordPress install and no network needed. Run that suite alone with:

```
php tests/run-tests.php
```
