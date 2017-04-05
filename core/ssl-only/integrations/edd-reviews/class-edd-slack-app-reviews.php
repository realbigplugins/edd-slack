<?php
/**
 * EDD Reviews Integration for the Slack App
 *
 * @since 1.0.0
 *
 * @package EDD_Slack
 * @subpackage EDD_Slack/core/ssl-only/integrations/edd-reviews
 */

defined( 'ABSPATH' ) || die();

class EDD_Slack_App_Reviews {
	
	/**
	 * EDD_Slack_App_Reviews constructor.
	 *
	 * @since 1.0.0
	 */
	function __construct() {
		
		// If we've got a linked Slack App
		if ( edd_get_option( 'slack_app_oauth_token' ) ) {
		
			// Set the new Notification API Endpoint
			add_filter( 'edd_slack_notification_webhook', array( $this, 'override_webhook' ), 10, 4 );

			// Add our Interaction Buttons
			add_filter( 'edd_slack_notification_args', array( $this, 'override_arguments' ), 10, 5 );
			
		}
		
		// Add our Trigger(s) to the Interactive Triggers Array
		add_filter( 'edd_slack_interactive_triggers', array( $this, 'add_support' ), 1, 1 );
		
	}
	
	/**
	 * Override Webhook URL with chat.postMessage for Slack App if appropriate
	 * 
	 * @param	  string $webhook_url	 The Webhook URL provided for the Slack Notification
	 * @param	  string $trigger		 Notification Trigger
	 * @param	  string $notification_id ID used for Notification Hooks
	 * @param	  array  $args			$args Array passed from the original Trigger of the process
	 *															  
	 * @access	  public
	 * @since	  1.0.0
	 * @return	  string Altered URL
	 */
	public function override_webhook( $webhook_url, $trigger, $notification_id, $args ) {
		
		if ( $notification_id !== 'rbm' ) return $webhook_url;
		
		// Allow Webhook URL overrides to bail Interactive Notifications
		if ( ( $webhook_url !== '' ) &&
			( $webhook_url !== edd_get_option( 'slack_webhook_default' ) ) ) return $webhook_url;
		
		// If our Trigger doesn't an applicable Trigger, bail
		if ( $trigger !== 'edd_insert_review' &&
		   $trigger !== 'edd_vendor_feedback' ) return $webhook_url;

		return 'chat.postMessage';
		
	}
	
	/**
	 * Override Notification Args for Slack App if appropriate
	 * 
	 * @param	  string $notification_args   Args for creating the Notification
	 * @param	  string $webhook_url		 The Webhook URL provided for the Slack Notification
	 * @param	  string $trigger			 Notification Trigger
	 * @param	  string $notification_id	 ID used for Notification Hooks
	 * @param	  array  $args				$args Array passed from the original Trigger of the process
	 *															  
	 * @access	  public
	 * @since	  1.0.0
	 * @return	  array  Altered Notification Args
	 */
	public function override_arguments( &$notification_args, $webhook_url, $trigger, $notification_id, $args ) {
		
		if ( $notification_id !== 'rbm' ) return $notification_args;
		
		// Allow Webhook URL overrides to bail Interactive Notifications
		if ( strpos( $webhook_url, 'hooks.slack.com' ) &&
			$webhook_url !== edd_get_option( 'slack_webhook_default' ) ) return $notification_args;
		
		// If our Trigger doesn't an applicable Trigger, bail
		if ( $trigger !== 'edd_insert_review' &&
		   $trigger !== 'edd_vendor_feedback' ) return $notification_args;
		
		$notification_args['attachments'][0]['actions'] = array(
			array(
				'name' => 'approve',
				'text' => _x( 'Approve', 'Approve Button Text', 'edd-slack' ),
				'type' => 'button',
				'style' => 'primary',
				'value' => json_encode( $args ),
			),
			array(
				'name' => 'spam',
				'text' => _x( 'Mark as Spam', 'Mark as Spam Button Text', 'edd-slack' ),
				'type' => 'button',
				'style' => 'danger',
				'value' => json_encode( $args ),
			),
		);
		
		// Remove the Approve Button if the Review is auto-approved
		// EDD Reviews handles this via Post Meta instead of as part of the Comment itself (Which is WRONG)
		if ( $auto_approval = wp_allow_comment( $args, true ) == '1' ) {
			
			array_splice( $notification_args['attachments'][0]['actions'], 0, 1 );
			
		}
		
		/**
		 * Allow the Notification Args for the Slack App Integration to be overriden
		 *
		 * @since 1.0.0
		 */
		$notification_args = apply_filters( 'edd_slack_app_' . $trigger . '_notification_args', $notification_args, $notification_id, $args );
		
		return $notification_args;
		
	}
	
	/**
	 * Add our Trigger(s) to the Interactive Triggers Array
	 * 
	 * @param	  array $interactive_triggers Array holding the Triggers that support Interactive Buttons
	 *																							  
	 * @return	  array Array with our added Triggers
	 */
	public function add_support( $interactive_triggers ) {
		
		$interactive_triggers[] = 'edd_insert_review';
		$interactive_triggers[] = 'edd_vendor_feedback';
		
		return $interactive_triggers;
		
	}
	
}

$integrate = new EDD_Slack_App_Reviews();

if ( ! function_exists( 'edd_slack_interactive_message_edd_insert_review' ) ) {
	
	/**
	 * EDD Slack Rest New Review on Download Endpoint
	 * This is pretty much identical to how Comments are handled
	 * 
	 * @param	  object $button	  name and value from the Interactive Button. value should be json_decode()'d
	 * @param	  string $response_url Webhook to send the Response Message to
	 * @param	  object $payload	  POST'd data from the Slack Client
	 *														
	 * @since	  1.0.0
	 * @return	  void
	 */
	function edd_slack_interactive_message_edd_insert_review( $button, $response_url, $payload ) {
		
		$action = $button->name;
		$value = json_decode( $button->value );
		
		// Set depending on the Action
		$message = '';
		
		if ( strtolower( $action ) == 'approve' ) {
			
			// If you do this the correct way, EDD Reviews freaks out
			$approve = update_comment_meta( $value->comment_id, 'edd_review_approved', '1' );
			
			// Create Reviewer Discount as appropriate
			edd_reviews()->create_reviewer_discount( $value->comment_id, get_comment( $value->comment_id, ARRAY_A ) );
			
			$message = sprintf( _x( "%s has Approved %s's Review on %s", 'Review Approved Response Text', 'edd-slack' ), $payload->user->name, $value->name, get_the_title( $value->comment_post_id ) );
			
		}
		else if ( strtolower( $action ) == 'spam' ) {
			
			// If you do this the correct way, EDD Reviews freaks out
			$spam = update_comment_meta( $value->comment_id, 'edd_review_approved', 'spam' );
			
			$message = sprintf( _x( "%s has marked %s's Review on %s as Spam", 'Spam Review Response Text', 'edd-slack' ), $payload->user->name, $value->name, get_the_title( $value->comment_post_id ) );
			
		}
		
		// Response URLs are Incoming Webhooks
		$response_message = EDDSLACK()->slack_api->push_incoming_webhook(
			$response_url,
			array(
				'text' => $message,
			)
		);
		
	}
	
}

if ( ! function_exists( 'edd_slack_interactive_message_edd_vendor_feedback' ) ) {
	
	/**
	 * EDD Slack Rest New Vendor Feedback Endpoint
	 * This is pretty much identical to how Comments are handled
	 * 
	 * @param	  object $button	  name and value from the Interactive Button. value should be json_decode()'d
	 * @param	  string $response_url Webhook to send the Response Message to
	 * @param	  object $payload	  POST'd data from the Slack Client
	 *														
	 * @since	  1.0.0
	 * @return	  void
	 */
	function edd_slack_interactive_message_edd_vendor_feedback( $button, $response_url, $payload ) {
		
		$action = $button->name;
		$value = json_decode( $button->value );
		
		// Set depending on the Action
		$message = '';
		
		if ( strtolower( $action ) == 'approve' ) {
			
			$approve = wp_update_comment( array(
				'comment_ID' => $value->comment_id,
				'comment_approved' => 1,
			) );
			
			update_comment_meta( $value->comment_id, 'edd_review_approved', '1' );
			
			$message = sprintf( _x( "%s has Approved %s's %s Feedback on %s", 'Vendor Feedback Approved Response Text', 'edd-slack' ), $payload->user->name, $value->name, EDD_FES()->helper->get_vendor_constant_name( false, true ), get_the_title( $value->comment_post_id ) );
			
		}
		else if ( strtolower( $action ) == 'spam' ) {
			
			$spam = wp_update_comment( array(
				'comment_ID' => $value->comment_id,
				'comment_approved' => 'spam',
			) );
			
			update_comment_meta( $value->comment_id, 'edd_review_approved', 'spam' );
			
			$message = sprintf( _x( "%s has marked %s's %s Feedback on %s as Spam", 'Spam Vendor Feedback Response Text', 'edd-slack' ), $payload->user->name, $value->name, EDD_FES()->helper->get_vendor_constant_name( false, true ), get_the_title( $value->comment_post_id ) );
			
		}
		
		// Response URLs are Incoming Webhooks
		$response_message = EDDSLACK()->slack_api->push_incoming_webhook(
			$response_url,
			array(
				'text' => $message,
			)
		);
		
	}
	
}