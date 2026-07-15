<?php
/**
 * Shared configuration for CIWA Auto SMS.
 *
 * @package CIWA_Auto_SMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIWA_Auto_SMS_Config {

	public static function get_events() {
		return array(
			'register'   => __( 'ثبت نام کاربر', 'ciwa-auto-sms' ),
			'new_order'  => __( 'سفارش جدید', 'ciwa-auto-sms' ),
			'processing' => __( 'در حال پردازش', 'ciwa-auto-sms' ),
			'completed'  => __( 'تکمیل سفارش', 'ciwa-auto-sms' ),
			'cancelled'  => __( 'لغو سفارش', 'ciwa-auto-sms' ),
			'failed'     => __( 'سفارش ناموفق', 'ciwa-auto-sms' ),
			'abandoned'  => __( 'سبد خرید رها شده', 'ciwa-auto-sms' ),
		);
	}

	public static function get_time_units() {
		return array(
			'immediately' => __( 'بلافاصله', 'ciwa-auto-sms' ),
			'minute'      => __( 'دقیقه بعد', 'ciwa-auto-sms' ),
			'hour'        => __( 'ساعت بعد', 'ciwa-auto-sms' ),
			'day'         => __( 'روز بعد', 'ciwa-auto-sms' ),
		);
	}

	public static function compute_delay_seconds( $time, $delay ) {
		$delay = max( 0, (int) $delay );
		switch ( $time ) {
			case 'minute':
				return $delay * MINUTE_IN_SECONDS;
			case 'hour':
				return $delay * HOUR_IN_SECONDS;
			case 'day':
				return $delay * DAY_IN_SECONDS;
			case 'immediately':
			default:
				return 0;
		}
	}

	public static function get_phone( $user_id ) {
		$phone = '';
		if ( $user_id ) {
			$phone = get_user_meta( $user_id, 'billing_phone', true );
			if ( empty( $phone ) ) {
				$phone = get_user_meta( $user_id, 'phone_number', true );
			}
		}
		return self::sanitize_phone( $phone );
	}

	public static function sanitize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) $phone );
		if ( strlen( $phone ) === 12 && substr( $phone, 0, 2 ) === '98' ) {
			$phone = '0' . substr( $phone, 2 );
		}
		return $phone;
	}

	public static function to_e164( $phone ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) $phone );
		if ( substr( $phone, 0, 2 ) === '98' && strlen( $phone ) >= 11 ) {
			// Already includes country code.
		} elseif ( substr( $phone, 0, 1 ) === '0' ) {
			$phone = '98' . substr( $phone, 1 );
		} else {
			$phone = '98' . $phone;
		}
		return '+' . $phone;
	}
}
