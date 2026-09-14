=== GoHighLevel Integration ===
Contributors: curiositymarketinggroup
Tags: gohighlevel, highlevel, leadconnector, directory, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
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
