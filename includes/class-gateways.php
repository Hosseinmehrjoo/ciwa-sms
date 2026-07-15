<?php
/**
 * SMS gateway integrations for CIWA Auto SMS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIWA_Auto_SMS_Gateways {

	public static function get_gateways() {
		return array(
			'ippanel'  => __( 'آی‌پی‌پنل (ippanel)', 'ciwa-auto-sms' ),
			'farazsms' => __( 'فراز اس‌ام‌اس (farazsms)', 'ciwa-auto-sms' ),
			'mediana'  => __( 'مدیانا (mediana)', 'ciwa-auto-sms' ),
		);
	}

	public static function send( $gateway, $settings, $to, $message ) {
		if ( empty( $to ) || empty( $message ) ) {
			return array( 'success' => false, 'message' => __( 'شماره یا متن پیام خالی است.', 'ciwa-auto-sms' ) );
		}

		switch ( $gateway ) {
			case 'ippanel':
				return self::send_ippanel( $settings, $to, $message );
			case 'farazsms':
				return self::send_farazsms( $settings, $to, $message );
			case 'mediana':
				return self::send_mediana( $settings, $to, $message );
			default:
				return array( 'success' => false, 'message' => __( 'درگاه نامعتبر است.', 'ciwa-auto-sms' ) );
		}
	}

	private static function send_ippanel( $settings, $to, $message ) {
		if ( empty( $settings['api_key'] ) || empty( $settings['sender'] ) ) {
			return array( 'success' => false, 'message' => __( 'کلید API و شماره خط ارسال الزامی است.', 'ciwa-auto-sms' ) );
		}

		$to_e164 = CIWA_Auto_SMS_Config::to_e164( $to );
		$sender  = trim( $settings['sender'] ); // تمیز کردن ساده

		$payload = array(
			'sending_type' => 'webservice',
			'from_number'  => $sender,
			'message'      => $message,
			'params'       => array(
				'recipients' => array( $to_e164 ),
			),
		);

		$response = wp_remote_post(
			'https://edge.ippanel.com/v1/api/send',
			array(
				'headers' => array(
					'Authorization' => trim( $settings['api_key'] ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 25,
			)
		);

		// لاگ دقیق برای دیباگ
		self::log_debug( 'ippanel', $payload, $response );

		return self::parse_ippanel_response( $response );
	}

	private static function send_farazsms( $settings, $to, $message ) {
		// فعلاً بدون تغییر (اگر کار می‌کرد خوب است)
		if ( empty( $settings['username'] ) || empty( $settings['password'] ) || empty( $settings['sender'] ) ) {
			return array( 'success' => false, 'message' => __( 'اطلاعات فراز اس‌ام‌اس کامل نیست.', 'ciwa-auto-sms' ) );
		}

		$auth = base64_encode( $settings['username'] . ':' . $settings['password'] );

		$response = wp_remote_post(
			'https://api.farazsms.com/v1/sms/send',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'from' => $settings['sender'],
						'to'   => array( $to ),
						'text' => $message,
					)
				),
				'timeout' => 20,
			)
		);

		self::log_debug( 'farazsms', array( 'to' => $to, 'message' => $message ), $response );
		return self::parse_response( $response );
	}

	private static function send_mediana( $settings, $to, $message ) {
		// فعلاً بدون تغییر
		if ( empty( $settings['username'] ) || empty( $settings['password'] ) || empty( $settings['sender'] ) ) {
			return array( 'success' => false, 'message' => __( 'اطلاعات مدیانا کامل نیست.', 'ciwa-auto-sms' ) );
		}

		if ( ! class_exists( 'SoapClient' ) ) {
			return array( 'success' => false, 'message' => __( 'ماژول SOAP فعال نیست.', 'ciwa-auto-sms' ) );
		}

		try {
			$client = new SoapClient( 'http://sms.medianasystem.com/webservice/_soapserver.php?wsdl' );
			$result = $client->SendSMS(
				$settings['username'],
				$settings['password'],
				array( $settings['sender'] ),
				array( $to ),
				array( $message ),
				'',
				array( 1 ),
				array( time() )
			);

			return array( 'success' => (bool) $result, 'message' => $result ? 'ارسال شد' : 'خطا در ارسال' );
		} catch ( Exception $e ) {
			return array( 'success' => false, 'message' => $e->getMessage() );
		}
	}

	private static function parse_ippanel_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 200 && isset( $data['meta']['status'] ) && $data['meta']['status'] === true ) {
			$msg = $data['meta']['message'] ?? 'پیام با موفقیت ارسال شد.';
			return array( 'success' => true, 'message' => $msg );
		}

		$error_msg = $data['meta']['message'] ?? $data['message'] ?? 'خطای ناشناخته از سرور ippanel';
		return array( 'success' => false, 'message' => $error_msg . ' (کد: ' . $code . ')' );
	}

	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 200 && isset( $data['status'] ) && $data['status'] === 'OK' ) {
			return array( 'success' => true, 'message' => 'پیام با موفقیت ارسال شد.' );
		}

		$msg = $data['message'] ?? $data['error'] ?? 'خطای سرور';
		return array( 'success' => false, 'message' => $msg );
	}

	private static function log_debug( $gateway, $request, $response ) {
		$log = get_option( 'ciwa_auto_sms_debug_log', array() );
		$log[] = array(
			'time'     => current_time( 'mysql' ),
			'gateway'  => $gateway,
			'request'  => $request,
			'response' => array(
				'code' => wp_remote_retrieve_response_code( $response ),
				'body' => wp_remote_retrieve_body( $response ),
			),
		);
		if ( count( $log ) > 30 ) {
			$log = array_slice( $log, -30 );
		}
		update_option( 'ciwa_auto_sms_debug_log', $log );
		error_log( print_r( $log, true ) );
	}
}