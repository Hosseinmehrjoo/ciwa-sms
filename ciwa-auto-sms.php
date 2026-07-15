<?php
/**
 * Plugin Name:       افزونه اتومیشن سیوا
 * Plugin URI:        https://hosseinmehrjoo.ir
 * Description:        افزونه اتومیشن و ارسال پیامک سیوا - مدیریت تنظیمات، پیامک‌ها، گزارشات، کمپین‌ها و لاگ‌ها
 * Version:           1.0.0
 * Author:            حسین مهرجو
 * Author URI:        https://hosseinmehrjoo.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ciwa-auto-sms
 * Domain Path:       /languages
 *
 * English Name:      CIWA Auto SMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CIWA_AUTO_SMS_VERSION', '1.0.0' );
define( 'CIWA_AUTO_SMS_FILE', __FILE__ );
define( 'CIWA_AUTO_SMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CIWA_AUTO_SMS_URL', plugin_dir_url( __FILE__ ) );

require_once CIWA_AUTO_SMS_PATH . 'includes/class-config.php';
require_once CIWA_AUTO_SMS_PATH . 'includes/class-gateways.php';
require_once CIWA_AUTO_SMS_PATH . 'includes/class-automation.php';
require_once CIWA_AUTO_SMS_PATH . 'includes/class-campaigns.php';
require_once CIWA_AUTO_SMS_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, 'ciwa_auto_sms_activate' );

function ciwa_auto_sms_activate() {
	delete_option( 'ciwa_auto_sms_welcome_dismissed' );
	CIWA_Auto_SMS_Automation::schedule_cron();
}

register_deactivation_hook( __FILE__, 'ciwa_auto_sms_deactivate' );

function ciwa_auto_sms_deactivate() {
	CIWA_Auto_SMS_Automation::clear_cron();
}

function ciwa_auto_sms_init() {
	CIWA_Auto_SMS_Admin::get_instance();
	CIWA_Auto_SMS_Automation::init();
}
add_action( 'plugins_loaded', 'ciwa_auto_sms_init' );

function ciwa_auto_sms_load_textdomain() {
	load_plugin_textdomain( 'ciwa-auto-sms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'ciwa_auto_sms_load_textdomain' );

function ciwa_auto_sms_dashboard_widget() {
	$logs       = get_option( 'ciwa_auto_sms_log', array() );
	$campaigns  = CIWA_Auto_SMS_Campaigns::get_campaigns();
	$settings   = get_option( 'ciwa_auto_sms_settings', array() );

	$sent_count   = 0;
	$failed_count = 0;
	foreach ( $logs as $l ) {
		if ( 'success' === $l['status'] ) {
			$sent_count++;
		} else {
			$failed_count++;
		}
	}
	$total_all   = $sent_count + $failed_count;
	$rate        = $total_all > 0 ? round( ( $sent_count / $total_all ) * 100 ) : 0;
	$pending     = 0;
	$queue = get_option( 'ciwa_auto_sms_queue', array() );
	if ( is_array( $queue ) ) {
		$pending = count( $queue );
	}
	?>
	<div class="ciwa-dashboard-widget">
		<div class="ciwa-kpis">
			<div class="ciwa-kpi ciwa-kpi--sent">
				<div class="ciwa-kpi__value"><?php echo esc_html( $sent_count ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'موفق', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--failed">
				<div class="ciwa-kpi__value"><?php echo esc_html( $failed_count ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'ناموفق', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--rate">
				<div class="ciwa-kpi__value"><?php echo esc_html( $rate ); ?>%</div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'نرخ موفقیت', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--pending">
				<div class="ciwa-kpi__value"><?php echo esc_html( $pending ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'در صف', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--campaign">
				<div class="ciwa-kpi__value"><?php echo esc_html( count( $campaigns ) ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'کمپین', 'ciwa-auto-sms' ); ?></div>
			</div>
		</div>
		<?php if ( ! empty( $settings['gateway'] ) ) : ?>
			<div class="ciwa-gateway-status">
				<strong><?php esc_html_e( 'درگاه:', 'ciwa-auto-sms' ); ?></strong>
				<?php echo esc_html( $settings['gateway'] ); ?>
			</div>
		<?php endif; ?>
		<div class="ciwa-dashboard-actions">
			<a class="ciwa-btn ciwa-btn--primary ciwa-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=ciwa-auto-sms&tab=reports' ) ); ?>"><?php esc_html_e( 'مشاهده گزارش کامل', 'ciwa-auto-sms' ); ?></a>
		</div>
	</div>
	<?php
}

function ciwa_auto_sms_add_dashboard_widget() {
	wp_add_dashboard_widget(
		'ciwa_auto_sms_reports',
		__( 'گزارش‌های پیامکی', 'ciwa-auto-sms' ),
		'ciwa_auto_sms_dashboard_widget'
	);
}
add_action( 'wp_dashboard_setup', 'ciwa_auto_sms_add_dashboard_widget' );
