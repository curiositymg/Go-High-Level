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
							<p class="description"><?php esc_html_e( 'Printed under the name in the detail modal. A multi-select field comes through as a comma-separated list, e.g. "Infectious Disease, Internal Medicine".', 'gohighlevel-integration' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Email and phone are off by default — only turn them on for contacts who expect their details to be public.', 'gohighlevel-integration' ); ?></p>
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
						<th scope="row"><?php esc_html_e( 'Detail modal', 'gohighlevel-integration' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[modal]" value="1" <?php checked( ! empty( $settings['modal'] ) ); ?> />
								<?php esc_html_e( 'Open a contact\'s full details when their card is clicked', 'gohighlevel-integration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show in the detail modal', 'gohighlevel-integration' ); ?></th>
						<td>
							<?php self::checkbox_list( $name . '[modal_show]', self::show_choices( $fields ), (array) $settings['modal_show'] ); ?>
							<p class="description"><?php esc_html_e( 'Separate from the card list above, so the modal can carry phone, email or a full address without printing them on every card in the grid.', 'gohighlevel-integration' ); ?></p>
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
			<?php if ( ! empty( $state['error'] ) ) : ?>
				<p class="notice notice-error" style="padding:8px 12px">
					<strong><?php esc_html_e( 'Last error:', 'gohighlevel-integration' ); ?></strong>
					<?php echo esc_html( $state['error'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( ! GHLD_Settings::is_configured() ) : ?>
				<p><em><?php esc_html_e( 'Add a token (and a Location ID for API v2) below, save, then sync.', 'gohighlevel-integration' ); ?></em></p>
			<?php endif; ?>
			<p>
				<?php foreach ( array( 'ghld_sync' => __( 'Sync now', 'gohighlevel-integration' ), 'ghld_test' => __( 'Test connection', 'gohighlevel-integration' ) ) as $action => $label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
						<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
						<?php wp_nonce_field( $action ); ?>
						<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
					</form>
				<?php endforeach; ?>
			</p>
		</div>
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
			<li><code>show</code> — <?php esc_html_e( 'card contents: photo, title, company, location, tags, email, phone, website, bio, or cf:your_field_key.', 'gohighlevel-integration' ); ?></li>
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
