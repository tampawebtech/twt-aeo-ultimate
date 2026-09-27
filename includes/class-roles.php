<?php
/**
 * Role Access Control.
 *
 * Lets an administrator choose which WordPress roles may use the plugin's tools
 * without being full admins. It works by granting a plugin-specific capability
 * (`manage_twtaeo`) to the chosen roles, and — only inside the plugin's own
 * screens and AJAX actions — letting that capability satisfy the plugin's
 * existing `manage_options` checks. Outside the plugin, allowed roles gain no
 * extra site-wide power, and the Settings/credentials pages stay admin-only.
 *
 * No role objects are mutated: access is computed live from an option, so it's
 * fully reversible and leaves nothing behind.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Roles {

	/** The plugin's own capability. */
	const CAP = 'manage_twtaeo';

	/** Option holding the role slugs allowed to use the plugin. */
	const OPTION = 'twtaeo_allowed_roles';

	/** Plugin pages that stay administrator-only regardless of role access. */
	const ADMIN_ONLY_PAGES = array( 'twt-aeo-settings', 'twt-aeo-access', 'twt-aeo-modules', 'twt-aeo-diagnostics' );

	public static function register_hooks() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
	}

	// ── Access data ──────────────────────────────────────────────────────────────

	/** @return string[] Role slugs allowed to use the plugin (never administrator — it always is). */
	public static function get_allowed_roles() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? array_values( $r ) : array();
	}

	/** Persist the allowed-role selection, filtered to real, non-admin roles. */
	public static function save_allowed_roles( array $roles ) {
		$valid = array_keys( self::editable_roles() );
		$clean = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
		update_option( self::OPTION, $clean, false );
	}

	/** Roles an admin may grant access to — everything except administrator. */
	public static function editable_roles() {
		$roles = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		unset( $roles['administrator'] );
		return is_array( $roles ) ? $roles : array();
	}

	// ── Capability grant ─────────────────────────────────────────────────────────

	/**
	 * Grant the plugin capability to admins + allowed roles, and — only within the
	 * plugin's own context — let it stand in for `manage_options`.
	 *
	 * @param array   $allcaps
	 * @param array   $caps
	 * @param array   $args
	 * @param WP_User $user
	 * @return array
	 */
	public static function grant( $allcaps, $caps, $args, $user ) {
		$has_plugin = ! empty( $allcaps['manage_options'] );

		if ( ! $has_plugin ) {
			$user_roles = ( is_object( $user ) && isset( $user->roles ) ) ? (array) $user->roles : array();
			if ( array_intersect( $user_roles, self::get_allowed_roles() ) ) {
				$has_plugin = true;
			}
		}

		if ( $has_plugin ) {
			$allcaps[ self::CAP ] = true;
			if ( self::is_plugin_context() ) {
				$allcaps['manage_options'] = true;
			}
		}

		return $allcaps;
	}

	/** True on the plugin's own operational screens or AJAX actions (not the admin-only ones). */
	private static function is_plugin_context() {
		// Read-only context detection inside a user_has_cap filter: it inspects the
		// current screen / AJAX action only to scope the capability and changes no
		// state, so nonce verification does not apply.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_key( wp_unslash( $_GET['page'] ) );
			if ( 0 === strpos( $page, 'twt-aeo' ) && ! in_array( $page, self::ADMIN_ONLY_PAGES, true ) ) {
				return true;
			}
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_REQUEST['action'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
			if ( 0 === strpos( $action, 'twtaeo' ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
	}

	// ── Admin page (administrator-only) ──────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$saved = false;
		if ( isset( $_POST['twtaeo_roles_save'] ) ) {
			check_admin_referer( 'twtaeo_roles' );
			$sel = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array();
			self::save_allowed_roles( $sel );
			$saved = true;
		}

		$allowed = self::get_allowed_roles();
		?>
		<div class="wrap twt-aeo-wrap">
			<h1><?php esc_html_e( 'Access &amp; Roles', 'twt-aeo-ultimate' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Choose which user roles can use the TWT AEO tools without being full administrators. Administrators always have access. Settings, Modules and this page stay administrator-only so credentials and site configuration are never exposed.', 'twt-aeo-ultimate' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access updated.', 'twt-aeo-ultimate' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'twtaeo_roles' ); ?>
				<input type="hidden" name="twtaeo_roles_save" value="1">
				<table class="widefat striped" style="max-width:640px;margin-top:12px;">
					<thead><tr>
						<th><?php esc_html_e( 'Role', 'twt-aeo-ultimate' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Can use AEO tools', 'twt-aeo-ultimate' ); ?></th>
					</tr></thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Administrator', 'twt-aeo-ultimate' ); ?></strong></td>
							<td><span class="twt-aeo-muted"><?php esc_html_e( 'Always', 'twt-aeo-ultimate' ); ?></span></td>
						</tr>
						<?php foreach ( self::editable_roles() as $slug => $role ) : ?>
							<tr>
								<td><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></td>
								<td>
									<label>
										<input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed, true ) ); ?>>
										<?php esc_html_e( 'Allow', 'twt-aeo-ultimate' ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save access', 'twt-aeo-ultimate' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
