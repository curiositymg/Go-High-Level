<?php
/**
 * Admin settings screen.
 *
 * @package GoHighLevel_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page under Settings → GoHighLevel.
 */
class GHLD_Admin {

	const PAGE       = 'gohighlevel-integration';
	const GROUP      = 'ghld_settings_group';
	const CAPABILITY = 'manage_options';

	/**
	 * Hook the admin pieces.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ghld_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_ghld_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_ghld_inspect', array( __CLASS__, 'handle_inspect' ) );
		add_action( 'admin_post_ghld_store', array( __CLASS__, 'handle_store' ) );
		add_action( 'admin_post_ghld_recheck', array( __CLASS__, 'handle_recheck' ) );
		add_action( 'wp_ajax_ghld_enrich_batch', array( __CLASS__, 'handle_enrich_batch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GHLD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			__( 'GoHighLevel Integration', 'gohighlevel-integration' ),
			__( 'GoHighLevel', 'gohighlevel-integration' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the single settings option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			GHLD_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'GHLD_Settings', 'sanitize' ),
				'default'           => GHLD_Settings::defaults(),
			)
		);
	}

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'gohighlevel-integration' ) . '</a>' );

		return $links;
	}

	/**
	 * Handle the "Sync now" button.
	 *
	 * @return void
	 */
	public static function handle_sync() {
		self::guard( 'ghld_sync' );

		$result = GHLD_Repository::sync();
		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}

		self::redirect(
			'success',
			sprintf(
				/* translators: %d: number of contacts. */
				_n( 'Synced %d contact from GoHighLevel.', 'Synced %d contacts from GoHighLevel.', (int) $result, 'gohighlevel-integration' ),
				(int) $result
			)
		);
	}

	/**
	 * Load the progress-loop script on the settings screen only.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_script( 'ghld-admin', GHLD_URL . 'assets/js/admin.js', array(), GHLD_VERSION, true );
		wp_localize_script(
			'ghld-admin',
			'GHLDAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ghld_enrich_batch' ),
				'i18n'    => array(
					'starting' => __( 'Starting…', 'gohighlevel-integration' ),
					/* translators: 1: contacts done, 2: total contacts, 3: headshots found. */
					'progress' => __( '%1$s of %2$s contacts checked — %3$s headshots so far.', 'gohighlevel-integration' ),
					/* translators: %s: headshots found. */
					'done'     => __( 'Finished. %s contacts have a headshot.', 'gohighlevel-integration' ),
					'failed'   => __( 'That run failed. Press the button again to carry on from where it stopped.', 'gohighlevel-integration' ),
					'stalled'  => __( 'Stopped after a lot of batches without finishing — press again to continue.', 'gohighlevel-integration' ),
				),
			)
		);
	}

	/**
	 * Run one enrichment batch for the progress loop.
	 *
	 * @return void
	 */
	public static function handle_enrich_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'gohighlevel-integration' ) ), 403 );
		}

		check_ajax_referer( 'ghld_enrich_batch', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reset  = ! empty( $_POST['reset'] ) && '1' === (string) $_POST['reset'];
		$result = GHLD_Repository::enrich_once( 10, $reset );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle the "Look for new photos" button.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		self::guard( 'ghld_recheck' );

		GHLD_Repository::clear_enrich_cooldown();
		$result = GHLD_Repository::sync();

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}

		$state     = GHLD_Repository::state();
		$remaining = isset( $state['enrich_remaining'] ) ? (int) $state['enrich_remaining'] : 0;

		if ( $remaining > 0 ) {
			self::redirect(
				'success',
				sprintf(
					/* translators: %s: contacts left to check. */
					__( 'Checking every contact again. %s to go — the rest continue in the background.', 'gohighlevel-integration' ),
					number_format_i18n( $remaining )
				)
			);
		}

		self::redirect( 'success', __( 'Checked every contact for a new photo.', 'gohighlevel-integration' ) );
	}

	/**
	 * Handle the "Test connection" button.
	 *
	 * @return void
	 */
	public static function handle_test() {
		self::guard( 'ghld_test' );

		$client = new GHLD_Client();
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}

		self::redirect( 'success', __( 'Connection to GoHighLevel succeeded.', 'gohighlevel-integration' ) );
	}

	/**
	 * Fetch one contact and keep its raw payload for inspection.
	 *
	 * When a mapped field produces nothing, the question is what GoHighLevel
	 * actually sent — whether custom fields come back on the contact list at
	 * all, under which IDs, and in what shape. Guessing at that from the front
	 * end is hopeless; this shows it.
	 *
	 * @return void
	 */
	public static function handle_inspect() {
		self::guard( 'ghld_inspect' );

		$client = new GHLD_Client();
		$who    = isset( $_POST['who'] ) ? sanitize_text_field( wp_unslash( $_POST['who'] ) ) : '';
		$cached = null;

		if ( '' !== $who ) {
			$cached = self::find_cached_contact( $who );
			if ( null === $cached ) {
				self::redirect(
					'error',
					sprintf(
						/* translators: %s: search term. */
						__( 'No cached contact matches "%s". Try part of a name or an email address.', 'gohighlevel-integration' ),
						$who
					)
				);
			}
		}

		// With a named contact, ask for that record on its own: the
		// single-contact endpoint returns fields the list can leave out.
		if ( null !== $cached ) {
			$raw = $client->get_contact( $cached['id'] );
			if ( is_wp_error( $raw ) ) {
				self::redirect( 'error', $raw->get_error_message() );
			}
			$source = 'detail';
		} else {
			$list = $client->get_contacts( 1 );
			if ( is_wp_error( $list ) ) {
				self::redirect( 'error', $list->get_error_message() );
			}
			if ( empty( $list ) ) {
				self::redirect( 'error', __( 'GoHighLevel returned no contacts to inspect.', 'gohighlevel-integration' ) );
			}
			$raw    = $list[0];
			$source = 'list';
		}

		$fields = $client->get_custom_fields();
		$fields = is_wp_error( $fields ) ? GHLD_Repository::custom_fields() : $fields;

		// Run the live payload through the same normalizer the front end uses,
		// so the panel can show the actual <img> it would produce.
		$resolved = GHLD_Contact::normalize( $raw, $fields, GHLD_Settings::all() );

		set_transient(
			'ghld_inspect',
			array(
				'contact'      => $raw,
				'fields'       => $fields,
				'source'       => $source,
				'name'         => ( null !== $cached ) ? $cached['name'] : '',
				'cached'       => ( null !== $cached && isset( $cached['custom'] ) ) ? $cached['custom'] : array(),
				'live_photo'   => $resolved['photo'],
				'cached_photo' => ( null !== $cached && isset( $cached['photo'] ) ) ? $cached['photo'] : '',
			),
			5 * MINUTE_IN_SECONDS
		);

		self::redirect( 'success', __( 'Fetched the contact. Its raw payload is shown below.', 'gohighlevel-integration' ) );
	}

	/**
	 * Write the inspected contact straight into the cache.
	 *
	 * Turns "the payload resolves a headshot but the cache has not caught up"
	 * into something you can see on the page a second later, rather than after
	 * a few hundred background fetches.
	 *
	 * @return void
	 */
	public static function handle_store() {
		self::guard( 'ghld_store' );

		$data = get_transient( 'ghld_inspect' );

		if ( ! is_array( $data ) || empty( $data['contact'] ) ) {
			self::redirect( 'error', __( 'That inspection has expired — inspect the contact again.', 'gohighlevel-integration' ) );
		}

		$contact = GHLD_Contact::normalize(
			$data['contact'],
			is_array( $data['fields'] ) ? $data['fields'] : GHLD_Repository::custom_fields(),
			GHLD_Settings::all()
		);

		if ( ! GHLD_Repository::store_contact( $contact ) ) {
			self::redirect( 'error', __( 'That contact is not in the cached set, so there was nothing to update. Press "Sync now" first.', 'gohighlevel-integration' ) );
		}

		self::redirect(
			'success',
			sprintf(
				/* translators: %s: contact name. */
				__( '%s updated in the cache — reload the directory to see it.', 'gohighlevel-integration' ),
				$contact['name']
			)
		);
	}

	/**
	 * Find a cached contact by name or email fragment.
	 *
	 * @param string $who Search term.
	 * @return array|null
	 */
	protected static function find_cached_contact( $who ) {
		$contacts = get_option( GHLD_Repository::OPTION_CONTACTS, array() );
		$needle   = GHLD_Contact::lower( $who );

		foreach ( (array) $contacts as $contact ) {
			if ( empty( $contact['id'] ) ) {
				continue;
			}
			$haystack = GHLD_Contact::lower( $contact['name'] . ' ' . $contact['email'] );
			if ( false !== strpos( $haystack, $needle ) ) {
				return $contact;
			}
		}

		return null;
	}

	/**
	 * Print the raw payload captured by handle_inspect().
	 *
	 * @return void
	 */
	protected static function render_inspection() {
		$data = get_transient( 'ghld_inspect' );

		if ( ! is_array( $data ) || empty( $data['contact'] ) ) {
			return;
		}

		$contact = $data['contact'];
		$custom  = array();
		foreach ( array( 'customFields', 'customField' ) as $property ) {
			if ( isset( $contact[ $property ] ) ) {
				$custom = $contact[ $property ];
				break;
			}
		}

		$live   = isset( $data['live_photo'] ) ? (string) $data['live_photo'] : '';
		$stored = isset( $data['cached_photo'] ) ? (string) $data['cached_photo'] : '';
		?>
		<details open style="margin:1em 0;padding:1em;border:1px solid #c3c4c7;background:#fff">
			<summary><strong><?php esc_html_e( 'Raw payload for one contact', 'gohighlevel-integration' ); ?></strong></summary>

			<h3><?php esc_html_e( 'The headshot, end to end', 'gohighlevel-integration' ); ?></h3>
			<table class="widefat striped" style="margin-bottom:1em">
				<tr>
					<td style="width:18em"><strong><?php esc_html_e( 'Resolved from this payload', 'gohighlevel-integration' ); ?></strong></td>
					<td><code><?php echo esc_html( '' === $live ? __( '(nothing)', 'gohighlevel-integration' ) : $live ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'What the card will output', 'gohighlevel-integration' ); ?></strong></td>
					<td><code><?php echo esc_html( '' === $live ? __( '(the initials circle)', 'gohighlevel-integration' ) : '<img class="ghld-avatar" src="' . $live . '">' ); ?></code></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'What the cache holds right now', 'gohighlevel-integration' ); ?></strong></td>
					<td><code><?php echo esc_html( '' === $stored ? __( '(nothing)', 'gohighlevel-integration' ) : $stored ); ?></code></td>
				</tr>
			</table>

			<?php if ( '' !== $live ) : ?>
				<p>
					<?php esc_html_e( 'Loaded from that URL, right here:', 'gohighlevel-integration' ); ?><br />
					<img src="<?php echo esc_url( $live ); ?>" alt="" style="max-width:160px;border-radius:50%;margin-top:.5em" />
				</p>
			<?php elseif ( '' === $stored ) : ?>
				<p class="notice notice-error" style="padding:8px 12px">
					<?php esc_html_e( 'No headshot in this payload, and none cached. The custom field values below are exactly what arrived.', 'gohighlevel-integration' ); ?>
				</p>
			<?php endif; ?>

			<?php
			// Always offered. This is a manual tool: hiding it because the two
			// happen to agree, or because the contact was reached without
			// typing a name, only ever means it is missing when someone goes
			// looking for it. Storing a contact that already matches is a
			// no-op, which is a far better failure than an absent button.
			$ghld_state = ( $live === $stored )
				? __( 'The cache already matches GoHighLevel for this contact.', 'gohighlevel-integration' )
				: (
					( '' === $live )
						? __( 'This contact has no headshot in GoHighLevel any more, but the cache still holds one — which is what the directory is showing.', 'gohighlevel-integration' )
						: __( 'The live payload resolves a headshot but the cached copy does not match it. The directory renders from the cache, never live.', 'gohighlevel-integration' )
				);
			?>
			<div class="notice <?php echo ( $live === $stored ) ? 'notice-success' : 'notice-warning'; ?>" style="padding:8px 12px">
				<p><?php echo esc_html( $ghld_state ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ghld_store" />
					<?php wp_nonce_field( 'ghld_store' ); ?>
					<button type="submit" class="button button-primary">
						<?php
						echo ( '' === $live && '' !== $stored )
							? esc_html__( 'Clear this contact in the cache now', 'gohighlevel-integration' )
							: esc_html__( 'Put this contact in the cache now', 'gohighlevel-integration' );
						?>
					</button>
					<span class="description"><?php esc_html_e( 'Writes what was just fetched into the cache for this one contact, without waiting for the whole sync.', 'gohighlevel-integration' ); ?></span>
				</form>
			</div>

			<p>
				<?php if ( empty( $custom ) ) : ?>
					<strong><?php esc_html_e( 'This contact came back with no custom field values at all.', 'gohighlevel-integration' ); ?></strong>
					<?php esc_html_e( 'If the field is filled in for this contact in GoHighLevel, the contact list endpoint is not returning custom fields for this location.', 'gohighlevel-integration' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Custom field values on this contact, as sent:', 'gohighlevel-integration' ); ?>
				<?php endif; ?>
			</p>

			<pre style="overflow:auto;max-height:22em;padding:1em;background:#f6f7f7"><?php echo esc_html( (string) wp_json_encode( $custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>

			<p><strong><?php esc_html_e( 'Custom field definitions (ID → key):', 'gohighlevel-integration' ); ?></strong></p>
			<pre style="overflow:auto;max-height:18em;padding:1em;background:#f6f7f7"><?php
			$lines = array();
			foreach ( (array) $data['fields'] as $id => $field ) {
				$lines[] = $id . '  →  ' . $field['key'] . '   (' . $field['name'] . ', ' . $field['type'] . ')';
			}
			echo esc_html( empty( $lines ) ? __( 'None returned — the token may lack the customFields scope.', 'gohighlevel-integration' ) : implode( "\n", $lines ) );
			?></pre>
		</details>
		<?php
	}

	/**
	 * Capability + nonce check for the admin-post actions.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	protected static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'gohighlevel-integration' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Return to the settings page carrying a notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 * @return void
	 */
	protected static function redirect( $type, $message ) {
		$url = add_query_arg(
			array(
				'page'         => self::PAGE,
				'ghld_notice'  => $type,
				'ghld_message' => rawurlencode( $message ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = GHLD_Settings::all();
		$state    = GHLD_Repository::state();
		$fields   = GHLD_Repository::custom_fields();

		?>
		<div class="wrap ghld-admin">
			<h1><?php esc_html_e( 'GoHighLevel Integration', 'gohighlevel-integration' ); ?></h1>

			<?php self::render_notice(); ?>
			<?php self::render_status( $state ); ?>
			<?php self::render_inspection(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<?php $name = GHLD_Settings::OPTION; ?>

				<h2><?php esc_html_e( 'Connection', 'gohighlevel-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ghld-api-version"><?php esc_html_e( 'API', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-api-version" name="<?php echo esc_attr( $name ); ?>[api_version]">
								<option value="v2" <?php selected( $settings['api_version'], 'v2' ); ?>><?php esc_html_e( 'v2 — Private Integration token (recommended)', 'gohighlevel-integration' ); ?></option>
								<option value="v1" <?php selected( $settings['api_version'], 'v1' ); ?>><?php esc_html_e( 'v1 — legacy location API key', 'gohighlevel-integration' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'v2 tokens come from Settings → Private Integrations in your GoHighLevel sub-account and need the contacts.readonly scope (add locations/customFields.readonly to map custom fields).', 'gohighlevel-integration' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-api-token"><?php esc_html_e( 'API token', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<?php if ( GHLD_Settings::token_is_constant() ) : ?>
								<p><code><?php esc_html_e( 'Set in wp-config.php via GHLD_API_TOKEN', 'gohighlevel-integration' ); ?></code></p>
							<?php else : ?>
								<input type="password" class="regular-text" id="ghld-api-token" name="<?php echo esc_attr( $name ); ?>[api_token]" value="" autocomplete="off"
									placeholder="<?php echo esc_attr( '' === $settings['api_token'] ? __( 'Paste your token', 'gohighlevel-integration' ) : __( 'Stored — leave blank to keep it', 'gohighlevel-integration' ) ); ?>" />
								<?php if ( '' !== $settings['api_token'] ) : ?>
									<label style="margin-left:1em">
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_api_token]" value="1" />
										<?php esc_html_e( 'Remove stored token', 'gohighlevel-integration' ); ?>
									</label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'The token is stored in the options table. To keep it out of database backups, define GHLD_API_TOKEN in wp-config.php instead.', 'gohighlevel-integration' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-location"><?php esc_html_e( 'Location ID', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ghld-location" name="<?php echo esc_attr( $name ); ?>[location_id]" value="<?php echo esc_attr( $settings['location_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Required for API v2. Found in the GoHighLevel sub-account URL, or under Settings → Business Profile.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-cache"><?php esc_html_e( 'Cache lifetime', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<input type="number" min="5" step="5" id="ghld-cache" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( (string) $settings['cache_minutes'] ); ?>" class="small-text" />
							<?php esc_html_e( 'minutes', 'gohighlevel-integration' ); ?>
							<p class="description"><?php esc_html_e( 'Contacts are cached in the database and refreshed hourly in the background; visitors never wait on the GoHighLevel API.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Which contacts', 'gohighlevel-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Fetch full records', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[deep_sync]" value="1" <?php checked( ! empty( $settings['deep_sync'] ) ); ?> />
								<?php esc_html_e( 'Fetch each contact individually to pick up custom fields the contact list leaves out', 'gohighlevel-integration' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn this on if a mapped custom field — a file upload in particular — is filled in inside GoHighLevel but arrives empty here. It is slow, so each sync fetches a batch of 60 and the next sync carries on where it left off; contacts that already have a headshot are skipped. Press "Sync now" a few times, or let the hourly sync work through them.', 'gohighlevel-integration' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Store headshots locally', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cache_photos]" value="1" <?php checked( ! empty( $settings['cache_photos'] ) ); ?> />
								<?php esc_html_e( 'Copy headshots into this site\'s uploads folder', 'gohighlevel-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default — GoHighLevel serves the file URLs publicly, so the cards can point straight at them. Turn it on to stop depending on those URLs staying reachable; downloads run in batches during sync.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-include"><?php esc_html_e( 'Only include tags', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ghld-include" name="<?php echo esc_attr( $name ); ?>[include_tags]" value="<?php echo esc_attr( $settings['include_tags'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated, and set to "member - physician" by default. Only contacts carrying one of these tags are listed. Leaving this empty makes every synced contact listable, so keep it set unless that is really what you want.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-exclude"><?php esc_html_e( 'Exclude tags', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ghld-exclude" name="<?php echo esc_attr( $name ); ?>[exclude_tags]" value="<?php echo esc_attr( $settings['exclude_tags'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated. Contacts with any of these tags are never shown.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Field mapping', 'gohighlevel-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ghld-photo"><?php esc_html_e( 'Photo', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-photo" name="<?php echo esc_attr( $name ); ?>[photo_field]">
								<option value="" <?php selected( $settings['photo_field'], '' ); ?>><?php esc_html_e( 'No photo — show initials', 'gohighlevel-integration' ); ?></option>
								<option value="profile_photo" <?php selected( $settings['photo_field'], 'profile_photo' ); ?>><?php esc_html_e( 'GoHighLevel profile photo', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['photo_field'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pick a custom field holding an image URL if your contacts store headshots there; it falls back to the GoHighLevel profile photo.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-title"><?php esc_html_e( 'Job title', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-title" name="<?php echo esc_attr( $name ); ?>[title_field]">
								<option value="" <?php selected( $settings['title_field'], '' ); ?>><?php esc_html_e( '— none —', 'gohighlevel-integration' ); ?></option>
								<option value="company" <?php selected( $settings['title_field'], 'company' ); ?>><?php esc_html_e( 'Company name', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['title_field'] ); ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-company"><?php esc_html_e( 'Organization', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-company" name="<?php echo esc_attr( $name ); ?>[company_field]">
								<option value="" <?php selected( $settings['company_field'], '' ); ?>><?php esc_html_e( 'The contact\'s own Company field', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['company_field'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'The practice or organization name. Falls back to the contact\'s Company field when the mapped field is empty.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-specialty"><?php esc_html_e( 'Primary specialty', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-specialty" name="<?php echo esc_attr( $name ); ?>[specialty_field]">
								<option value="" <?php selected( $settings['specialty_field'], '' ); ?>><?php esc_html_e( '— none —', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['specialty_field'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Printed under the name in the detail modal.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-specialty-2"><?php esc_html_e( 'Second specialty', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-specialty-2" name="<?php echo esc_attr( $name ); ?>[specialty_field_2]">
								<option value="" <?php selected( $settings['specialty_field_2'], '' ); ?>><?php esc_html_e( '— none —', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['specialty_field_2'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Joined to the first with a comma, so two fields read as one line: "Infectious Disease, Internal Medicine".', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-fax"><?php esc_html_e( 'Fax', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-fax" name="<?php echo esc_attr( $name ); ?>[fax_field]">
								<option value="" <?php selected( $settings['fax_field'], '' ); ?>><?php esc_html_e( '— none —', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['fax_field'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'GoHighLevel has no built-in fax field, so this reads from a custom field.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-bio"><?php esc_html_e( 'Bio', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-bio" name="<?php echo esc_attr( $name ); ?>[bio_field]">
								<option value="" <?php selected( $settings['bio_field'], '' ); ?>><?php esc_html_e( '— none —', 'gohighlevel-integration' ); ?></option>
								<?php self::field_options( $fields, $settings['bio_field'] ); ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gravatar fallback', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[use_gravatar]" value="1" <?php checked( ! empty( $settings['use_gravatar'] ) ); ?> />
								<?php esc_html_e( 'Use Gravatar when a contact has no photo', 'gohighlevel-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default: this sends a hash of each listed contact\'s email address to gravatar.com.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Directory display', 'gohighlevel-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show on each card', 'gohighlevel-integration' ); ?></th>
						<td>
							<?php self::checkbox_list( $name . '[show]', self::show_choices( $fields ), (array) $settings['show'] ); ?>
							<p class="description"><?php esc_html_e( 'What appears on the grid. Email and phone are off by default — only turn them on for contacts who expect their details to be public. The contact page has its own list below.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-name-format"><?php esc_html_e( 'Name line', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-name-format" name="<?php echo esc_attr( $name ); ?>[name_format]">
								<?php foreach ( GHLD_Settings::name_format_choices() as $ghld_value => $ghld_label ) : ?>
									<option value="<?php echo esc_attr( $ghld_value ); ?>" <?php selected( $settings['name_format'], $ghld_value ); ?>><?php echo esc_html( $ghld_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The title comes from whichever field is mapped as "Job title" above — map it to the custom field holding the credential (MD, DO, NP) or the specialty. With nothing mapped, only the name is printed.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-view"><?php esc_html_e( 'Opening a contact', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-view" name="<?php echo esc_attr( $name ); ?>[view]">
								<?php foreach ( GHLD_Settings::view_choices() as $ghld_value => $ghld_label ) : ?>
									<option value="<?php echo esc_attr( $ghld_value ); ?>" <?php selected( $settings['view'], $ghld_value ); ?>><?php echo esc_html( $ghld_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'A contact page is a normal, shareable URL on the directory page — good for linking to a physician directly, and visible to search engines. The modal keeps people on the grid.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contact page content', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[isolate_profile]" value="1" <?php checked( ! empty( $settings['isolate_profile'] ) ); ?> />
								<?php esc_html_e( 'Show only the contact, hiding the rest of the directory page', 'gohighlevel-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The directory sits on an ordinary page, so that page\'s intro copy, banners and calls to action would otherwise wrap around a single physician. Anything outside the content area — a sidebar, or a builder section above the header — is beyond this plugin\'s reach; the body tag carries a ghld-contact-page class so your theme can hide those too.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search engines', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[noindex_generated]" value="1" <?php checked( ! empty( $settings['noindex_generated'] ) ); ?> />
								<?php esc_html_e( 'Keep contact pages and filtered views out of search results', 'gohighlevel-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Marks them noindex, and overrides Yoast, Rank Math and All in One SEO so they cannot say otherwise. The directory page itself is left alone. Crawling stays allowed on purpose — a page has to be readable for its noindex to be seen, so blocking it in robots.txt would have the opposite effect.', 'gohighlevel-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Detail modal', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[modal]" value="1" <?php checked( ! empty( $settings['modal'] ) ); ?> />
								<?php esc_html_e( 'Open a contact\'s full details when their card is clicked (only used when "Opening a contact" is set to the modal)', 'gohighlevel-integration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show on the contact page', 'gohighlevel-integration' ); ?></th>
						<td>
							<?php self::checkbox_list( $name . '[modal_show]', self::show_choices( $fields ), (array) $settings['modal_show'] ); ?>
							<p class="description">
								<?php esc_html_e( 'Separate from the card list above, so a contact page can carry phone, email, a full address or any custom field without printing them on every card in the grid. Every custom field found in your location is listed here. The same list is used by the modal.', 'gohighlevel-integration' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Filter bar', 'gohighlevel-integration' ); ?></th>
						<td>
							<?php self::checkbox_list( $name . '[filters]', self::filter_choices( $fields ), (array) $settings['filters'] ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-columns"><?php esc_html_e( 'Columns', 'gohighlevel-integration' ); ?></label></th>
						<td><input type="number" min="1" max="6" class="small-text" id="ghld-columns" name="<?php echo esc_attr( $name ); ?>[columns]" value="<?php echo esc_attr( (string) $settings['columns'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-per-page"><?php esc_html_e( 'Contacts per page', 'gohighlevel-integration' ); ?></label></th>
						<td><input type="number" min="1" max="200" class="small-text" id="ghld-per-page" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( (string) $settings['per_page'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ghld-orderby"><?php esc_html_e( 'Default sort', 'gohighlevel-integration' ); ?></label></th>
						<td>
							<select id="ghld-orderby" name="<?php echo esc_attr( $name ); ?>[orderby]">
								<?php foreach ( GHLD_Settings::orderby_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['orderby'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="<?php echo esc_attr( $name ); ?>[order]">
								<option value="asc" <?php selected( $settings['order'], 'asc' ); ?>><?php esc_html_e( 'Ascending', 'gohighlevel-integration' ); ?></option>
								<option value="desc" <?php selected( $settings['order'], 'desc' ); ?>><?php esc_html_e( 'Descending', 'gohighlevel-integration' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_usage(); ?>
		</div>
		<?php
	}

	/**
	 * Print the admin notice carried in the redirect.
	 *
	 * @return void
	 */
	protected static function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['ghld_notice'] ) ) {
			return;
		}
		$type    = ( 'success' === $_GET['ghld_notice'] ) ? 'notice-success' : 'notice-error';
		$message = isset( $_GET['ghld_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ghld_message'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Sync status panel with the manual action buttons.
	 *
	 * @param array $state Sync state.
	 * @return void
	 */
	protected static function render_status( array $state ) {
		$synced = (int) $state['synced_at'];
		?>
		<div class="card" style="max-width:none">
			<h2 class="title"><?php esc_html_e( 'Status', 'gohighlevel-integration' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Cached contacts:', 'gohighlevel-integration' ); ?></strong>
				<?php echo esc_html( number_format_i18n( (int) $state['count'] ) ); ?><br />
				<strong><?php esc_html_e( 'Last successful sync:', 'gohighlevel-integration' ); ?></strong>
				<?php
				echo $synced
					? esc_html(
						sprintf(
							/* translators: %s: human readable time difference. */
							__( '%s ago', 'gohighlevel-integration' ),
							human_time_diff( $synced, time() )
						)
					)
					: esc_html__( 'never', 'gohighlevel-integration' );
				?>
				<br />
				<strong><?php esc_html_e( 'Custom fields discovered:', 'gohighlevel-integration' ); ?></strong>
				<?php echo esc_html( number_format_i18n( count( GHLD_Repository::custom_fields() ) ) ); ?>
			</p>
			<?php self::render_photo_diagnostics(); ?>
			<?php self::render_enrich_progress(); ?>
			<?php if ( ! empty( $state['error'] ) ) : ?>
				<p class="notice notice-error" style="padding:8px 12px">
					<strong><?php esc_html_e( 'Last error:', 'gohighlevel-integration' ); ?></strong>
					<?php echo esc_html( $state['error'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! GHLD_Settings::is_configured() ) : ?>
				<p><em><?php esc_html_e( 'Add a token (and a Location ID for API v2) below, save, then sync.', 'gohighlevel-integration' ); ?></em></p>
			<?php endif; ?>
			<p class="description" style="margin-bottom:.5em">
				<?php esc_html_e( '"Look for new photos" asks GoHighLevel about every contact again, including ones that had no headshot when they were last checked — use it after uploading photos rather than waiting a day for the next automatic check.', 'gohighlevel-integration' ); ?>
			</p>
			<p>
				<?php
				$ghld_actions = array(
					'ghld_sync'    => __( 'Sync now', 'gohighlevel-integration' ),
					'ghld_recheck' => __( 'Look for new photos', 'gohighlevel-integration' ),
					'ghld_test'    => __( 'Test connection', 'gohighlevel-integration' ),
				);
				foreach ( $ghld_actions as $action => $label ) :
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
						<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
						<?php wp_nonce_field( $action ); ?>
						<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</p>
			<p>
				<button type="button" class="button button-primary" data-ghld-fetch-all>
					<?php esc_html_e( 'Fetch every contact now', 'gohighlevel-integration' ); ?>
				</button>
				<span data-ghld-fetch-status style="margin-left:.75em"></span>
			</p>
			<div style="max-width:32em;height:6px;background:#dcdcde;border-radius:3px;overflow:hidden;margin:0 0 1em">
				<div data-ghld-fetch-bar style="width:0;height:100%;background:#2271b1;transition:width .2s"></div>
			</div>
			<p class="description" style="margin-bottom:1.5em">
				<?php esc_html_e( 'Works through every contact in batches without waiting for the background schedule, and shows how far it has got. Leave the page open until it finishes; if you close it, press the button again and it carries on from where it stopped.', 'gohighlevel-integration' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ghld_inspect" />
				<?php wp_nonce_field( 'ghld_inspect' ); ?>
				<label for="ghld-who"><?php esc_html_e( 'Inspect a contact:', 'gohighlevel-integration' ); ?></label>
				<input type="text" id="ghld-who" name="who" class="regular-text" placeholder="<?php esc_attr_e( 'Name or email — blank for the first contact', 'gohighlevel-integration' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Inspect', 'gohighlevel-integration' ); ?></button>
				<p class="description"><?php esc_html_e( 'Naming a contact fetches that record on its own, which returns fields the contact list can leave out.', 'gohighlevel-integration' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Report on the last batch of individually fetched contacts.
	 *
	 * @return void
	 */
	protected static function render_enrich_progress() {
		if ( empty( GHLD_Settings::get( 'deep_sync' ) ) ) {
			// The one setting that decides whether headshots in a file-upload
			// field can work at all, so its being off is worth saying loudly.
			?>
			<p class="notice notice-warning" style="padding:8px 12px">
				<strong><?php esc_html_e( '"Fetch full records" is off.', 'gohighlevel-integration' ); ?></strong>
				<?php esc_html_e( 'GoHighLevel does not send file-upload fields with the contact list, so headshots stored in one cannot arrive until this is on.', 'gohighlevel-integration' ); ?>
			</p>
			<?php

			return;
		}

		$state = GHLD_Repository::state();
		if ( ! isset( $state['enrich_fetched'] ) ) {
			return;
		}

		?>
		<p>
			<strong><?php esc_html_e( 'Last full-record batch:', 'gohighlevel-integration' ); ?></strong>
			<?php
			printf(
				/* translators: 1: contacts fetched, 2: headshots found. */
				esc_html__( '%1$s contacts fetched individually, %2$s of them gave up a headshot.', 'gohighlevel-integration' ),
				esc_html( number_format_i18n( (int) $state['enrich_fetched'] ) ),
				esc_html( number_format_i18n( isset( $state['enrich_resolved'] ) ? (int) $state['enrich_resolved'] : 0 ) )
			);
			?>
			<?php if ( ! empty( $state['enrich_remaining'] ) ) : ?>
				<br />
				<strong>
					<?php
					printf(
						/* translators: %s: contacts still to fetch. */
						esc_html__( '%s contacts still have no headshot — press "Sync now" again to keep going.', 'gohighlevel-integration' ),
						esc_html( number_format_i18n( (int) $state['enrich_remaining'] ) )
					);
					?>
				</strong>
			<?php endif; ?>
			<?php if ( empty( $state['enrich_resolved'] ) && ! empty( $state['enrich_fetched'] ) ) : ?>
				<br />
				<em><?php esc_html_e( 'None of them did, so the single-contact endpoint is not carrying that field either — use "Inspect a contact" on someone whose photo you have set to see what it does send.', 'gohighlevel-integration' ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Say what the cache actually holds for the mapped photo field.
	 *
	 * "The headshots aren't showing" has several causes that look identical on
	 * the front end — the field isn't mapped to the right key, the value isn't a
	 * URL, or the contacts were cached before the field was filled in. This
	 * prints enough to tell them apart without opening the database.
	 *
	 * @return void
	 */
	protected static function render_photo_diagnostics() {
		$contacts = get_option( GHLD_Repository::OPTION_CONTACTS, array() );
		$contacts = is_array( $contacts ) ? $contacts : array();

		if ( empty( $contacts ) ) {
			return;
		}

		$field = (string) GHLD_Settings::get( 'photo_field', '' );
		$key   = ( 0 === strpos( $field, 'cf:' ) ) ? substr( $field, 3 ) : '';

		$from_field   = 0;
		$from_profile = 0;
		$samples      = array();
		$key_counts   = array();

		foreach ( $contacts as $contact ) {
			// Which source actually produced the photo matters here: the
			// resolver falls back to GoHighLevel's own profile picture, so a
			// card showing an image does not mean the mapping worked.
			$mapped = ( '' !== $key && ! empty( $contact['custom'][ $key ] ) )
				? GHLD_Contact::first_url( $contact['custom'][ $key ] )
				: '';

			if ( '' !== $mapped ) {
				$from_field++;
			} elseif ( ! empty( $contact['photo'] ) ) {
				$from_profile++;
			}

			if ( '' === $mapped && '' !== $key && ! empty( $contact['custom'][ $key ] ) && count( $samples ) < 3 ) {
				$samples[] = (string) $contact['custom'][ $key ];
			}

			if ( ! empty( $contact['custom'] ) && is_array( $contact['custom'] ) ) {
				foreach ( array_keys( $contact['custom'] ) as $custom_key ) {
					$key_counts[ $custom_key ] = isset( $key_counts[ $custom_key ] ) ? $key_counts[ $custom_key ] + 1 : 1;
				}
			}
		}

		?>
		<p>
			<strong><?php esc_html_e( 'Headshots:', 'gohighlevel-integration' ); ?></strong>
			<?php
			printf(
				/* translators: 1: from the mapped field, 2: from the profile picture, 3: total cached contacts. */
				esc_html__( '%1$s from the mapped field, %2$s from the GoHighLevel profile picture, out of %3$s contacts.', 'gohighlevel-integration' ),
				esc_html( number_format_i18n( $from_field ) ),
				esc_html( number_format_i18n( $from_profile ) ),
				esc_html( number_format_i18n( count( $contacts ) ) )
			);
			?>
		</p>
		<?php

		if ( $from_field > 0 || '' === $key ) {
			return;
		}

		if ( ! empty( $samples ) ) {
			?>
			<p class="notice notice-warning" style="padding:8px 12px">
				<?php
				printf(
					/* translators: %s: custom field key. */
					esc_html__( 'Every cached contact has an empty or unusable value for "%s". The plugin reads it as:', 'gohighlevel-integration' ),
					esc_html( $key )
				);
				?>
				<br />
				<?php foreach ( $samples as $sample ) : ?>
					<code><?php echo esc_html( $sample ); ?></code><br />
				<?php endforeach; ?>
				<?php esc_html_e( 'A headshot needs to be a full https:// image URL.', 'gohighlevel-integration' ); ?>
			</p>
			<?php

			return;
		}

		arsort( $key_counts );
		$in_use = array_slice( array_keys( $key_counts ), 0, 12 );
		?>
		<p class="notice notice-warning" style="padding:8px 12px">
			<?php
			printf(
				/* translators: %s: custom field key. */
				esc_html__( 'No cached contact carries any value for "%s". Either the field is empty in GoHighLevel, or the mapping points at the wrong key — press "Sync now" after filling it in.', 'gohighlevel-integration' ),
				esc_html( $key )
			);
			?>
			<?php if ( ! empty( $in_use ) ) : ?>
				<br />
				<strong><?php esc_html_e( 'Keys that do hold values:', 'gohighlevel-integration' ); ?></strong>
				<code><?php echo esc_html( implode( ', ', $in_use ) ); ?></code>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Shortcode cheat-sheet.
	 *
	 * @return void
	 */
	protected static function render_usage() {
		?>
		<h2><?php esc_html_e( 'Using the directory', 'gohighlevel-integration' ); ?></h2>
		<p><?php esc_html_e( 'Put the shortcode on any page or post:', 'gohighlevel-integration' ); ?></p>
		<p><code>[ghl_directory]</code></p>
		<p><?php esc_html_e( 'Every setting above can be overridden per shortcode:', 'gohighlevel-integration' ); ?></p>
		<p><code>[ghl_directory tags="member - physician" columns="4" per_page="36" filters="search,tag,city,sort" show="photo,title,company,location,tags" layout="grid" orderby="name" order="asc"]</code></p>
		<ul class="ul-disc">
			<li><code>tags</code> / <code>exclude_tags</code> — <?php esc_html_e( 'narrow this directory to certain GoHighLevel tags (applied on top of the global setting).', 'gohighlevel-integration' ); ?></li>
			<li><code>filters</code> — <?php esc_html_e( 'which controls appear in the filter bar: search, tag, city, state, company, sort, or cf:your_field_key. Use filters="none" to hide the bar.', 'gohighlevel-integration' ); ?></li>
			<li><code>show</code> — <?php esc_html_e( 'card contents: photo, title, specialty, company, location, address, tags, email, phone, fax, website, bio, or cf:your_field_key.', 'gohighlevel-integration' ); ?></li>
			<li><code>modal_show</code> — <?php esc_html_e( 'the same element names, for the contact page and modal.', 'gohighlevel-integration' ); ?></li>
			<li><code>layout</code> — <?php esc_html_e( 'grid (default) or list.', 'gohighlevel-integration' ); ?></li>
			<li><code>name_format</code> — <?php esc_html_e( 'name_title (default) prints "Name, Title"; name puts the title on its own line.', 'gohighlevel-integration' ); ?></li>
			<li><code>modal</code> / <code>modal_show</code> — <?php esc_html_e( 'modal="no" makes cards non-clickable; modal_show takes the same element names as show.', 'gohighlevel-integration' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Render `cf:` options for a custom-field select.
	 *
	 * @param array  $fields   Custom field definitions.
	 * @param string $selected Currently selected value.
	 * @return void
	 */
	protected static function field_options( array $fields, $selected ) {
		$rendered = array();

		foreach ( $fields as $field ) {
			$value      = 'cf:' . $field['key'];
			$rendered[] = $value;
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $field['name'] )
			);
		}

		// A mapping can name a field the last sync didn't return — the sync
		// hasn't run yet, or the token lacks the custom-fields scope. Render it
		// anyway: a <select> that omits its own stored value would reset the
		// mapping to "none" the next time the form is saved.
		$selected = (string) $selected;
		if ( 0 === strpos( $selected, 'cf:' ) && ! in_array( $selected, $rendered, true ) ) {
			printf(
				'<option value="%1$s" selected="selected">%2$s</option>',
				esc_attr( $selected ),
				esc_html(
					sprintf(
						/* translators: %s: custom field key. */
						__( '%s (not seen in the last sync)', 'gohighlevel-integration' ),
						substr( $selected, 3 )
					)
				)
			);
		}
	}

	/**
	 * Render a list of checkboxes for an array setting.
	 *
	 * @param string $name    Field name including the option prefix.
	 * @param array  $choices value => label.
	 * @param array  $current Selected values.
	 * @return void
	 */
	protected static function checkbox_list( $name, array $choices, array $current ) {
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:inline-block;min-width:14em;margin:0 1em .35em 0"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( in_array( (string) $value, array_map( 'strval', $current ), true ), true, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Card element choices, including custom fields.
	 *
	 * @param array $fields Custom field definitions.
	 * @return array
	 */
	protected static function show_choices( array $fields ) {
		$choices = array(
			'photo'    => __( 'Photo', 'gohighlevel-integration' ),
			'title'     => __( 'Job title', 'gohighlevel-integration' ),
			'specialty' => __( 'Primary specialty', 'gohighlevel-integration' ),
			'company'  => __( 'Company', 'gohighlevel-integration' ),
			'location' => __( 'City / state', 'gohighlevel-integration' ),
			'address'  => __( 'Street address', 'gohighlevel-integration' ),
			'tags'     => __( 'Tags', 'gohighlevel-integration' ),
			'bio'      => __( 'Bio', 'gohighlevel-integration' ),
			'email'    => __( 'Email address', 'gohighlevel-integration' ),
			'phone'    => __( 'Phone number', 'gohighlevel-integration' ),
			'fax'      => __( 'Fax number', 'gohighlevel-integration' ),
			'website'  => __( 'Website', 'gohighlevel-integration' ),
		);

		foreach ( $fields as $field ) {
			$choices[ 'cf:' . $field['key'] ] = $field['name'];
		}

		return $choices;
	}

	/**
	 * Filter-bar choices, including custom fields.
	 *
	 * @param array $fields Custom field definitions.
	 * @return array
	 */
	protected static function filter_choices( array $fields ) {
		$choices = array(
			'search'  => __( 'Search box', 'gohighlevel-integration' ),
			'tag'     => __( 'Tag', 'gohighlevel-integration' ),
			'city'    => __( 'City', 'gohighlevel-integration' ),
			'state'   => __( 'State', 'gohighlevel-integration' ),
			'company' => __( 'Company', 'gohighlevel-integration' ),
			'sort'    => __( 'Sort control', 'gohighlevel-integration' ),
		);

		foreach ( $fields as $field ) {
			$choices[ 'cf:' . $field['key'] ] = sprintf(
				/* translators: %s: custom field name. */
				__( 'Custom: %s', 'gohighlevel-integration' ),
				$field['name']
			);
		}

		return $choices;
	}
}
