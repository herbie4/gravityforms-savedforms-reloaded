<?php
/**
 * Plugin Name: Gravity Forms Saved Forms Reloaded Add-On
 * Requires Plugins: gravityforms
 * Plugin URI: https://haha.nl/wordpress-plug-in-op-maat/
 * Description: (GF 2.9+ Compatible) Adds state to forms allowing users to save form for later. Auto-loads saved forms without requiring save link. Based on Gravity Forms Saved Forms Addon by author: Gennady Kovshenin. NOTE: should be compatible with existing data from the addon it is based on.
 * Version: 2.0.5 beta
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
		if ( is_object( self::$instance ) && get_class( self::$instance == __CLASS__ ) )
			wp_die( __CLASS__.' can have only one instance' );
		self::$instance = $this;
		$this->bootstrap();
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
		add_action( 'gform_post_submission', array( $this, 'form_submit' ), 10, 2 );

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
//		error_log('GF SAVED FORMS: Save button detected via POST');
		add_filter( 'gform_validation', array( $this, 'bypass_validation_on_save' ) );
	}
}


	/**
	 * Simplified: Just store the resume token directly with visible debug
	 *
	 * @param array $submission
	 * @param array $resume_token
	 * @param array $form
	 * @param array $entry
	 *
	 * @return update_user_meta
	 */

	 public function form_save( $submission, $resume_token, $form, $entry ) {

	 	if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] ) {
	 		return;
	 	}

	 	$user = wp_get_current_user();
	 	if ( !$user ) {
	 		return;
	 	}

	 	// Simply store the resume token directly
	 	update_user_meta( $user->ID, 'has_pending_form_' . $form['id'], $resume_token );

	 	// Verify it was saved
	 	$verify = get_user_meta( $user->ID, 'has_pending_form_' . $form['id'], true );

	 }

/**
 * undocumented function summary
 *
 * Undocumented function long description
 *
 * @param array $entry
 * @param array $form
 *
 * @return update_user_meta delete_user_meta
 */

	public function form_submit( $entry, $form ) {
		if ( !isset( $form['enableFormState'] ) || !$form['enableFormState'] )
			return;

		$user = wp_get_current_user();
		if ( !$user )
			return;

		update_user_meta( $user->ID, 'completed_form_' . $form['id'], true );

		if ( !isset( $form['enableFormStateOnSubmit'] ) || !$form['enableFormStateOnSubmit'] ) {
			delete_user_meta( $user->ID, 'has_pending_form_' . $form['id'] );
			delete_user_meta( $user->ID, '_gf_draft_uuid_' . $form['id'] );
		}
	}


	 /**
	  * Get the correct UUID for a user's saved form using user ID
	  *
		* First, check if we have a stored UUID in the backup meta
		*
	  * If backup doesn't exist or is invalid, search all drafts for this form
		* and look for the user ID in the submission data
		*
		* Check if this draft contains the user ID, Look in submitted_values for the user ID
		*
		* If no match found, return the most recent draft for this form (fallback for existing data)
	  *
	  * @param array $user
		* @param var $form_id
		*
	  * @return var $uuid
	  */

	private function get_user_draft_uuid_by_user_id( $user, $form_id ) {

		global $wpdb;
		$table_name = $wpdb->prefix . 'gf_draft_submissions';

		// First, check if we have a stored UUID in the backup meta
		$backup_uuid = get_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, true );
		if ( $backup_uuid ) {
			// Verify this UUID still exists in the draft table
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT uuid FROM {$table_name} WHERE uuid = %s",
				$backup_uuid
			) );
			if ( $exists ) {
				return $backup_uuid;
			}
		}

		// If backup doesn't exist or is invalid, search all drafts for this form
		// and look for the user ID in the submission data
		$drafts = $wpdb->get_results( $wpdb->prepare(
			"SELECT uuid, submission FROM {$table_name} WHERE form_id = %d ORDER BY date_created DESC",
			$form_id
		) );

		foreach ( $drafts as $draft ) {
			$submission = json_decode( $draft->submission, true );

			// Check if this draft contains the user ID
			// Look in submitted_values for the user ID
			if ( isset( $submission['submitted_values'] ) && is_array( $submission['submitted_values'] ) ) {
				foreach ( $submission['submitted_values'] as $field_id => $value ) {
					// Check for exact user ID match
					if ( $value == $user->ID ) {
						return $draft->uuid;
					}
					// Also check if the value is a string representation of the user ID
					if ( (string) $value === (string) $user->ID ) {
						return $draft->uuid;
					}
				}
			}

			// Also check the lead (entry) data if available
			if ( isset( $submission['lead'] ) && isset( $submission['lead']['created_by'] ) ) {
				if ( $submission['lead']['created_by'] == $user->ID ) {
					return $draft->uuid;
				}
			}
		}

		// If no match found, return the most recent draft for this form (fallback for existing data)
		if ( !empty( $drafts ) ) {
			return $drafts[0]->uuid;
		}

		return null;
	}

/**
 * Set up resume token
 *
 * Get the stored resume token from user meta
 * Inject the resume token into the form url on load form
 *
 * @param void
 * @return wp_redirect
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
		if ( !$user ) {
			return;
		}

		global $post;
		if ( !$post ) {
			return;
		}

		if ( preg_match( '/\[gravityform.*id=["\']?([0-9]+)["\']?.*\]/i', $post->post_content, $matches ) ) {
			$form_id = intval( $matches[1] );

			$form = GFAPI::get_form( $form_id );

			if ( !is_wp_error( $form ) &&
				 isset( $form['enableFormState'] ) && $form['enableFormState'] &&
				 isset( $form['requireLogin'] ) && $form['requireLogin'] ) {

				$stored_token = get_user_meta( $user->ID, 'has_pending_form_' . $form_id, true );

				if ( $stored_token ) {
					$current_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ) . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
					$separator = ( strpos( $current_url, '?' ) !== false ) ? '&' : '?';
					$redirect_url = $current_url . $separator . 'gf_token=' . urlencode( $stored_token );

					wp_redirect( $redirect_url );
					exit;
				}
			}
		}
	}


	/**
	 * Provide resume token via filter
	 *
	 * Undocumented function long description
	 *
	 * @param var $token
	 *
	 * @return var $token
	 */

	public function provide_resume_token( $token ) {

		if ( !empty( $token ) ) {
			return $token;
		}

		$user = wp_get_current_user();
		if ( !$user ) {
			return $token;
		}

		global $post;
		if ( !$post ) {
			return $token;
		}

		if ( preg_match( '/\[gravityform.*id=["\']?([0-9]+)["\']?.*\]/i', $post->post_content, $matches ) ) {
			$form_id = intval( $matches[1] );

			// Check backup meta first
			$stored_token = get_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, true );
			if ( !$stored_token ) {
				$stored_token = get_user_meta( $user->ID, 'has_pending_form_' . $form_id, true );
			}

			if ( $stored_token ) {
				// Verify it exists in draft table
				global $wpdb;
				$table_name = $wpdb->prefix . 'gf_draft_submissions';
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT uuid FROM {$table_name} WHERE uuid = %s",
					$stored_token
				) );

				if ( $exists ) {
					return $stored_token;
				} else {
					// Find correct token using user ID
					$correct_token = $this->get_user_draft_uuid_by_user_id( $user, $form_id );
					if ( $correct_token ) {
						update_user_meta( $user->ID, 'has_pending_form_' . $form_id, $correct_token );
						update_user_meta( $user->ID, '_gf_draft_uuid_' . $form_id, $correct_token );
						return $correct_token;
					}
				}
			}
		}

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
 * Pending/Cpmpleted entries page
 *
 * Get all users with pending or completed entries.
 *
 * @param void
 * @return html
 */

	public function pending_completed_entries_screen() {

		$forms = RGFormsModel::get_forms( null, 'title' );
		$id = RGForms::get( 'id' );

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

		$form = RGFormsModel::get_form_meta( $form_id );
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
