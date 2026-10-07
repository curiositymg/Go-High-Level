<?php
/**
 * One-time upgrades for installs that already hold saved settings and data.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brings an existing install up to date.
 *
 * A stored value always beats a changed default, so a site that has ever saved
 * the settings screen keeps whatever it saved. Anything that has to reach
 * existing installs is applied here, once, and recorded.
 *
 * Every step belongs inside a branch that names the versions it is for. A step
 * written outside one runs again on every later upgrade, which is how three
 * consecutive releases came to wipe the contact cache: the flush below was for
 * migration 3 and sat in the function body, so bumping the number to 4 and then
 * 5 re-ran it, taking the enriched headshots with it each time — and those are
 * the slowest thing to come back, because they arrive only from the
 * single-contact endpoint.
 */
class GHLD_Migrate {

	/**
	 * Where the applied migration number lives.
	 */
	const OPTION = 'ghld_migration';

	/**
	 * The migration this version of the plugin expects.
	 */
	const CURRENT = 6;

	/**
	 * Apply whatever this install has not had yet.
	 *
	 * @return int The migration number now recorded.
	 */
	public static function run() {
		$applied = (int) get_option( self::OPTION, 0 );

		if ( $applied >= self::CURRENT ) {
			return $applied;
		}

		if ( $applied < 2 ) {
			// Headshots in a file-upload field only arrive via the single-contact
			// endpoint, so full-record fetching has to be on for them to work.
			GHLD_Settings::update( array( 'deep_sync' => 1 ) );
		}

		if ( $applied < 3 ) {
			// Cached contacts hold values that were flattened by the code that
			// cached them. A file-upload field flattens to its URLs in the order
			// the payload listed them, so a contact cached before uploads were
			// ordered live-first keeps a replaced file's dead URL — and because
			// that counts as "has a photo", nothing would ever re-fetch it. Drop
			// the cache so the next sync rebuilds every contact with the current
			// code. Only for installs that predate that ordering fix: for anybody
			// else this throws away good data, headshots first.
			GHLD_Repository::flush();
		}

		if ( 4 === $applied ) {
			// Undo migration 4, which turned local headshot copies on for
			// everyone on the strength of a conclusion that turned out to be
			// wrong: those documents/download URLs do serve a visitor's browser.
			// Worse, a local copy is served from this domain, so on a site behind
			// a staging password gate it would put the headshots behind that gate
			// — breaking the one part that was working. The setting and its
			// button stay; only the unasked-for default goes back.
			GHLD_Settings::update( array( 'cache_photos' => 0 ) );
		}

		update_option( self::OPTION, self::CURRENT );

		return self::CURRENT;
	}
}
