<?php
/**
 * Campaign logic for CIWA Auto SMS.
 *
 * @package CIWA_Auto_SMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIWA_Auto_SMS_Campaigns {

	public static function get_target_labels() {
		return array(
			'all'          => __( 'همه', 'ciwa-auto-sms' ),
			'buyers'       => __( 'خریدار', 'ciwa-auto-sms' ),
			'no_purchase'  => __( 'بدون خرید', 'ciwa-auto-sms' ),
			'vip'          => __( 'مشتریان ویژه', 'ciwa-auto-sms' ),
			'club'         => __( 'اعضای باشگاه مشتریان', 'ciwa-auto-sms' ),
		);
	}

	public static function get_campaigns() {
		return get_option( 'ciwa_auto_sms_campaigns', array() );
	}

	public static function get_campaign( $id ) {
		foreach ( self::get_campaigns() as $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				return $c;
			}
		}
		return null;
	}

	public static function save_campaign( $data ) {
		$campaigns   = self::get_campaigns();
		$campaigns[] = $data;
		update_option( 'ciwa_auto_sms_campaigns', $campaigns );
	}

	public static function delete_campaign( $id ) {
		$campaigns = self::get_campaigns();
		foreach ( $campaigns as $k => $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				unset( $campaigns[ $k ] );
			}
		}
		update_option( 'ciwa_auto_sms_campaigns', array_values( $campaigns ) );
	}

	public static function update_status( $id, $status ) {
		$campaigns = self::get_campaigns();
		foreach ( $campaigns as $k => $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				$campaigns[ $k ]['status'] = $status;
			}
		}
		update_option( 'ciwa_auto_sms_campaigns', array_values( $campaigns ) );
	}

	public static function get_targets( $target, $limit = 0 ) {
		$limit = (int) $limit;
		$ids   = array();

		if ( 'vip' === $target || 'club' === $target ) {
			$meta = 'vip' === $target ? 'ciwa_vip' : 'ciwa_club';
			$q    = new WP_User_Query(
				array(
					'meta_key' => $meta,
					'meta_value' => 'yes',
					'fields'   => 'ID',
					'number'   => $limit > 0 ? $limit : -1,
				)
			);
			$ids  = $q->get_results();
		} elseif ( 'buyers' === $target ) {
			$ids = self::get_buyer_ids( $limit );
		} elseif ( 'no_purchase' === $target ) {
			$buyers = self::get_buyer_ids( 0 );
			$all    = get_users( array( 'fields' => 'ID', 'number' => -1 ) );
			$ids    = array_values( array_diff( $all, $buyers ) );
			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}
		} else {
			$q   = new WP_User_Query(
				array(
					'fields' => 'ID',
					'number' => $limit > 0 ? $limit : -1,
				)
			);
			$ids = $q->get_results();
		}

		$results = array();
		foreach ( $ids as $uid ) {
			$phone = CIWA_Auto_SMS_Config::get_phone( $uid );
			if ( $phone ) {
				$results[] = array( 'user_id' => $uid, 'phone' => $phone );
			}
		}
		return $results;
	}

	private static function get_buyer_ids( $limit = 0 ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'  => $limit > 0 ? $limit : -1,
				'status' => array( 'processing', 'completed' ),
				'return' => 'ids',
			)
		);
		$ids    = array();
		foreach ( $orders as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order ) {
				$cid = $order->get_customer_id();
				if ( $cid ) {
					$ids[] = $cid;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function run_campaign( $id ) {
		$c = self::get_campaign( $id );
		if ( ! $c ) {
			return 0;
		}
		$targets = self::get_targets( $c['target'], $c['limit'] );
		$ts      = strtotime( $c['datetime'] );
		if ( false === $ts ) {
			$ts = time();
		}
		$count = 0;
		foreach ( $targets as $t ) {
			CIWA_Auto_SMS_Automation::enqueue( $t['phone'], $c['message'], $ts, $id, array( 'user_id' => (int) $t['user_id'] ) );
			$count++;
		}
		self::update_status( $id, 'sent' );
		self::reset_counts( $id );
		return $count;
	}

	public static function reset_counts( $id ) {
		$campaigns = self::get_campaigns();
		foreach ( $campaigns as $k => $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				$campaigns[ $k ]['sent']   = 0;
				$campaigns[ $k ]['failed'] = 0;
			}
		}
		update_option( 'ciwa_auto_sms_campaigns', array_values( $campaigns ) );
	}

	public static function record_result( $id, $success ) {
		$campaigns = self::get_campaigns();
		foreach ( $campaigns as $k => $c ) {
			if ( isset( $c['id'] ) && $c['id'] === $id ) {
				if ( $success ) {
					$campaigns[ $k ]['sent'] = ( isset( $c['sent'] ) ? (int) $c['sent'] : 0 ) + 1;
				} else {
					$campaigns[ $k ]['failed'] = ( isset( $c['failed'] ) ? (int) $c['failed'] : 0 ) + 1;
				}
			}
		}
		update_option( 'ciwa_auto_sms_campaigns', array_values( $campaigns ) );
	}
}
