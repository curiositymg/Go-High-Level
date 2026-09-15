=== GoHighLevel Integration ===
Contributors: curiositymarketinggroup
Tags: gohighlevel, highlevel, leadconnector, directory, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pull contacts from GoHighLevel and display them as a filterable directory with the [ghl_directory] shortcode.

== Description ==

Syncs contacts from a GoHighLevel (LeadConnector) sub-account into a local cache
and renders them as a responsive card directory with a filter bar: live search,
tag / city / state / company dropdowns, custom-field filters, sorting and
pagination.

Features:

* Works with the current API v2 (Private Integration token) or the legacy v1
  location API key.
* Contacts are cached in the database and refreshed hourly in the background, so
  page loads never wait on the GoHighLevel API.
* Photos come from a custom field of your choosing, GoHighLevel's own profile
  photo, or an optional Gravatar fallback — otherwise the card shows initials.
* Tag scoping (include/exclude) so only contacts who agreed to be listed appear
  — shipped scoped to the "member - physician" tag.
* Click a card for a full detail modal, with its own field list.
* Filtering works without JavaScript, and is upgraded to fetch-without-reload
  when JavaScript is available.
* Every template can be overridden from your theme.

== Installation ==

1. Upload the `gohighlevel-integration` folder to `/wp-content/plugins/` and activate it.
2. In GoHighLevel, open the sub-account: Settings → Private Integrations → create
   a token with the `contacts.readonly` scope (add `locations/customFields.readonly`
   to map custom fields).
3. In WordPress, go to Settings → GoHighLevel, paste the token, add the
   Location ID, save, then press "Sync now".
4. Add `[ghl_directory]` to a page.

For a token that stays out of database backups, put this in `wp-config.php`
instead of pasting it into the form:

`define( 'GHLD_API_TOKEN', 'pit-your-token' );`
`define( 'GHLD_LOCATION_ID', 'your-location-id' );`

== Frequently Asked Questions ==

= Does this expose my whole CRM? =

Only what you choose. "Only include tags" ships set to `member - physician`, so
nothing outside that tag is listed; email and phone are off by default.

= How often does it refresh? =

Hourly via WP-Cron, plus whenever the cache lifetime expires on a page view. Use
"Sync now" for an immediate refresh.

== Changelog ==

= 1.9.2 =
* Rename the field list to "Show on the contact page", which is what it drives
  now that pages are the default. The same list still applies to the modal.

= 1.9.1 =
* Contact pages and filtered views are marked noindex, overriding Yoast, Rank
  Math and All in One SEO. The directory page itself stays indexable, and
  crawling stays allowed so the noindex can actually be read.

= 1.9.0 =
* Each contact now has their own page at a shareable URL, instead of a modal.
  Cards are real links, so they open in a new tab, can be bookmarked, and are
  visible to search engines. Set "Opening a contact" back to the modal to keep
  the old behaviour.

= 1.8.7 =
* "Look for new photos" button: clears the day-long cooldown and checks every
  contact again, so a headshot uploaded just now appears without waiting.

= 1.8.6 =
* A sync no longer erases headshots. Rebuilding from the contact list was
  discarding the richer records that individual fetches had collected, so every
  sync undid its own work.
* Contacts that come back without a headshot are left alone for a day rather
  than being re-fetched on every sync.

= 1.8.5 =
* Fetching full records now finishes on its own in the background instead of
  needing "Sync now" pressed repeatedly, and stops once every contact has been
  tried rather than re-fetching the ones that genuinely have no photo.
* "Put this contact in the cache now" button in the inspector, to see one
  contact's headshot on the page immediately.

= 1.8.4 =
* Clear the contact cache on upgrade. Cached values were flattened by whichever
  version cached them, so a contact stored before uploads were ordered live-first
  kept a replaced file's dead URL — and since that counted as having a photo,
  nothing re-fetched it. The 1.8.2 fix could not reach existing data.
* Fall back to any uploaded file when the mapped key cannot be matched, so a
  headshot still resolves if the custom field definitions are unavailable.

= 1.8.3 =
* "Inspect a contact" now shows the headshot end to end: the URL resolved from
  the live payload, the exact <img> tag the card will output, what the cache
  currently holds, and the image itself loaded from that URL.

= 1.8.2 =
* Use the live upload in a file-upload field rather than one that was replaced.
  A replaced file stays in the field flagged deleted, and its URL no longer
  serves the image — which is why headshots came through as initials.

= 1.8.1 =
* Turn "Fetch full records" on for sites that already saved their settings — a
  changed default never reached them, so the 1.8.0 fix did nothing there.
* Fetch for as long as a sync can safely afford instead of a fixed 60 contacts,
  and report how many are still waiting.

= 1.8.0 =
* "Fetch full records" is now on by default: the contact list does not carry
  file-upload values, so headshots need the single-contact endpoint.
* Headshots are forced round at a specificity themes cannot override.
* Optional "Store headshots locally" setting, off by default.

= 1.7.0 =
* New "Fetch full records" option: sync fetches each contact individually to
  pick up custom fields the contact list leaves out — file uploads in
  particular. Batched at 60 per sync, resuming where the last run stopped.
* "Inspect a contact" can now target a named contact and fetches that record on
  its own, so the two endpoints can be compared directly.
* Optional second specialty field, joined to the first with a comma. Off unless
  you map it.

= 1.6.2 =
* The headshot count now separates photos resolved from the mapped field from
  those falling back to the GoHighLevel profile picture, so a working fallback
  can't be mistaken for a working mapping.

= 1.6.1 =
* "Inspect a contact" in Settings dumps one contact's raw custom field payload
  and the location's field definitions, so a mapping that produces nothing can
  be diagnosed from what GoHighLevel actually sent.
* A headshot that fails to load falls back to the initials circle instead of a
  broken-image icon.

= 1.6.0 =
* Read headshots from file-upload custom fields, whose value arrives as a list
  of URLs or {url} objects rather than a bare string.
* Stop treating a bare filename like "headshot.jpg" as a website address.
* Settings now reports how many cached contacts have a headshot, and when none
  do, shows what the mapped field actually contains and which custom field keys
  do hold values.

= 1.5.6 =
* Hide the "Search" label above the search box, keeping it for screen readers.

= 1.5.5 =
* The title on the name line takes the name's own weight and colour.

= 1.5.4 =
* The Clear button carries the theme's blue-button class.

= 1.5.3 =
* Filter-bar fields no longer force themselves to full width; the theme's own
  form styling decides.

= 1.5.2 =
* Left-align the modal's name and details.
* Drop the country line from the address.
* No ring, plate or hover on the modal's close button — the dialog itself
  takes focus on open.

= 1.5.1 =
* Re-derive names already in the cache on upgrade, so capitalization applies
  without waiting for the next sync.
* Capitalize names in CSS as well, covering data the PHP rule leaves alone.
* Drop the background plate behind the modal's close button.

= 1.5.0 =
* Modal laid out like a practice listing: name and specialty across the top,
  then the photo beside the organization name, postal address and "P." / "F."
  phone and fax lines.
* New mappings for Organization (falling back to the contact's Company field)
  and Fax, which GoHighLevel has no built-in field for.
* Phone and fax now appear in the modal by default; email still does not.
* Filter-bar buttons no longer stretch to full width, and the Clear button
  inherits the theme's own button styling.

= 1.4.0 =
* Map a Primary Specialty field and print it as an italic subtitle under the
  name in the detail modal. Multi-select fields render as a comma-separated
  list, e.g. "Infectious Disease, Internal Medicine".
* A custom field claimed by a mapping (photo, title, specialty, bio) is no
  longer also printed as a generic labelled row.

= 1.3.0 =
* Print the name line as "Name, Title" (e.g. "Emily Billingsley, MD"), with the
  title styled as secondary to the name. Set the Name line option back to
  "Name only" to keep the title on its own line.

= 1.2.0 =
* Capitalize names that arrive all-lowercase from GoHighLevel, leaving
  deliberate spellings (DeShawn, McDonald, van der Berg) untouched.
* Clicking a card opens a modal with that contact's full details. What appears
  there is controlled separately from the card, so the modal can carry a phone
  number or address without printing it on every card in the grid.
* Sort alphabetically by the name as printed on the card by default; surname
  order is still available as "Last name".

= 1.1.0 =
* Ship this site's own defaults: list only contacts tagged "member - physician",
  and take headshots from the contact.member_profile_photo custom field.
* Hide a tag the whole directory is scoped to from the filter bar and the cards,
  where it is true of every contact anyway.
* Resolve custom field values by the key carried in the payload when the field
  definitions could not be fetched, and accept `field_value` as well as `value`.
* Keep a field mapping that the last sync did not return instead of silently
  resetting it to "none" on the next save.

= 1.0.0 =
* Initial release.
