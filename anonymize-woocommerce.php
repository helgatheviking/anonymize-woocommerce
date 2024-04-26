<?php
/**
 * Plugin Name: Anonymize WooCommerce
 * Plugin URI: https://github.com/helgatheviking/anonymize-woocommerce/
 * Description: A tool to anonymize WooCommerce customer and order data.
 * Author: Kathy Darling
 * Version: 1.0.0-alpha.1
 * Author URI: http://kathyisawesome.com
 * License: GPL-3.0
 * Text Domain: anonymize-woocommerce
 * 
 * WC requires at least: 7.0.0
 * WC tested up to: 8.8.0
 *
 * Requires at least: 6.0.0
 * Tested up to: 6.5.0
 *
 * Requires PHP: 7.4
 * 
 * Requires Plugins: woocommerce
 * 
 * Copyright 2024 Kathy Darling				
 * 
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */
namespace AnonymizeWooCommerce;

\add_action( 'before_woocommerce_init', __NAMESPACE__ . '\plugin_init' );
 /**
 * Plugin bootstap.
 */
function plugin_init() {

	// Quietly version test for Woo v7.0.0.
	if ( ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '7.0.0', '<' ) ) ) {
		return;
	}

	require_once __DIR__ . '/includes/AnonymizeCustomerProcessor.php';
	require_once __DIR__ . '/includes/AnonymizeOrderProcessor.php';

	\add_action( 'before_woocommerce_init', __NAMESPACE__ . '\declare_features_compatibility', 20 );
	\add_filter( 'woocommerce_debug_tools', __NAMESPACE__ . '\register_tool' );
	\add_filter( 'woocommerce_get_batch_processor', __NAMESPACE__ . '\get_batch_processor', 10, 2 );
}

 /**
 * Declare WooCommerce Features compatibility.
 */
function declare_features_compatibility() {

	if ( ! class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	// HPOS (Custom Order tables) compatibility.
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );

	// Cart/Checkout Blocks compatibility.
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', plugin_basename( __FILE__ ), true );
}


/**
 * A a tool to list of available tools for use in the system status section.
 *
 * @param array $tools All registered tools.
 * @return array
 */
function register_tool( $tools ) {

	$batch_processor = wc_get_container()->get( \Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController::class );

	$customer_processor_enqueued = $batch_processor->is_enqueued( AnonymizeCustomerProcessor::class );
	$order_processor_enqueued    = $batch_processor->is_enqueued( AnonymizeOrderProcessor::class );

	if ( $customer_processor_enqueued || $order_processor_enqueued ) {
		$tools['stop_anonmyizing_data'] = array(
			'name'     => __( 'Stop anonymizing customer and order data', 'anonymize-woocommerce' ),
			'button'   => __( 'Stop anonymizing', 'anonymize-woocommerce' ),
			'desc'     => __( 'This will stop the background process that anonmyizes the customer and order data.', 'anonymize-woocommerce' ),
				'callback' => __NAMESPACE__ . '\stop_processors',
		);
	} else {
		$tools['start_anonmyizing_data'] = array(
			'name'     => __( 'Anonymize customer and order data', 'anonymize-woocommerce' ),
			'button'   => __( 'Start anonymizing', 'anonymize-woocommerce' ),
			'desc'     => __( 'This tool will replace personally identifiable customer data. It it NOT reversable. The process will happen overtime in the background (via Action Scheduler).', 'anonymize-woocommerce' ),
				'callback' => __NAMESPACE__ . '\start_processors',
		);
	}

	return $tools;
}

/**
 * Start our processors
 */
function start_processors() {

	wc_get_logger()->debug('startprocessors', array('kathy'));

	$batch_processor = wc_get_container()->get( \Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController::class );

	$batch_processor->enqueue_processor( AnonymizeCustomerProcessor::class );
	$batch_processor->enqueue_processor( AnonymizeOrderProcessor::class );

	return __( 'Background processes for data anonymization started.', 'test-processor' );
}

/**
 * Stop our processors
 */
function stop_processors() {

	wc_get_logger()->debug('stop processors', array('kathy'));

	$batch_processor = wc_get_container()->get( \Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController::class );

	$batch_processor->remove_processor( AnonymizeCustomerProcessor::class );
	$batch_processor->remove_processor( AnonymizeOrderProcessor::class );

	return __( 'Background processes for data anonymization stopped.', 'test-processor' );
}

 /**
 * Register our batch processor.
 * 	
 * @param object|null $processor The processor instance given by the dependency injection container, or null if none was obtained.
 * @param string $processor_class_name The full class name of the processor.
 * @return BatchProcessorInterface|null The actual processor instance to use, or null if none could be retrieved.
 */
function get_batch_processor( $processor, $process_class_name ) {
	if ( 'AnonymizeWooCommerce\AnonymizeCustomerProcessor' === $process_class_name ) {
		return new AnonymizeCustomerProcessor();
	}

	return $processor;
}
