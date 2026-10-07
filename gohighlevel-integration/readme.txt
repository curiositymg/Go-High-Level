=== GoHighLevel Integration ===
Contributors: curiositymarketinggroup
Tags: gohighlevel, highlevel, leadconnector, directory, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.18.0
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

= 1.18.0 =
* Fixes the directory being invisible to visitors whose device is in dark mode.
  The stylesheet redefined its colours inside a prefers-color-scheme: dark query,
  which reports the visitor's operating system and says nothing about the colour
  of the page the directory sits on. On a theme that stays light — most themes —
  a visitor in dark mode got white text, white borders and transparent cards on a
  white page: the specialty, title, organisation and location lines unreadable and
  the card outlines gone, while names and headshots, which do not use those
  colours, still showed. To anyone whose device is in light mode the same page
  looked perfect, which is why this survived cache purges, a host investigation
  and several wrong diagnoses.
* The dark colours are still available: tick "Follow the visitor's dark mode" if
  your theme turns dark with the device too. A theme can also redefine the
  --ghld-* custom properties, as it always could.

= 1.17.5 =
* "Never cache the directory" now works on a page built with a page builder.
  Elementor and its kind keep the layout in post meta rather than in
  post_content, so the plugin did not recognise such a page as showing the
  directory and the option quietly did nothing — on exactly the pages most likely
  to need it. Elementor, SiteOrigin, Cornerstone and Oxygen layouts are read
  now, and a ghld_page_has_directory filter covers anything else. The stylesheet
  fallback keys off the same answer, so it benefits too.
* This is the option to reach for when a page is current on the server but stale
  for particular people: a copy held in their own browser, an office proxy or a
  CDN edge node near them cannot be purged from WordPress, but it can be told not
  to keep the page in the first place.

= 1.17.4 =
* Recognises a site behind a server-level password — a staging or coming-soon
  gate — which answers before WordPress runs and refuses every file on the domain
  to anyone without the credentials, while images hosted elsewhere load normally.
  That is a styled page for whoever holds the password and an unstyled page with
  working photographs for everybody else, and no cache is involved. "Check the
  public page" now says so instead of reporting an unreachable page, and points
  at "Write the styling into the page", which carries the rules inside HTML that
  has already passed the gate.
* Reverts 1.17.3's default. Those GoHighLevel file URLs do serve a visitor's
  browser, so copying headshots here is optional again, and an install switched
  over by 1.17.3 is switched back. The setting and the "Download every headshot
  now" button stay. On a site behind a staging password the trade-off runs the
  other way: a copy here sits behind the gate, while a headshot on GoHighLevel
  does not.

= 1.17.3 =
* Headshots now load for everybody, not only for whoever is signed in to
  GoHighLevel. A headshot in a file-upload field is stored as a
  services.leadconnectorhq.com/documents/download address, which is an API
  endpoint rather than a public file: it answers a request carrying the API token
  and refuses one from a visitor's browser. Pasting such an address into your own
  browser appears to prove it public, but only because that browser holds a
  GoHighLevel session. Everybody else got a failed image, which the directory
  replaces with the initials circle — so their cards looked emptier than yours,
  and no amount of cache clearing could change it. Copying headshots to this site
  is therefore on by default now, and existing installs are switched on when they
  update.
* "Download every headshot now" copies them all immediately, in batches with a
  progress bar, instead of sixty per sync in the background.
* "Check the public page" requests the headshots on the page with no cookies and
  reports how many a visitor can actually load, with the failing address and its
  status.

= 1.17.2 =
* The directory carries its own stylesheet when the page has not already loaded
  one. Enqueuing a stylesheet asks for it; it does not promise one arrives. A
  directory rendered by a builder, a widget or a template runs after <head> has
  been sent, and a plugin that combines or minifies CSS can drop it on the way
  out — and because logged-in administrators are normally exempt from CSS
  optimisation, that is invisible to the one person checking. The stylesheet
  reference now goes beside the directory in that case, inside the HTML, where
  nothing downstream can lose it.
* "Check the public page" now requests the stylesheet too, anonymously, and says
  whether the page asks for it, whether that address serves it, and whether what
  came back is really the plugin's CSS — a 200 from a rewrite rule or a security
  layer is not.
* The render stamp records how the styling reached the page: from the head,
  beside the directory, or written in.
* New "Write the styling into the page" option, for an install where the file is
  on disk but its address will not serve it: an asset CDN that never fetched it,
  a rewritten asset host, a permission that stops the webserver reading it.
  Inlining sidesteps the URL completely.

= 1.17.1 =
* Answers "they still see the old page" with facts instead of guesswork. Every
  directory and profile page now carries a render stamp — an HTML comment giving
  the plugin version, the page it came from and the moment that HTML was built —
  so a page source says whether a visitor has the live page or a copy a cache
  kept.
* A "Check the public page" button requests the page with no login, which is
  what everybody else gets, and reports the render stamp, the cache headers, the
  stylesheet version and how many cards and specialty lines actually arrived.
  You are the one visitor a page cache treats differently, so this is the only
  way to see the public page from the admin screen. Point it at another address
  to check whether the people reporting a problem are on a different site.
* A purge now records which caches it was able to reach, and the report says so.
  A purge that reaches nothing is the answer: the caching is somewhere PHP
  cannot clear, and re-purging will not help.
* More caches cleared: Breeze, Hummingbird, Autoptimize, Comet Cache, Swift
  Performance, NitroPack and Pantheon, alongside the ones already handled.
* New "Never cache the directory" option for a cache that cannot be purged. It
  asks that no copy of the page be stored, so it is always built fresh. Off by
  default, because it costs the page its cache hit.

= 1.17.0 =
* The public directory refreshes itself. Saving settings, a sync that changes
  what a card shows, or fetching one contact now purges the host page cache, so
  logged-out visitors see the same thing an administrator does instead of
  whatever the cache happened to hold. WP Engine, WP Rocket, W3 Total Cache,
  LiteSpeed, SG Optimizer, Cache Enabler, WP Fastest Cache and Elementor's CSS
  cache are all cleared when present.
* A "Clear page caches" button on the settings screen does it on demand, and a
  "Page caches" checkbox turns the automatic purging off.

= 1.16.1 =
* Phone is now a mapped field too, so an office line can be published instead
  of the number on the contact record. Unmapped, nothing changes.

= 1.16.0 =
* A physician with several credentials gets them on their own line, with the
  comma that introduces them kept beside the name — no more stranded comma at
  the start of a wrapped line.

= 1.15.2 =
* Clear actually clears. It was a form reset, which restores a form to the
  values it was rendered with — on a filtered page, the filters themselves.

= 1.15.1 =
* A contact with no address in the mapped email field shows no email, rather
  than the address on their contact record.

= 1.15.0 =
* Only contacts carrying the include tags are cached, so a location of
  thousands syncs as the few hundred actually listed. Everything costly runs
  per contact, so this is the difference between a sync finishing and timing
  out.
* Email is now a mapped field: publish a practice address from a custom field
  rather than the personal one on the contact record.

= 1.14.0 =
* A contact with no headshot shows a placeholder photo rather than initials.
  A default one ships with the plugin; paste a media library URL to use your own.
* Specialties read "Specialty: Internal Medicine, Geriatrics".
* Field labels end with a colon and stay on one line.

= 1.13.1 =
* The contact page panel can address the physician it sits beside: {name},
  {specialty}, {city} and other placeholders are filled per contact, and a
  shortcode of your own can read the same record with
  GHLD_Shortcode::current_contact().

= 1.13.0 =
* A panel beside every contact, written in the settings: HTML, with shortcodes
  run, so anything already on the site can be dropped in. Filter
  ghld_profile_sidebar for anything needing PHP.
* The back control carries only the theme's btn-blue class, with no styling
  from the plugin.

= 1.12.0 =
* Square headshots that fill the card, instead of small circles that threw
  away most of the frame.
* Contact pages follow the supplied model: photo on the left, name, phone,
  address and specialty to the right.
* The contact's name is the page's only H1; the theme's page title is stepped
  down to a label on those pages.
* The back control reads "See Full Directory" and is styled as a control.
* Primary Specialty now appears on the cards as well as the contact page.

= 1.11.3 =
* The per-contact cache button is always shown after an inspection — whether
  the cache agrees or not, and whether or not a name was typed to find the
  contact.

= 1.11.2 =
* The per-contact cache button appears whenever the cache and GoHighLevel
  disagree, including when a photo has been removed — previously it was hidden
  in exactly that case, since it required the live payload to have a headshot.

= 1.11.1 =
* Clearing a photo field in GoHighLevel now clears it on the site. Every upload
  a field ever held stays in the payload flagged deleted, and a deleted upload
  was still being used as a last resort — so emptying the field left the old
  image showing.
* "Fetch every contact now" re-checks contacts that already have a headshot,
  so a photo that changed or was removed is picked up.

= 1.11.0 =
* "Fetch every contact now" works through the whole location in batches with a
  progress bar, instead of waiting on the hourly background schedule.

= 1.10.0 =
* A contact page now shows only that contact: the directory page's own intro
  copy, banners and calls to action are hidden. A ghld-contact-page body class
  covers anything outside the content area.
* "Back" returns to the page of results you came from, with any search still
  applied, instead of restarting at page one.

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
