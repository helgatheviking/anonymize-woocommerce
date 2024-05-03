<?php

namespace AnonymizeWooCommerce;

use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController;
use Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessorInterface;
use Automattic\WooCommerce\Internal\Traits\AccessiblePrivateMethods;
use Automattic\WooCommerce\Utilities\StringUtil;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

use \Exception;

/**
 * This class is intended to be used with BatchProcessingController and converts verbose
 * 'coupon_data' metadata entries in coupon line items (corresponding to coupons applied to orders)
 * into simplified 'coupon_info' entries. See WC_Coupon::get_short_info.
 *
 * Additionally, this class manages the "Convert order coupon data" tool.
 */
class AnonymizeCustomerProcessor implements BatchProcessorInterface, RegisterHooksInterface {

	use AccessiblePrivateMethods;

	/**
	 * Register hooks for the class.
	 */
	public function register() {
		self::mark_method_as_accessible( 'enqueue' );
		self::mark_method_as_accessible( 'dequeue' );
	}

	/**
	 * Get a user-friendly name for this processor.
	 *
	 * @return string Name of the processor.
	 */
	public function get_name(): string {
		return "Customer data anonymizer";
	}

	/**
	 * Get a user-friendly description for this processor.
	 *
	 * @return string Description of what this processor does.
	 */
	public function get_description(): string {
		return "Erase personally identifiable data for customers [Excluding admininistrators].";
	}

	/**
	 * Get the total number of pending items that require processing.
	 *
	 * @return int Number of items pending processing.
	 */
	public function get_total_pending_count(): int {
		return \count(
			\get_users(
				array(
					'role__not_in' => array( 'administrator', 'shop_manager' ),
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => '_anonymized',
							'compare' => 'NOT EXISTS', // Check if the meta key does not exist
						),
						array(
							'key'     => '_anonymized',
							'value'   => 'yes',
							'compare' => '!=', // Check if the meta value is not 'yes'
						),
					),
				)
			)
		);
	}

	/**
	 * Returns the next batch of items that need to be processed.
	 * A batch in this context is a list of 'meta_id' values from the wp_woocommerce_order_itemmeta table.
	 *
	 * @param int $size Maximum size of the batch to be returned.
	 *
	 * @return array Batch of items to process, containing $size or less items.
	 */
	public function get_next_batch_to_process( int $size ): array {
		global $wpdb;


		return \get_users(
			array(
				'number' => $size,
				'role__not_in' => array( 'administrator', 'shop_manager' ),
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => '_anonymized',
						'compare' => 'NOT EXISTS', // Check if the meta key does not exist
					),
					array(
						'key'     => '_anonymized',
						'value'   => 'yes',
						'compare' => '!=', // Check if the meta value is not 'yes'
					),
				),
			)
		);
		  
	}

	/**
	 * Process data for the supplied batch. See the convert_item method.
	 *
	 * @throw \Exception Something went wrong while processing the batch.
	 *
	 * @param WP_User[] $batch Batch to process, as returned by 'get_next_batch_to_process'.
	 */
	public function process_batch( array $batch ): void {

		$ids = [];
		
		// IMPORTANT! Don't send email and password change notifications.
		\add_filter( 'send_email_change_email', '__return_false' );
		\add_filter( 'send_password_change_email', '__return_false' );

		if ( empty( $batch ) ) {
			return;
		}

		foreach ( $batch as $user ) {
			try {
				$ids[] = $user->ID;
				$this->process_item( $user );
			} catch ( Exception $ex ) {
				\wc_get_logger()->error( StringUtil::class_name_without_namespace( self::class ) . ": error when anonymizing user with id {$user->ID}: {$ex->getMessage()}", array( 'source' => 'anonymize-woocommerce' ) );
			}
		}

		// Logging the anonymized user IDs (WooCommerce > Status > Logs > Anonymize WooCommerce).
		\wc_get_logger()->info( sprintf( 'Users anonymized: %s', implode(', ', $ids) ), array( 'source' => 'anonymize-woocommerce' ) );
	}

	/**
	 * Anonymize all data for single user.
	 *
	 * @param \WP_User    $user The user to be anonymized.
	 * @throws \Exception Database error.
	 */
	private function process_item( \WP_User $user ) {

		global $wpdb;

		// Use Woo built-in tools to erase customer data.
		\WC_Privacy_Erasers::customer_data_erase( $user->email );
		\WC_Privacy_Erasers::download_data_eraser( $user->email );
		\WC_Privacy_Erasers::customer_tokens_eraser( $user->email );

		// Build the new user data.
		$userdata = array(
			'ID'            => $user->ID,
			'user_login'    => wp_privacy_anonymize_data( 'email', $user->email ),
			'user_email'    => wp_privacy_anonymize_data( 'email', $user->email ),
			'first_name'    => wp_privacy_anonymize_data( 'text', $user->first_name ),
			'last_name'     => wp_privacy_anonymize_data( 'text', $user->last_name ),
			'display_name'  => wp_privacy_anonymize_data( 'text', $user->display_name ),
			'nickname'      => wp_privacy_anonymize_data( 'text', $user->nickname ),
			'user_nicename' => wp_privacy_anonymize_data( 'text', $user->nickname ),
			'user_pass'     => wp_generate_password(),
		);

		$result = wp_update_user( $userdata );
	   
		// Cannot update user_login via wp_update_user, so we do it manually.
		if ( $result ) {

			$success = $wpdb->update(
				$wpdb->users,
				['user_login' => $userdata['user_email']],
				['ID' => $result],
				['%s'],
				['%d']
			);

			if ( ! $success ) {
				throw new Exception( 'Error updating user_login for user #%d' );
			}

		} else {
			throw new Exception( 'Error updating user data for user #%d' );
		}
	   
	}

	/**
	 * Default (preferred) batch size to pass to 'get_next_batch_to_process'.
	 *
	 * @return int Default batch size.
	 */
	public function get_default_batch_size(): int {
		return 20;
	}

	/**
	 * Start the background process for customer data conversion.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	private function enqueue(): string {
		$batch_processor = wc_get_container()->get( BatchProcessingController::class );
		if ( $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for customer anonymization already started, nothing done.', 'anonymize-woocommerce' );
		}

		$batch_processor->enqueue_processor( self::class );
		return __( 'Background process for customer anonymization started', 'anonymize-woocommerce' );
	}

	/**
	 * Stop the background process for customer data conversion.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	private function dequeue(): string {
		$batch_processor = wc_get_container()->get( BatchProcessingController::class );
		if ( ! $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for customer anonymization not started, nothing done.', 'anonymize-woocommerce' );
		}

		$batch_processor->remove_processor( self::class );
		return __( 'Background process for customer anonymization stopped', 'anonymize-woocommerce' );
	}
}
