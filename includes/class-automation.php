<?php
/**
 * Automation engine: schedules and sends SMS based on store events.
 *
 * @package CIWA_Auto_SMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIWA_Auto_SMS_Automation {

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 10, 1 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order' ), 10, 2 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order' ), 10, 2 );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order' ), 10, 2 );
			add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_order' ), 10, 2 );
			add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 10, 1 );
		}

		add_action( 'ciwa_auto_sms_send_due', array( __CLASS__, 'send_due' ) );
		add_action( 'ciwa_auto_sms_abandoned_check', array( __CLASS__, 'abandoned_check' ), 10, 1 );

		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
	}

	public static function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules['ciwa_every_minute'] ) ) {
			$schedules['ciwa_every_minute'] = array(
				'interval' => 60,
				'display'  => __( 'هر یک دقیقه (سیوا)', 'ciwa-auto-sms' ),
			);
		}
		return $schedules;
	}

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( 'ciwa_auto_sms_send_due' ) ) {
			wp_schedule_event( time(), 'ciwa_every_minute', 'ciwa_auto_sms_send_due' );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( 'ciwa_auto_sms_send_due' );
		wp_clear_scheduled_hook( 'ciwa_auto_sms_abandoned_check' );
	}

	public static function on_user_register( $user_id ) {
		self::trigger( 'register', $user_id );
	}

	public static function on_new_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::trigger( 'new_order', $order->get_user_id(), $order->get_billing_phone(), $order_id );
	}

	public static function on_order( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$status = $order->get_status();
		$event  = $status; // processing | completed | cancelled | failed
		self::trigger( $event, $order->get_user_id(), $order->get_billing_phone(), $order_id );
	}

	public static function on_add_to_cart( $cart_item_key ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'ciwa_auto_sms_abandoned_check', array( $user_id ) );
	}

	public static function abandoned_check( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'processing', 'completed' ),
				'limit'       => 1,
				'after'       => date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
			)
		);
		if ( empty( $orders ) ) {
			self::trigger( 'abandoned', $user_id );
		}
	}

	public static function trigger( $event, $user_id = 0, $phone = '', $order_id = 0 ) {
		$rules  = get_option( 'ciwa_auto_sms_rules', array() );
		$phone  = CIWA_Auto_SMS_Config::sanitize_phone( $phone );
		if ( empty( $phone ) ) {
			$phone = CIWA_Auto_SMS_Config::get_phone( $user_id );
		}
		if ( empty( $phone ) ) {
			return;
		}

		$now = time();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['event'] ) || empty( $rule['message'] ) ) {
				continue;
			}
			if ( $rule['event'] !== $event ) {
				continue;
			}
			$delay  = CIWA_Auto_SMS_Config::compute_delay_seconds( $rule['time'], isset( $rule['delay'] ) ? $rule['delay'] : 0 );
			$send_at = $now + $delay;
			self::enqueue(
				$phone,
				$rule['message'],
				$send_at,
				'',
				array( 'user_id' => (int) $user_id, 'order_id' => (int) $order_id )
			);
		}
	}

	public static function enqueue( $to, $message, $send_at, $campaign_id = '', $context = array() ) {
		$queue   = get_option( 'ciwa_auto_sms_queue', array() );
		$context = wp_parse_args(
			$context,
			array( 'user_id' => 0, 'order_id' => 0 )
		);
		$queue[] = array(
			'to'          => $to,
			'message'     => $message,
			'send_at'     => (int) $send_at,
			'campaign_id' => $campaign_id,
			'user_id'     => (int) $context['user_id'],
			'order_id'    => (int) $context['order_id'],
			'added'       => time(),
		);
		update_option( 'ciwa_auto_sms_queue', $queue );
	}

	public static function send_due() {
		$queue = get_option( 'ciwa_auto_sms_queue', array() );
		if ( empty( $queue ) ) {
			return;
		}

		$settings = get_option( 'ciwa_auto_sms_settings', array() );
		if ( empty( $settings['gateway'] ) ) {
			return;
		}

		$now      = time();
		$remaining = array();
		foreach ( $queue as $item ) {
			if ( (int) $item['send_at'] > $now ) {
				$remaining[] = $item;
				continue;
			}
			$message = self::replace_vars( $item['message'], $item );
			$result  = CIWA_Auto_SMS_Gateways::send( $settings['gateway'], $settings, $item['to'], $message );
			self::log( $item['to'], $message, $result['success'], $result['message'] );
			if ( ! empty( $item['campaign_id'] ) ) {
				CIWA_Auto_SMS_Campaigns::record_result( $item['campaign_id'], $result['success'] );
			}
		}
		update_option( 'ciwa_auto_sms_queue', $remaining );
	}

	public static function get_variables() {
		return array(
			'{first_name}'  => __( 'نام مشتری', 'ciwa-auto-sms' ),
			'{last_name}'   => __( 'نام خانوادگی مشتری', 'ciwa-auto-sms' ),
			'{full_name}'   => __( 'نام و نام خانوادگی مشتری', 'ciwa-auto-sms' ),
			'{phone}'       => __( 'شماره موبایل مشتری', 'ciwa-auto-sms' ),
			'{email}'       => __( 'ایمیل مشتری', 'ciwa-auto-sms' ),
			'{order_id}'    => __( 'شماره سفارش', 'ciwa-auto-sms' ),
			'{order_total}' => __( 'مبلغ سفارش', 'ciwa-auto-sms' ),
			'{site_name}'   => __( 'نام فروشگاه', 'ciwa-auto-sms' ),
			'{site_url}'    => __( 'آدرس سایت', 'ciwa-auto-sms' ),
		);
	}

	public static function replace_vars( $message, $context = array() ) {
		$context = wp_parse_args(
			$context,
			array( 'user_id' => 0, 'order_id' => 0, 'phone' => '' )
		);

		$user_id  = (int) $context['user_id'];
		$order_id = (int) $context['order_id'];
		$user     = $user_id ? get_userdata( $user_id ) : null;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		$replace = array(
			'{first_name}'  => $user ? $user->first_name : '',
			'{last_name}'   => $user ? $user->last_name : '',
			'{full_name}'   => $user ? trim( $user->first_name . ' ' . $user->last_name ) : ( $user ? $user->display_name : '' ),
			'{phone}'       => ! empty( $context['phone'] ) ? $context['phone'] : ( $user ? CIWA_Auto_SMS_Config::get_phone( $user_id ) : '' ),
			'{email}'       => $user ? $user->user_email : '',
			'{order_id}'    => $order_id ? (string) $order_id : '',
			'{order_total}' => $order ? $order->get_formatted_order_total() : '',
			'{site_name}'   => get_bloginfo( 'name' ),
			'{site_url}'    => home_url(),
		);

		return str_replace( array_keys( $replace ), array_values( $replace ), $message );
	}

	public static function log( $to, $message, $success, $note ) {
		$log   = get_option( 'ciwa_auto_sms_log', array() );
		array_unshift(
			$log,
			array(
				'id'      => uniqid( 'log_' ),
				'time'    => current_time( 'mysql' ),
				'to'      => $to,
				'message' => $message,
				'status'  => $success ? 'success' : 'failed',
				'note'    => $note,
			)
		);
		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, 0, 200 );
		}
		update_option( 'ciwa_auto_sms_log', $log );
	}
}
