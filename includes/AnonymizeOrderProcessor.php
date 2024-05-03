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
class AnonymizeOrderProcessor implements BatchProcessorInterface, RegisterHooksInterface {

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
		return count( 
			wc_get_orders(
				array(
					'limit'        => -1,
					'anonymized'   => false,
					'type'         => wc_get_order_types(),
					'return'       => 'ids',
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

		return wc_get_orders(
			array(
				'limit'        => $size,
				'anonymized'   => false,
				'type'         => wc_get_order_types(),
			)
		);

	}

	/**
	 * Process data for the supplied batch.
	 *
	 * @throw \Exception Something went wrong while processing the batch.
	 *
	 * @param WC_Order[] $batch Batch of orders to process, as returned by 'get_next_batch_to_process'.
	 */
	public function process_batch( array $batch ): void {

		$ids = [];

		if ( empty( $batch ) ) {
			return;
		}

		foreach ( $batch as $order ) {
			$ids[] = $order->get_id();
			try {
				\WC_Privacy_Erasers::remove_order_personal_data( $order );
			} catch ( Exception $ex ) {
				\wc_get_logger()->error( StringUtil::class_name_without_namespace( self::class ) . ": when anonymizing user with id {$user->ID}: {$ex->getMessage()}" );
			}
			
		}

		// Logging the anonymized order IDs (WooCommerce > Status > Logs > Anonymize WooCommerce).
		wc_get_logger()->info( sprintf( 'Orders anonymized: %s', implode(', ', $ids) ), array( 'source' => 'anonymize-woocommerce' ) );


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
	 * Start the background process for order data conversion.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	private function enqueue(): string {
		$batch_processor = wc_get_container()->get( BatchProcessingController::class );
		if ( $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for order anonymization already started, nothing done.', 'anonymize-woocommerce' );
		}

		$batch_processor->enqueue_processor( self::class );
		return __( 'Background process for order anonymization started', 'anonymize-woocommerce' );
	}

	/**
	 * Stop the background process for order data conversion.
	 *
	 * @return string Informative string to show after the tool is triggered in UI.
	 */
	private function dequeue(): string {
		$batch_processor = wc_get_container()->get( BatchProcessingController::class );
		if ( ! $batch_processor->is_enqueued( self::class ) ) {
			return __( 'Background process for order anonymization not started, nothing done.', 'anonymize-woocommerce' );
		}

		$batch_processor->remove_processor( self::class );
		return __( 'Background process for order anonymization stopped', 'anonymize-woocommerce' );
	}
}
