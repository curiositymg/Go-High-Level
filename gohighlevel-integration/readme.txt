=== GoHighLevel Integration ===
Contributors: curiositymarketinggroup
Tags: gohighlevel, highlevel, leadconnector, directory, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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
* Tag scoping (include/exclude) so only contacts who agreed to be listed appear.
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

Only what you choose. Set "Only include tags" to something like `directory` and
tag the contacts who agreed to be listed; email and phone are off by default.

= How often does it refresh? =

Hourly via WP-Cron, plus whenever the cache lifetime expires on a page view. Use
"Sync now" for an immediate refresh.

== Changelog ==

= 1.0.0 =
* Initial release.
