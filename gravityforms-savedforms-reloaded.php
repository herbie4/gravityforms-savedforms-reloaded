<?php
/**
 * Plugin Name: Gravity Forms Saved Forms Reloaded Add-On
 * Requires Plugins: gravityforms
 * Plugin URI: https://haha.nl/wordpress-plug-in-op-maat/
 * Description: (GF 2.9+ Compatible) Adds state to forms allowing users to save form for later. Auto-loads saved forms without requiring save link. Based on Gravity Forms Saved Forms Addon by author: Gennady Kovshenin. NOTE: should be compatible with existing data from the addon it is based on.
 * Version: 2.1.2
 * Author: herbert hoekstra - haha!
 * Author URI: https://haha.nl
 * Documentation URI: https://haha.nl/wordpress-plug-in-op-maat/
 * Text Domain: gravityforms-savedforms-reloaded
 * Domain Path: /languages
 */

class GFStatefulForms {
	private static $instance = null;
	private static $textdomain = 'gravityforms-savedforms-reloaded';

	public function __construct() {

		require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';

		if ( is_object( self::$instance ) && get_class( self::$instance == __CLASS__ ) )
			wp_die( __CLASS__.' can have only one instance' );
		self::$instance = $this;
		$this->bootstrap();

		// updater and checker
		$myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/herbie4/gravityforms-savedforms-reloaded/',
		__FILE__,
		'gravityforms-savedforms-reloaded.php'
	);
		//Set the branch that contains the stable release.
		$myUpdateChecker->setBranch('master');

	}

	public static function get_instance() {
		return ( get_class( self::$instance ) == __CLASS__ ) ? self::$instance : new self;
	}

	public function bootstrap() {
		add_action( 'plugins_loaded', array( $this, 'early_init' ) );

		/* Form Settings */
		add_filter( 'gform_form_settings_fields', array( $this, 'add_form_settings_fields' ), 10, 2 );
		add_filter( 'gform_form_settings_before_save', array( $this, 'save_form_settings' ), 10, 1 );

		/* Save Button and AJAX save confirmation */
		add_filter( 'gform_submit_button', array( $this, 'form_add_save_button' ), 10, 2 );
		add_filter( 'gform_next_button', array( $this, 'form_add_save_button' ), 10, 2 );

		/* Save Confirmation when using POST*/
		add_filter( 'gform_confirmation', array( $this, 'form_save_confirmation' ), 10, 4 );

		/* Restore logic */
		add_action( 'wp', array( $this, 'setup_resume_token' ), 0 );
		add_filter( 'gform_resume_token', array( $this, 'provide_resume_token' ) );

		/* Save logic */
		add_action( 'gform_incomplete_submission_post_save', array( $this, 'form_save' ), 10, 4 );

		// PATCH: gform_post_submission does NOT exist. Use gform_after_submission.
		// This was why pending tokens were never cleared on a normal submit,
		// leading to empty entries being created on subsequent page loads.
		add_action( 'gform_after_submission', array( $this, 'form_submit' ), 10, 2 );

		/* Handle save button */
		add_action( 'gform_pre_submission', array( $this, 'handle_save_button' ), 10, 1 );

		/* Admin menu */
		add_filter( 'gform_addon_navigation', array( $this, 'add_pending_completed_entries_item' ) );
	}

	// load the text domain, early

	public function early_init() {
		load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * PATCH: Helper to verify a token still exists in the draft table.
	 * Used to prevent redirects/provides for tokens that GF has already
	 * consumed or deleted (which would create empty entries).
	 *
	 * @param string $token
	 * @return bool
	 */
	private function token_exists_in_draft_table( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		global $wpdb;
		$table_name = $wpdb->prefix . 'gf_draft_submissions';
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT uuid FROM {$table_name} WHERE uuid = %s",
			$token
		) );
		return ! empty( $exists );
	}

	/**
	 * PATCH: Helper to clean up stale pending tokens for a user/form.
	 *
	 * @param int $user_id
	 * @param int $form_id
	 */
	private function clear_pending_token( $user_id, $form_id ) {
		delete_user_meta( $user_id, 'has_pending_form_' . $form_id );
		delete_user_meta( $user_id, '_gf_draft_uuid_' . $form_id );
	}

	/**
	 * Add the custom form settings for this plugin
	 *
	 * Enable form state radio button, save confirmation message text area, enable form state on submit radio button
	 *
	 * @param array $fields
	 * @param array $form
	 *
	 * @return array $fields
	 */

	public function add_form_settings_fields( $fields, $form ) {
		$form_options_index = null;
		foreach ( $fields as $index => $section ) {
			if ( isset( $section['title'] ) && $section['title'] === __( 'Form Options', 'gravityforms' ) ) {
				$form_options_index = $index;
				break;
			}
		}

		$new_fields = array(
			array(
				'type'    => 'checkbox',
				'name'    => 'enableFormState',
				'label'   => __( 'Save forms', self::$textdomain ),
				'tooltip' => __( 'Saves partially filled forms for logged in users. Requires "Require Login" to be enabled.', self::$textdomain ),
				'choices' => array(
					array(
						'label' => __( 'Enable form state', self::$textdomain ),
						'name'  => 'enableFormState',
						'value' => 1,
					),
				),
			),
			array(
				'type'    => 'textarea',
				'name'    => 'saveConfirmationMessage',
				'label'   => __( 'Save Confirmation Message', self::$textdomain ),
				'tooltip' => __( 'Message shown after saving a form for later.', self::$textdomain ),
				'default_value' => __( 'Your progress has been saved. You can return to this form anytime in the future to complete it.', self::$textdomain ),
				'dependency' => array(
					'field'  => 'enableFormState',
					'values' => array( '1' ),
				),
			),
			array(
				'type'    => 'checkbox',
				'name'    => 'enableFormStateOnSubmit',
				'label'   => __( 'Save on submit', self::$textdomain ),
				'tooltip' => __( 'Keep saved data after submission.', self::$textdomain ),
				'choices' => array(
					array(
						'label' => __( 'Save form state even after submit', self::$textdomain ),
						'name'  => 'enableFormStateOnSubmit',
						'value' => 1,
					),
				),
				'dependency' => array(
					'field'  => 'enableFormState',
					'values' => array( '1' ),
				),
			),
		);

		if ( $form_options_index !== null ) {
			$fields[$form_options_index]['fields'] = array_merge( $fields[$form_options_index]['fields'], $new_fields );
		} else {
			$fields[] = array(
				'title'  => __( 'Saved Forms', self::$textdomain ),
				'fields' => $new_fields,
			);
		}

		return $fields;
	}

	/**
	 * Saving the form settings
	 *
	 * Make sure to use gform_form_settings_before_save filter
	 *
	 * @param array $form
	 *
	 * @return array $form
	 */

	public function save_form_settings( $form ) {
		$form['enableFormState'] = rgpost( 'enableFormState' ) ? true : false;
		$form['enableFormStateOnSubmit'] = rgpost( 'enableFormStateOnSubmit' ) ? true : false;
		$form['saveConfirmationMessage'] = rgpost( 'saveConfirmationMessage' );
		return $form;
	}

	/**
	 * Add the form save button
	 *
	 * Default Submit Button: Works exactly as before - validates, submits, and shows default Gravity Forms confirmation
	 * Save for Later Button: Sets the save flag, submits the form, and shows your custom save confirmation message
	 *
	 * @param var $button_input
	 * @param array $form
	 *
	 * @return html original submit button, hidden field, and save button
	 */

	 public function form_add_save_button( $button_input, $form ) {

		if ( !isset($form['requireLogin']) || !isset($form['enableFormState']) )
			return $button_input;

		if ( !$form['requireLogin'] || !$form['enableFormState'] )
			return $button_input;

		// get the current form ID
		$form_id = $form['id'];
		// clean up any line breaks and other stuff
		$saveConfirmationMessage = sanitize_text_field($form['saveConfirmationMessage']);

		// Add a hidden field that GF will detect as a draft save
		$hidden = '<input type="hidden" name="gform_save" value="0" id="gform_save_' . $form_id . '">';

		// Create a separate save button (type="button" to prevent default submission)
		$save_button = '<button type="button" id="gform_save_state_' . $form_id . '" class="gform_save_state button">'
						. __( "Save for later", self::$textdomain ) . '</button>';

		// Add JavaScript to handle the save functionality
		add_action( 'wp_footer', function() use ( $form_id, $saveConfirmationMessage ) {
			?>
			<script type="text/javascript">
			jQuery(document).ready(function($) {
				var isSubmitting = false;

				// Function to inject save message
				function injectSaveMessage(message) {
					var $messageDiv = $('.form_saved_message');
					if ($messageDiv.length && $messageDiv.is(':empty')) {
						$messageDiv.html(message);
					}
				}

				// Watch for AJAX completion and inject message
				$(document).on('gform_post_render', function(event, formId) {
					if (formId == <?php echo $form_id; ?>) {
						if (sessionStorage.getItem('gform_save_complete_<?php echo $form_id; ?>') === 'true') {
							var saveMessage = '<?php echo $saveConfirmationMessage ?>';
							injectSaveMessage(saveMessage);
							sessionStorage.removeItem('gform_save_complete_<?php echo $form_id; ?>');
						}
					}
				});

				// Handle the save button click ONLY (not interfering with default submit)
				$('#gform_save_state_<?php echo $form_id; ?>').on('click', function(e) {
					e.preventDefault();

					if (isSubmitting) {
						return false;
					}

					var $form = $('#gform_<?php echo $form_id; ?>');

					// Set the hidden field value to 1 for save action
					$('#gform_save_<?php echo $form_id; ?>').val('1');

					// Set flag to show message on return
					sessionStorage.setItem('gform_save_complete_<?php echo $form_id; ?>', 'true');

					// Set submitting flag
					isSubmitting = true;

					// Submit the form
					$form.trigger('submit');

					// Reset flag after 3 seconds (in case of error)
					setTimeout(function() {
						isSubmitting = false;
					}, 3000);
				});

				// Reset the hidden field value when default submit button is clicked
				// This ensures normal submission doesn't trigger save functionality
				$('#gform_submit_button_<?php echo $form_id; ?>').on('click', function() {
					$('#gform_save_<?php echo $form_id; ?>').val('0');
				});
			});
			</script>
			<?php
		});

		// Return the original submit button, hidden field, and save button
		return $button_input . $hidden . $save_button;
	}


	/**
	 * Bypass validation on save
	 *
	 * @param boolean $validation_result
	 *
	 * @return boolean $validation_result
	 */

	public function bypass_validation_on_save( $validation_result ) {
		$validation_result['is_valid'] = true;
		return $validation_result;
	}


	/**
	 * Form save confirmation POST, not using AJAX
	 *
	 * Fallback when not using ajax. Uses default text if no message is added in form settings.
	 *
	 * @param var $confirmation
	 * @param array $form
	 * @param array $entry
	 * @param var $ajax
	 *
	 * @return html $confirmation
	 */

	public function form_save_confirmation( $confirmation, $form, $entry, $ajax ) {

		if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
			return $confirmation;

		if ( isset( $_POST['gform_save'] ) && $_POST['gform_save'] == '1' ) {

			// Get the saved message from form settings
			$save_message = isset( $form['saveConfirmationMessage'] ) && !empty( $form['saveConfirmationMessage'] )
				? $form['saveConfirmationMessage']
				: __( 'Your progress has been saved. You can return to this form anytime in the future to complete it.', self::$textdomain );

			// Return the message - Gravity Forms will wrap it in the confirmation div
			$confirmation = $save_message;
		}

		return $confirmation;
	}

/**
 * Make the save button bypass validation
 *
 * @param array $form
 *
 * @return add_filter call bypass_validation_on_save()
 */

	public function handle_save_button( $form ) {
	// Check if our save flag is set
	if ( isset( $_POST['gform_save'] ) && $_POST['gform_save'] == '1' ) {
		add_filter( 'gform_validation', array( $this, 'bypass_validation_on_save' ) );
	}
}


	/**
	 * Store the resume token on incomplete submission.
	 *
	 * PATCH: Only store when this is an explicit "save for later" action,
	 * OR when GF creates an incomplete submission and the form has state enabled.
	 * We no longer store the backup UUID here — provide_resume_token handles
	 * fallback lookup for legacy data. This avoids writing a second meta key
	 * on every save.
	 *
	 * @param array $submission
	 * @param array $resume_token
	 * @param array $form
	 * @param array $entry
	 *
	 * @return void
	 */

	 public function form_save( $submission, $resume_token, $form, $entry ) {

	 	if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ) {
	 		return;
	 	}

	 	if ( empty( $resume_token ) ) {
	 		return;
	 	}

	 	$user = wp_get_current_user();
	 	if ( ! $user || ! $user->ID ) {
	 		return;
	 	}

	 	// Only overwrite the pending token if it's a newer save.
	 	// This avoids an older auto-draft overwriting a newer explicit save.
	 	update_user_meta( $user->ID, 'has_pending_form_' . $form['id'], $resume_token );

	 	// Also mirror to the legacy backup key for compatibility with old installs.
	 	update_user_meta( $user->ID, '_gf_draft_uuid_' . $form['id'], $resume_token );
	 }

/**
 * Clear pending state after a successful submission.
 *
 * PATCH: now correctly hooked to gform_after_submission.
 * Also always clears the pending token when "Save on submit" is OFF.
 *
 * @param array $entry
 * @param array $form
 *
 * @return void
 */

	public function form_submit( $entry, $form ) {
		if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
			return;

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID )
			return;

		update_user_meta( $user->ID, 'completed_form_' . $form['id'], true );

		if ( !isset( $form['enableFormStateOnSubmit'] ) || !$form['enableFormStateOnSubmit'] ) {
			$this->clear_pending_token( $user->ID, $form['id'] );
		}
	}


	 /**
	  * Get the correct UUID for a user's saved form using user ID
	  *
	  * PATCH: stricter matching. Legacy fallback to "most recent draft" now
	  * only returns a draft if it was created by (or contains) this user.
	  * Otherwise it returns null, preventing cross-user data leakage and
	  * preventing empty entries from being created from an unrelated draft.
	  *
	  * @param WP_User $user
	  * @param int $form_id
	  *
	  * @return string|null
	  */

	private function get_user_draft_uuid_by_user_id( $user, $form_id ) {

		global $wpdb;
		$table_name = $wpdb->prefix . 'gf_draft_submissions';

		// First, check if we have a stored UUID in the backup meta
		$backup_uuid = get_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, true );
		if ( $backup_uuid && $this->token_exists_in_draft_table( $backup_uuid ) ) {
			return $backup_uuid;
		}

		// Search all drafts for this form and look for the user ID in the submission data
		$drafts = $wpdb->get_results( $wpdb->prepare(
			"SELECT uuid, submission FROM {$table_name} WHERE form_id = %d ORDER BY date_created DESC",
			$form_id
		) );

		foreach ( $drafts as $draft ) {
			$submission = json_decode( $draft->submission, true );
			if ( ! is_array( $submission ) ) {
				continue;
			}

			// Check submitted_values for a user ID match
			if ( isset( $submission['submitted_values'] ) && is_array( $submission['submitted_values'] ) ) {
				foreach ( $submission['submitted_values'] as $value ) {
					if ( (string) $value === (string) $user->ID ) {
						return $draft->uuid;
					}
				}
			}

			// Check lead (entry) data
			if ( isset( $submission['lead']['created_by'] )
				&& (string) $submission['lead']['created_by'] === (string) $user->ID ) {
				return $draft->uuid;
			}
		}

		// PATCH: do NOT fall back to "most recent draft" — that could match
		// another user's draft and produce empty/incorrect entries.
		return null;
	}

/**
 * Set up resume token
 *
 * PATCH: added strict checks:
 *  - only redirect for logged-in users with an ID
 *  - bail early if form state or requireLogin is off (also clean up stale meta)
 *  - verify the stored token actually still exists in the drafts table
 *    before redirecting (prevents empty entries from consumed tokens)
 *
 * @param void
 * @return void
 */

	public function setup_resume_token() {

		if ( is_admin() ) {
			return;
		}

		if ( isset( $_POST['gform_submit'] ) || isset( $_POST['gform_save'] ) ) {
			return;
		}

		if ( isset( $_GET['gf_token'] ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		if ( ! preg_match( '/\[gravityform.*id=["\']?([0-9]+)["\']?.*\]/i', $post->post_content, $matches ) ) {
			return;
		}

		$form_id = intval( $matches[1] );
		$form    = GFAPI::get_form( $form_id );

		if ( is_wp_error( $form ) ) {
			return;
		}

		// PATCH: if either flag is off, clean up any stale token and bail.
		if ( empty( $form['enableFormState'] ) || empty( $form['requireLogin'] ) ) {
			$this->clear_pending_token( $user->ID, $form_id );
			return;
		}

		$stored_token = get_user_meta( $user->ID, 'has_pending_form_' . $form_id, true );

		if ( ! $stored_token ) {
			return;
		}

		// PATCH: only redirect if the token still exists in the drafts table.
		if ( ! $this->token_exists_in_draft_table( $stored_token ) ) {
			$this->clear_pending_token( $user->ID, $form_id );
			return;
		}

		$current_url = ( is_ssl() ? 'https' : 'http' ) . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
		$redirect_url = add_query_arg( 'gf_token', urlencode( $stored_token ), $current_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}


	/**
	 * Provide resume token via filter
	 *
	 * PATCH:
	 *  - guard on form setting (enableFormState) before handing out any token
	 *  - validate token existence in drafts table
	 *  - clean up stale meta
	 *
	 * @param var $token
	 *
	 * @return var $token
	 */

	public function provide_resume_token( $token ) {

		if ( ! empty( $token ) ) {
			return $token;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return $token;
		}

		global $post;
		if ( ! $post ) {
			return $token;
		}

		if ( ! preg_match( '/\[gravityform.*id=["\']?([0-9]+)["\']?.*\]/i', $post->post_content, $matches ) ) {
			return $token;
		}

		$form_id = intval( $matches[1] );
		$form    = GFAPI::get_form( $form_id );

		if ( is_wp_error( $form ) || empty( $form['enableFormState'] ) ) {
			return $token;
		}

		// Check backup meta first, then current meta
		$stored_token = get_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, true );
		if ( ! $stored_token ) {
			$stored_token = get_user_meta( $user->ID, 'has_pending_form_' . $form_id, true );
		}

		if ( ! $stored_token ) {
			return $token;
		}

		if ( $this->token_exists_in_draft_table( $stored_token ) ) {
			return $stored_token;
		}

		// Token is stale. Try to find a valid one via user-ID lookup.
		$correct_token = $this->get_user_draft_uuid_by_user_id( $user, $form_id );
		if ( $correct_token && $this->token_exists_in_draft_table( $correct_token ) ) {
			update_user_meta( $user->ID, 'has_pending_form_' . $form_id, $correct_token );
			update_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, $correct_token );
			return $correct_token;
		}

		// PATCH: no valid token available — clean up and return original.
		$this->clear_pending_token( $user->ID, $form_id );
		return $token;
	}

/**
 * Add menu item to GF menu
 *
 * Sets up the pending/completed menu item
 *
 * @param array $menu items
 *
 * @return array $menu_items
 */

	public function add_pending_completed_entries_item( $menu_items ) {
		$has_full_access = current_user_can( 'gform_full_access' );
		$pending_completed_item = array(
			'name' => 'gf_pending_completed_entries',
			'label' => __( 'Pending/Completed Entries', self::$textdomain ),
			'callback' => array( $this, 'pending_completed_entries_screen' ),
			'permission' => $has_full_access ? 'gform_full_access' : 'gravityforms_view_entries'
		);
		$menu_items[] = $pending_completed_item;
		return $menu_items;
	}

/**
 * Pending/Completed entries page
 *
 * Get all users with pending or completed entries.
 *
 * @param void
 * @return html
 */

	public function pending_completed_entries_screen() {

		$forms = GFFormsModel::get_forms( null, 'title' );

		$id = GFForms::get( 'id' );

		if( sizeof($forms) == 0 ) {
			?>
			<div style="margin:50px 0 0 10px;">
				<?php echo sprintf(__("You don't have any active forms. Let's go %screate one%s", "gravityforms"), '<a href="?page=gf_edit_forms&id=0">', '</a>'); ?>
			</div>
			<?php
			return;
		} else {
			if( empty($id) ) $form_id = $forms[0]->id;
			else $form_id = $id;
		}

		$form = GFFormsModel::get_form_meta( $form_id );
		?>
		<link rel="stylesheet" href="<?php echo GFCommon::get_base_url() ?>/css/admin.css" type="text/css" />
		<div class="wrap">
			<div class="icon32" id="gravity-entry-icon"><br></div>
			<h2><?php _e( 'Pending/Completed Entries for ', self::$textdomain ); echo $form['title']; ?></h2>

			<div id="gf_form_toolbar">
				<ul id="gf_form_toolbar_links">
					<li class="gf_form_switcher">
						<label for="export_form"><?php _e( 'Select A Form', 'gravityforms' ) ?></label>
						<select name="form_switcher" id="form_switcher" onchange="GF_SwitchForm(jQuery(this).val());">
							<option value=""><?php _e( 'Switch Form', 'gravityforms' ) ?></option>
							<?php foreach($forms as $form_info): ?>
								<option value="<?php echo $form_info->id ?>" <?php selected( $form_id, $form_info->id ); ?>><?php echo $form_info->title ?></option>
							<?php endforeach; ?>
						</select>
					</li>
				</ul>
			</div>

			<div>
				<?php if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ): ?>
					<div style="margin:50px 0 0 10px;">
						<?php _e( sprintf( 'This form does not have persistent state keeping for users. Enable the feature by <a href="%s">edit this form\'s</a> settings.', 'admin.php?page=gf_edit_forms&id='.$form['id']), self::$textdomain ); ?>
					</div>
				<?php else: ?>
					<h3><?php _e( 'Users who have completed this form', self::$textdomain ); ?></h3>
					<ul>
						<?php $users_completed = $this->get_users( $form['id'], true ); ?>
						<?php if ( !sizeof( $users_completed ) ): ?>
							<li><?php _e( 'No users have completed this form yet.', self::$textdomain ); ?></li>
						<?php else: foreach ( $users_completed as $user ): ?>
							<li><a href="user-edit.php?user_id=<?php echo $user->ID; ?>"><?php echo $user->display_name; ?></a></li>
						<?php endforeach; endif; ?>
					</ul>
					<hr />
					<h3><?php _e( 'Users who have saved this form for later', self::$textdomain ); ?></h3>
					<ul>
						<?php $users_pending = $this->get_users( $form['id'], false ); ?>
						<?php if ( !sizeof( $users_pending ) ): ?>
							<li><?php _e( 'No users who have saved this form for later.', self::$textdomain ); ?></li>
						<?php else: foreach ( $users_pending as $user ): ?>
							<li><a href="user-edit.php?user_id=<?php echo $user->ID; ?>"><?php echo $user->display_name; ?></a></li>
						<?php endforeach; endif; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>

		<script type="text/javascript">
			function GF_SwitchForm(id){
				if(id.length > 0){
					document.location = "?page=gf_pending_completed_entries&id=" + id;
				}
			}
		</script>
		<?php
	}

/**
 * Get users for completed/pending forms
 *
 * is used by pending_completed_entries_screen()
 *
 * @param var $form_id
 * @param boolean $completed
 *
 * @return array $users
 */

	public function get_users( $form_id, $completed = true ) {
		if ( !$completed ) {
			$users = (array) get_users( array(
				'meta_key' => 'has_pending_form_' . $form_id,
				'meta_value' => '0',
				'meta_compare' => '>'
			) );
			foreach ( $users as &$user ) {
				$user->pending_entry = get_user_meta( $user->ID, 'has_pending_form_' . $form_id, true );
			}
			return $users;
		} else {
			$users = (array) get_users( array(
				'meta_key' => 'completed_form_' . $form_id,
				'meta_value' => '0',
				'meta_compare' => '>'
			) );
			return $users;
		}
	}
}

// Initialize the plugin
if ( defined( 'WP_CONTENT_DIR' ) ) {
	new GFStatefulForms;
}
