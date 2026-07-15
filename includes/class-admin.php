<?php
/**
 * Admin area for CIWA Auto SMS.
 *
 * @package CIWA_Auto_SMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CIWA_Auto_SMS_Admin {

	private $tabs = array();

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->tabs = array(
			'settings'  => __( 'تنظیمات', 'ciwa-auto-sms' ),
			'help'      => __( 'راهنما', 'ciwa-auto-sms' ),
			'sms'       => __( 'قوانین ارسال', 'ciwa-auto-sms' ),
			'reports'   => __( 'گزارشات', 'ciwa-auto-sms' ),
			'campaigns' => __( 'کمپین‌ها', 'ciwa-auto-sms' ),
			'logs'      => __( 'لاگ‌ها', 'ciwa-auto-sms' ),
		);

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'process_actions' ) );
		add_action( 'wp_ajax_ciwa_auto_sms_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_ciwa_dismiss_welcome', array( $this, 'ajax_dismiss_welcome' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'اتومیشن سیوا', 'ciwa-auto-sms' ),
			__( 'اتومیشن سیوا', 'ciwa-auto-sms' ),
			'manage_options',
			'ciwa-auto-sms',
			array( $this, 'render_page' ),
			'dashicons-email-alt',
			30
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_ciwa-auto-sms' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ciwa-google-font',
			'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700;800&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'ciwa-auto-sms-admin',
			CIWA_AUTO_SMS_URL . 'assets/css/admin.css',
			array(),
			CIWA_AUTO_SMS_VERSION
		);

		wp_enqueue_script(
			'ciwa-auto-sms-admin',
			CIWA_AUTO_SMS_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			CIWA_AUTO_SMS_VERSION,
			true
		);

		wp_localize_script(
			'ciwa-auto-sms-admin',
			'ciwaAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ciwa_auto_sms_nonce' ),
			)
		);
	}

	private function get_current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! array_key_exists( $tab, $this->tabs ) ) {
			$tab = 'settings';
		}
		return $tab;
	}

	public function process_actions() {
		if ( empty( $_GET['page'] ) || 'ciwa-auto-sms' !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_GET['ciwa_dismiss_welcome'] ) ) {
			update_option( 'ciwa_auto_sms_welcome_dismissed', 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms' ) );
			exit;
		}

		if ( isset( $_POST['ciwa_save_settings'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
			}
			check_admin_referer( 'ciwa_save_settings', 'ciwa_settings_nonce' );

			$settings = array(
				'gateway'      => sanitize_text_field( wp_unslash( $_POST['ciwa_gateway'] ) ),
				'api_key'      => sanitize_text_field( wp_unslash( $_POST['ciwa_api_key'] ) ),
				'username'     => sanitize_text_field( wp_unslash( $_POST['ciwa_username'] ) ),
				'password'     => sanitize_text_field( wp_unslash( $_POST['ciwa_password'] ) ),
				'sender'       => sanitize_text_field( wp_unslash( $_POST['ciwa_sender'] ) ),
				'test_number'  => sanitize_text_field( wp_unslash( $_POST['ciwa_test_number'] ) ),
			);
			update_option( 'ciwa_auto_sms_settings', $settings );

			$number = $settings['test_number'];
			if ( ! empty( $number ) ) {
		$context = array( 'user_id' => get_current_user_id(), 'phone' => $number );
		$message = CIWA_Auto_SMS_Automation::replace_vars( $this->test_message(), $context );

		$result = CIWA_Auto_SMS_Gateways::send( $settings['gateway'], $settings, $number, $message );
		CIWA_Auto_SMS_Automation::log( $number, $message, $result['success'], $result['message'] );
				if ( $result['success'] ) {
					set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'success', 'text' => $this->success_message() ), 60 );
				} else {
					set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'error', 'text' => $this->failure_message() ), 60 );
				}
			} else {
				set_transient(
					'ciwa_auto_sms_notice',
					array(
						'type' => 'info',
						'text' => __( 'تنظیمات ذخیره شد. شماره تست وارد نشده است.', 'ciwa-auto-sms' ),
					),
					60
				);
			}
		}

		if ( isset( $_POST['ciwa_save_rules'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
			}
			check_admin_referer( 'ciwa_save_rules', 'ciwa_rules_nonce' );

			$rules = array();
			if ( ! empty( $_POST['ciwa_rules'] ) && is_array( $_POST['ciwa_rules'] ) ) {
				foreach ( $_POST['ciwa_rules'] as $row ) {
					$rules[] = array(
						'event'   => sanitize_key( wp_unslash( $row['event'] ) ),
						'time'    => sanitize_key( wp_unslash( $row['time'] ) ),
						'delay'   => min( 365, max( 0, (int) $row['delay'] ) ),
						'message' => sanitize_textarea_field( wp_unslash( $row['message'] ) ),
					);
				}
			}
			while ( count( $rules ) < 20 ) {
				$rules[] = array( 'event' => '', 'time' => 'immediately', 'delay' => 1, 'message' => '' );
			}
			update_option( 'ciwa_auto_sms_rules', $rules );

			set_transient(
				'ciwa_auto_sms_notice',
				array(
					'type' => 'success',
					'text' => __( 'قوانین پیامکی با موفقیت ذخیره شد.', 'ciwa-auto-sms' ),
				),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms&tab=sms' ) );
			exit;
		}

		if ( isset( $_POST['ciwa_save_campaign'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
			}
			check_admin_referer( 'ciwa_save_campaign', 'ciwa_campaign_nonce' );

			$name     = sanitize_text_field( wp_unslash( $_POST['ciwa_campaign_name'] ) );
			$datetime = sanitize_text_field( wp_unslash( $_POST['ciwa_campaign_datetime'] ) );
			$target   = sanitize_key( wp_unslash( $_POST['ciwa_campaign_target'] ) );
			$limit    = max( 0, (int) $_POST['ciwa_campaign_limit'] );
			$message  = sanitize_textarea_field( wp_unslash( $_POST['ciwa_campaign_message'] ) );

			if ( empty( $name ) || empty( $datetime ) || empty( $message ) ) {
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'error', 'text' => __( 'نام، زمان و متن پیام کمپین الزامی است.', 'ciwa-auto-sms' ) ), 60 );
			} else {
				CIWA_Auto_SMS_Campaigns::save_campaign(
					array(
						'id'       => uniqid( 'cmp_' ),
						'name'     => $name,
						'datetime' => $datetime,
						'target'   => $target,
						'limit'    => $limit,
						'message'  => $message,
						'status'   => 'pending',
						'sent'     => 0,
						'failed'   => 0,
						'created'  => current_time( 'mysql' ),
					)
				);
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'success', 'text' => __( 'کمپین با موفقیت ذخیره شد.', 'ciwa-auto-sms' ) ), 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms&tab=campaigns' ) );
			exit;
		}

		if ( isset( $_GET['ciwa_campaign_action'], $_GET['id'] ) && in_array( $_GET['ciwa_campaign_action'], array( 'run', 'delete' ), true ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
			}
			check_admin_referer( 'ciwa_campaign_action' );
			$id     = sanitize_text_field( wp_unslash( $_GET['id'] ) );
			$action = sanitize_text_field( wp_unslash( $_GET['ciwa_campaign_action'] ) );
			if ( 'run' === $action ) {
				$count = CIWA_Auto_SMS_Campaigns::run_campaign( $id );
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'success', 'text' => sprintf( __( 'کمپین برای %d مخاطب زمان‌بندی شد.', 'ciwa-auto-sms' ), $count ) ), 60 );
			} else {
				CIWA_Auto_SMS_Campaigns::delete_campaign( $id );
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'info', 'text' => __( 'کمپین حذف شد.', 'ciwa-auto-sms' ) ), 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms&tab=campaigns' ) );
			exit;
		}

		if ( isset( $_GET['ciwa_log_action'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'ciwa_log_action' );
			$action = sanitize_key( wp_unslash( $_GET['ciwa_log_action'] ) );
			if ( 'delete_all' === $action ) {
				delete_option( 'ciwa_auto_sms_log' );
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'info', 'text' => __( 'همه لاگ‌ها حذف شدند.', 'ciwa-auto-sms' ) ), 60 );
			} elseif ( 'delete' === $action && isset( $_GET['id'] ) ) {
				$this->delete_log_entries( array( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ) );
				set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'info', 'text' => __( 'لاگ مورد نظر حذف شد.', 'ciwa-auto-sms' ) ), 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms&tab=logs' ) );
			exit;
		}

		if ( isset( $_POST['ciwa_delete_logs'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
			}
			check_admin_referer( 'ciwa_log_bulk', 'ciwa_log_bulk_nonce' );
			$ids = isset( $_POST['ciwa_log_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ciwa_log_ids'] ) ) : array();
			$count = $this->delete_log_entries( $ids );
			set_transient( 'ciwa_auto_sms_notice', array( 'type' => 'info', 'text' => sprintf( __( '%d لاگ حذف شد.', 'ciwa-auto-sms' ), $count ) ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ciwa-auto-sms&tab=logs' ) );
			exit;
		}

		if ( isset( $_GET['ciwa_export'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'ciwa_export' );
			$type = sanitize_key( wp_unslash( $_GET['ciwa_export'] ) );
			if ( 'campaigns' === $type ) {
				$target_labels = CIWA_Auto_SMS_Campaigns::get_target_labels();
				$rows = array();
				foreach ( CIWA_Auto_SMS_Campaigns::get_campaigns() as $c ) {
					$rows[] = array(
						$c['name'],
						isset( $target_labels[ $c['target'] ] ) ? $target_labels[ $c['target'] ] : $c['target'],
						'pending' === $c['status'] ? __( 'در انتظار', 'ciwa-auto-sms' ) : __( 'ارسال‌شده', 'ciwa-auto-sms' ),
						isset( $c['sent'] ) ? (int) $c['sent'] : 0,
						isset( $c['failed'] ) ? (int) $c['failed'] : 0,
						$c['datetime'],
						$c['created'],
						$c['message'],
					);
				}
				$this->export_csv(
					'ciwa-campaigns.csv',
					array( __( 'نام', 'ciwa-auto-sms' ), __( 'هدف', 'ciwa-auto-sms' ), __( 'وضعیت', 'ciwa-auto-sms' ), __( 'موفق', 'ciwa-auto-sms' ), __( 'ناموفق', 'ciwa-auto-sms' ), __( 'زمان ارسال', 'ciwa-auto-sms' ), __( 'تاریخ ایجاد', 'ciwa-auto-sms' ), __( 'متن پیام', 'ciwa-auto-sms' ) ),
					$rows
				);
			} elseif ( 'logs' === $type ) {
				$rows = array();
				foreach ( get_option( 'ciwa_auto_sms_log', array() ) as $l ) {
					$rows[] = array(
						$l['time'],
						$l['to'],
						$l['message'],
						'success' === $l['status'] ? __( 'موفق', 'ciwa-auto-sms' ) : __( 'ناموفق', 'ciwa-auto-sms' ),
						$l['note'],
					);
				}
				$this->export_csv(
					'ciwa-logs.csv',
					array( __( 'زمان', 'ciwa-auto-sms' ), __( 'گیرنده', 'ciwa-auto-sms' ), __( 'پیام', 'ciwa-auto-sms' ), __( 'وضعیت', 'ciwa-auto-sms' ), __( 'توضیح', 'ciwa-auto-sms' ) ),
					$rows
				);
			}
		}
	}

	private function export_csv( $filename, $header, $rows ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	private function delete_log_entries( $ids ) {
		$ids  = (array) $ids;
		$logs = get_option( 'ciwa_auto_sms_log', array() );
		$keep = array();
		foreach ( $logs as $l ) {
			if ( ! isset( $l['id'] ) || ! in_array( $l['id'], $ids, true ) ) {
				$keep[] = $l;
			}
		}
		$removed = count( $logs ) - count( $keep );
		update_option( 'ciwa_auto_sms_log', $keep );
		return $removed;
	}

	public function ajax_test() {
		check_ajax_referer( 'ciwa_auto_sms_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'دسترسی غیرمجاز', 'ciwa-auto-sms' ) );
		}

		$settings = get_option( 'ciwa_auto_sms_settings', array() );
		if ( empty( $settings ) ) {
			wp_send_json_error( __( 'ابتدا تنظیمات را ذخیره کنید.', 'ciwa-auto-sms' ) );
		}

		$number = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '';
		if ( empty( $number ) ) {
			wp_send_json_error( __( 'شماره تست را وارد کنید.', 'ciwa-auto-sms' ) );
		}

		$message = $this->test_message();
		$result  = CIWA_Auto_SMS_Gateways::send( $settings['gateway'], $settings, $number, $message );
		CIWA_Auto_SMS_Automation::log( $number, $message, $result['success'], $result['message'] );
		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		}
		wp_send_json_error( $result['message'] );
	}

	public function ajax_dismiss_welcome() {
		check_ajax_referer( 'ciwa_auto_sms_nonce', 'nonce' );
		update_option( 'ciwa_auto_sms_welcome_dismissed', 1 );
		wp_die();
	}

	public function render_page() {
		$current   = $this->get_current_tab();
		$show_welcome = ! (bool) get_option( 'ciwa_auto_sms_welcome_dismissed' );
		?>
		<div class="wrap ciwa-wrap">
			<div class="ciwa-hero">
				<div class="ciwa-hero__icon">✉️</div>
				<div>
					<h1 class="ciwa-title">
						<?php esc_html_e( 'افزونه اتومیشن سیوا', 'ciwa-auto-sms' ); ?>
						<span class="ciwa-subtitle">CIWA Auto SMS</span>
					</h1>
					<p class="ciwa-hero__desc"><?php esc_html_e( 'ارسال خودکار پیامک‌های زمان‌بندی شده به مشتریان: پس از خرید، رها کردن سبد خرید، ثبت‌نام و...', 'ciwa-auto-sms' ); ?></p>
				</div>
			</div>

			<nav class="ciwa-tabs">
				<?php foreach ( $this->tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ciwa-auto-sms&tab=' . $key ) ); ?>"
					   class="ciwa-tab <?php echo ( $key === $current ) ? 'ciwa-tab--active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ciwa-tab-content">
				<?php
				$method = 'render_tab_' . str_replace( '-', '_', $current );
				if ( method_exists( $this, $method ) ) {
					$this->$method();
				} else {
					$this->render_tab_placeholder( $current );
				}
				?>
			</div>

			<footer class="ciwa-footer">
				<?php
				printf(
					esc_html__( 'سازنده: %1$s — وب‌سایت: %2$s', 'ciwa-auto-sms' ),
					'<strong>حسین مهرجو</strong>',
					'<a href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">hosseinmehrjoo.ir</a>'
				);
				?>
			</footer>
		</div>

		<?php if ( $show_welcome ) : ?>
			<div class="ciwa-modal" id="ciwa-welcome-modal">
				<div class="ciwa-modal__overlay" data-dismiss="1"></div>
				<div class="ciwa-modal__box">
					<div class="ciwa-modal__badge">🎉</div>
					<h2><?php esc_html_e( 'خوش‌آمدید به اتومیشن سیوا', 'ciwa-auto-sms' ); ?></h2>
					<p><?php esc_html_e( 'از اینکه اتومیشن سیوا را انتخاب نمودید سپاسگذاریم. لطفا با کلیک روی دکمه زیر تنظیمات افزونه را اعمال نمایید. در صورتی که پیشنهادی برای کارکرد هرچه بهتر افزونه دارید به آیدی توسعه دهنده در تلگرام ciwaseo پیام دهید.', 'ciwa-auto-sms' ); ?></p>
					<div class="ciwa-modal__actions">
						<a class="ciwa-btn ciwa-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ciwa-auto-sms&tab=settings&ciwa_dismiss_welcome=1' ) ); ?>">
							<?php esc_html_e( 'اعمال تنظیمات', 'ciwa-auto-sms' ); ?>
						</a>
						<button type="button" class="ciwa-btn ciwa-btn--ghost" data-dismiss="1"><?php esc_html_e( 'بعداً', 'ciwa-auto-sms' ); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	private function render_tab_placeholder( $tab ) {
		$label = isset( $this->tabs[ $tab ] ) ? $this->tabs[ $tab ] : $tab;
		printf(
			'<div class="ciwa-placeholder"><div class="ciwa-placeholder__icon">🛠️</div><p>%s</p><p class="description">%s</p></div>',
			sprintf( esc_html__( 'تب «%s» در حال ساخت است.', 'ciwa-auto-sms' ), esc_html( $label ) ),
			esc_html__( 'این تب به زودی تکمیل خواهد شد.', 'ciwa-auto-sms' )
		);
	}

	public function render_tab_settings() {
		$settings = get_option( 'ciwa_auto_sms_settings', array() );
		$gateways = CIWA_Auto_SMS_Gateways::get_gateways();
		$current_gateway = isset( $settings['gateway'] ) ? $settings['gateway'] : 'ippanel';

		$notice = get_transient( 'ciwa_auto_sms_notice' );
		if ( $notice ) {
			delete_transient( 'ciwa_auto_sms_notice' );
			$help_url = admin_url( 'admin.php?page=ciwa-auto-sms&tab=help' );
			?>
			<div class="ciwa-notice ciwa-notice--<?php echo esc_attr( $notice['type'] ); ?>">
				<div class="ciwa-notice__icon"><?php echo ( 'success' === $notice['type'] ) ? '✅' : ( 'error' === $notice['type'] ? '⚠️' : 'ℹ️' ); ?></div>
				<div class="ciwa-notice__body">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
					<?php if ( 'success' === $notice['type'] ) : ?>
						<a class="ciwa-btn ciwa-btn--primary ciwa-btn--sm" href="<?php echo esc_url( $help_url ); ?>"><?php esc_html_e( 'مشاهده راهنما', 'ciwa-auto-sms' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		?>

		<form method="post" class="ciwa-form" id="ciwa-settings-form">
			<?php wp_nonce_field( 'ciwa_save_settings', 'ciwa_settings_nonce' ); ?>

			<div class="ciwa-card">
				<h3 class="ciwa-card__title"><?php esc_html_e( 'انتخاب پنل پیامکی', 'ciwa-auto-sms' ); ?></h3>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'درگاه پیامک', 'ciwa-auto-sms' ); ?></span>
					<select name="ciwa_gateway" id="ciwa_gateway" class="ciwa-input">
						<?php foreach ( $gateways as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_gateway, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="ciwa-field ciwa-field--apikey" data-gateways="ippanel">
					<span class="ciwa-field__label"><?php esc_html_e( 'کلید API (ippanel)', 'ciwa-auto-sms' ); ?></span>
					<input type="text" name="ciwa_api_key" class="ciwa-input" value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>" placeholder="AccessKey ...">
				</label>

				<label class="ciwa-field ciwa-field--creds" data-gateways="farazsms,mediana">
					<span class="ciwa-field__label"><?php esc_html_e( 'نام کاربری', 'ciwa-auto-sms' ); ?></span>
					<input type="text" name="ciwa_username" class="ciwa-input" value="<?php echo esc_attr( isset( $settings['username'] ) ? $settings['username'] : '' ); ?>" placeholder="username">
				</label>

				<label class="ciwa-field ciwa-field--creds" data-gateways="farazsms,mediana">
					<span class="ciwa-field__label"><?php esc_html_e( 'رمز عبور', 'ciwa-auto-sms' ); ?></span>
					<input type="password" name="ciwa_password" class="ciwa-input" value="<?php echo esc_attr( isset( $settings['password'] ) ? $settings['password'] : '' ); ?>" placeholder="••••••••">
				</label>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'شماره خط ارسال', 'ciwa-auto-sms' ); ?></span>
					<input type="text" name="ciwa_sender" class="ciwa-input" value="<?php echo esc_attr( isset( $settings['sender'] ) ? $settings['sender'] : '' ); ?>" placeholder="3000xxxx">
				</label>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'شماره تست (برای دریافت پیام تست)', 'ciwa-auto-sms' ); ?></span>
					<input type="text" name="ciwa_test_number" id="ciwa_test_number" class="ciwa-input" value="<?php echo esc_attr( isset( $settings['test_number'] ) ? $settings['test_number'] : '' ); ?>" placeholder="09xxxxxxxxx">
				</label>
			</div>

			<div class="ciwa-actions">
				<button type="submit" name="ciwa_save_settings" class="ciwa-btn ciwa-btn--primary"><?php esc_html_e( 'ذخیره اطلاعات', 'ciwa-auto-sms' ); ?></button>
				<button type="button" id="ciwa-send-test" class="ciwa-btn ciwa-btn--accent"><?php esc_html_e( 'ارسال پیام تست', 'ciwa-auto-sms' ); ?></button>
			</div>

			<div id="ciwa-test-result" class="ciwa-test-result"></div>
		</form>
		<?php
	}

	public function render_tab_help() {
		$variables = CIWA_Auto_SMS_Automation::get_variables();
		?>
		<div class="ciwa-card ciwa-help">
			<h3 class="ciwa-card__title">📘 <?php esc_html_e( 'راهنمای جامع افزونه اتومیشن سیوا', 'ciwa-auto-sms' ); ?></h3>
			<p class="ciwa-card__hint">
				<?php esc_html_e( 'این افزونه برای ارسال خودکار پیامک در زمان‌بندی‌های مختلف به مشتریان شما طراحی شده است (پس از ثبت‌نام، خرید، رها کردن سبد خرید و...). در ادامه نحوه کار با هر بخش توضیح داده شده است.', 'ciwa-auto-sms' ); ?>
			</p>

			<div class="ciwa-help__section">
				<h4>۱. راه‌اندازی اولیه (تب تنظیمات)</h4>
				<ul>
					<li><?php esc_html_e( 'درگاه پیامک خود را انتخاب کنید (آی‌پی‌پنل، فراز اس‌ام‌اس یا مدیانا) و اطلاعات مربوطه را وارد نمایید.', 'ciwa-auto-sms' ); ?></li>
					<li><?php esc_html_e( 'شماره خط ارسال را وارد کنید (مثلاً 3000xxxx).', 'ciwa-auto-sms' ); ?></li>
					<li><?php esc_html_e( 'شماره تست را وارد کنید، سپس روی «ارسال پیام تست» کلیک کنید تا صحت اتصال بررسی شود.', 'ciwa-auto-sms' ); ?></li>
					<li><?php esc_html_e( 'در نهایت «ذخیره اطلاعات» را بزنید؛ پس از ارسال موفق پیام تست، افزونه آماده به کار خواهد بود.', 'ciwa-auto-sms' ); ?></li>
				</ul>
			</div>

			<div class="ciwa-help__section">
				<h4>۲. قوانین ارسال (تب قوانین ارسال)</h4>
				<p><?php esc_html_e( 'در اینجا ۲۰ ردیف قانون دارید. هر ردیف شامل ۴ انتخاب است:', 'ciwa-auto-sms' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'انتخاب رویداد:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'ثبت نام کاربر، سفارش جدید، در حال پردازش، تکمیل سفارش، لغو سفارش، سفارش ناموفق، سبد خرید رها شده.', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'زمان ارسال:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'بلافاصله، دقیقه بعد، ساعت بعد، روز بعد.', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'چقدر بعد؟:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'عددی بین ۰ تا ۳۶۵ که به زمان بالا اضافه می‌شود (در حالت بلافاصله غیرفعال است).', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'متن پیام:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'متنی که برای مشتری ارسال می‌شود (می‌توانید از متغیرها استفاده کنید).', 'ciwa-auto-sms' ); ?></li>
				</ul>
				<div class="ciwa-example">
					<strong><?php esc_html_e( 'مثال:', 'ciwa-auto-sms' ); ?></strong>
					<?php esc_html_e( 'ردیف اول: رویداد = ثبت نام کاربر، زمان = ساعت بعد، عدد = ۱، متن = «خوش آمدید {first_name} عزیز» → هر کسی ثبت‌نام کند، ۱ ساعت بعد این پیام را دریافت می‌کند.', 'ciwa-auto-sms' ); ?><br>
					<?php esc_html_e( 'ردیف دوم: رویداد = ثبت نام کاربر، زمان = روز بعد، عدد = ۵، متن = «شما بهترین مشتری ما هستید» → همان کاربر ۵ روز بعد این پیام را دریافت می‌کند.', 'ciwa-auto-sms' ); ?>
				</div>
			</div>

			<div class="ciwa-help__section">
				<h4>۳. کمپین‌ها (تب کمپین‌ها)</h4>
				<ul>
					<li><strong><?php esc_html_e( 'نام کمپین:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'یک عنوان برای شناسایی کمپین (مثلاً جشنواره تابستانه).', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'زمان ارسال:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'تاریخ شمسی و ساعت دلخواه برای ارسال زمان‌بندی‌شده.', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'کاربران هدف:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'همه / خریدار / بدون خرید / مشتریان ویژه / اعضای باشگاه مشتریان.', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'تعداد ارسال:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'محدودیت تعداد مخاطبان (مثلاً ۱۰۰ = فقط ۱۰۰ نفر اول لیست). عدد ۰ یعنی بدون محدودیت.', 'ciwa-auto-sms' ); ?></li>
					<li><strong><?php esc_html_e( 'متن پیام:', 'ciwa-auto-sms' ); ?></strong> <?php esc_html_e( 'محتوای پیامک کمپین.', 'ciwa-auto-sms' ); ?></li>
				</ul>
				<div class="ciwa-example">
					<strong><?php esc_html_e( 'مثال:', 'ciwa-auto-sms' ); ?></strong>
					<?php esc_html_e( 'کمپین «جشنواره تابستانه» با هدف «خریدار»، تعداد ۱۰۰ و زمان فردا ساعت ۱۰:۰۰ → فردا در ساعت ۱۰ صبح، پیامک به ۱۰۰ نفر اول خریداران ارسال می‌شود.', 'ciwa-auto-sms' ); ?>
				</div>
			</div>

			<div class="ciwa-help__section">
				<h4>۴. متغیرهای پیامک</h4>
				<p><?php esc_html_e( 'در متن پیامک می‌توانید از متغیرهای زیر استفاده کنید تا به‌جای آن‌ها مقادیر واقعی مشتری قرار گیرد. متغیرها دقیقاً همان‌گونه که در جدول آمده (با براکت {}) نوشته شوند:', 'ciwa-auto-sms' ); ?></p>
				<table class="ciwa-table ciwa-vars">
					<thead>
						<tr><th><?php esc_html_e( 'متغیر', 'ciwa-auto-sms' ); ?></th><th><?php esc_html_e( 'مقدار جایگزین', 'ciwa-auto-sms' ); ?></th><th><?php esc_html_e( 'مثال خروجی', 'ciwa-auto-sms' ); ?></th></tr>
					</thead>
					<tbody>
						<?php foreach ( $variables as $tag => $desc ) : ?>
							<tr>
								<td><code><?php echo esc_html( $tag ); ?></code></td>
								<td><?php echo esc_html( $desc ); ?></td>
								<td class="ciwa-vars__ex">
									<?php
									$sample = CIWA_Auto_SMS_Automation::replace_vars(
										$tag,
										array( 'user_id' => get_current_user_id(), 'order_id' => 0, 'phone' => '09123456789' )
									);
									echo esc_html( $sample );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div class="ciwa-example">
					<strong><?php esc_html_e( 'نمونه متن با متغیر:', 'ciwa-auto-sms' ); ?></strong><br>
					<code><?php esc_html_e( 'سلام {full_name} عزیز، سفارش شماره {order_id} ثبت شد. مبلغ: {order_total} — تیم {site_name}', 'ciwa-auto-sms' ); ?></code><br>
					<strong><?php esc_html_e( 'خروجی برای مشتری:', 'ciwa-auto-sms' ); ?></strong><br>
					<code><?php echo esc_html( CIWA_Auto_SMS_Automation::replace_vars( __( 'سلام {full_name} عزیز، سفارش شماره {order_id} ثبت شد. مبلغ: {order_total} — تیم {site_name}', 'ciwa-auto-sms' ), array( 'user_id' => get_current_user_id(), 'order_id' => 12345, 'phone' => '09123456789' ) ) ); ?></code>
				</div>
			</div>

			<div class="ciwa-help__section">
				<h4>۵. گزارشات و لاگ‌ها</h4>
				<ul>
					<li><?php esc_html_e( 'تب گزارشات: آمار کلی (موفق/ناموفق/نرخ موفقیت/در صف)، نمودار روزانه و گزارش پیشرفت کمپین‌ها را نمایش می‌دهد. خروجی CSV برای تحلیل در اکسل در دسترس است.', 'ciwa-auto-sms' ); ?></li>
					<li><?php esc_html_e( 'تب لاگ‌ها: تمام ارسال‌ها ثبت می‌شوند تا در صورت خطا بتوانید جزئیات را بررسی کنید. از فیلترها و حذف تکی/دسته‌جمعی استفاده کنید.', 'ciwa-auto-sms' ); ?></li>
				</ul>
			</div>

			<div class="ciwa-help__section ciwa-help__support">
				<h4>۶. پشتیبانی</h4>
				<p>
					<?php
					printf(
						esc_html__( 'سازنده: %1$s — وب‌سایت: %2$s', 'ciwa-auto-sms' ),
						'<strong>حسین مهرجو</strong>',
						'<a href="https://hosseinmehrjoo.ir" target="_blank" rel="noopener">hosseinmehrjoo.ir</a>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'در صورت بروز خطا، لاگ مربوطه را برای آیدی تلگرام', 'ciwa-auto-sms' ); ?> <strong>ciwaseo</strong> <?php esc_html_e( 'ارسال کنید.', 'ciwa-auto-sms' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function render_tab_sms() {
		$rules  = get_option( 'ciwa_auto_sms_rules', array() );
		while ( count( $rules ) < 20 ) {
			$rules[] = array( 'event' => '', 'time' => 'immediately', 'delay' => 1, 'message' => '' );
		}
		$events = CIWA_Auto_SMS_Config::get_events();
		$times  = CIWA_Auto_SMS_Config::get_time_units();

		$notice = get_transient( 'ciwa_auto_sms_notice' );
		if ( $notice ) {
			delete_transient( 'ciwa_auto_sms_notice' );
			?>
			<div class="ciwa-notice ciwa-notice--<?php echo esc_attr( $notice['type'] ); ?>">
				<div class="ciwa-notice__icon"><?php echo ( 'success' === $notice['type'] ) ? '✅' : ( 'error' === $notice['type'] ? '⚠️' : 'ℹ️' ); ?></div>
				<div class="ciwa-notice__body"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			</div>
			<?php
		}
		?>

		<div class="ciwa-card">
			<h3 class="ciwa-card__title"><?php esc_html_e( 'قوانین ارسال خودکار پیامک', 'ciwa-auto-sms' ); ?></h3>
			<p class="ciwa-card__hint"><?php esc_html_e( 'هر ردیف یک قانون است: وقتی رویداد انتخاب‌شده رخ دهد، پیام در زمان تعیین‌شده برای مشتری ارسال می‌شود.', 'ciwa-auto-sms' ); ?></p>

			<form method="post" class="ciwa-form">
				<?php wp_nonce_field( 'ciwa_save_rules', 'ciwa_rules_nonce' ); ?>
				<div class="ciwa-rules">
					<div class="ciwa-rules__head">
						<span><?php esc_html_e( 'انتخاب رویداد', 'ciwa-auto-sms' ); ?></span>
						<span><?php esc_html_e( 'زمان ارسال', 'ciwa-auto-sms' ); ?></span>
						<span><?php esc_html_e( 'چقدر بعد؟', 'ciwa-auto-sms' ); ?></span>
						<span><?php esc_html_e( 'متن پیام', 'ciwa-auto-sms' ); ?></span>
					</div>

					<?php for ( $i = 0; $i < 20; $i++ ) : ?>
						<?php $r = $rules[ $i ]; ?>
						<div class="ciwa-rules__row">
							<select name="ciwa_rules[<?php echo $i; ?>][event]" class="ciwa-input">
								<option value=""><?php esc_html_e( '— انتخاب —', 'ciwa-auto-sms' ); ?></option>
								<?php foreach ( $events as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['event'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<select name="ciwa_rules[<?php echo $i; ?>][time]" class="ciwa-input ciwa-rules__time">
								<?php foreach ( $times as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['time'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<input type="number" min="0" max="365" name="ciwa_rules[<?php echo $i; ?>][delay]" class="ciwa-input ciwa-rules__delay" value="<?php echo esc_attr( $r['delay'] ); ?>" <?php echo ( 'immediately' === $r['time'] ) ? 'disabled' : ''; ?>>

							<input type="text" name="ciwa_rules[<?php echo $i; ?>][message]" class="ciwa-input" value="<?php echo esc_attr( $r['message'] ); ?>" placeholder="<?php esc_attr_e( 'متن پیام...', 'ciwa-auto-sms' ); ?>">
						</div>
					<?php endfor; ?>
				</div>

				<div class="ciwa-actions">
					<button type="submit" name="ciwa_save_rules" class="ciwa-btn ciwa-btn--primary"><?php esc_html_e( 'ذخیره قوانین', 'ciwa-auto-sms' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	public function render_tab_reports() {
		$logs       = get_option( 'ciwa_auto_sms_log', array() );
		$queue      = get_option( 'ciwa_auto_sms_queue', array() );
		$campaigns  = CIWA_Auto_SMS_Campaigns::get_campaigns();
		$settings   = get_option( 'ciwa_auto_sms_settings', array() );
		$targets    = CIWA_Auto_SMS_Campaigns::get_target_labels();
		$gateways   = CIWA_Auto_SMS_Gateways::get_gateways();

		$sent_count   = 0;
		$failed_count = 0;
		$daily        = array();
		foreach ( $logs as $l ) {
			if ( 'success' === $l['status'] ) {
				$sent_count++;
			} else {
				$failed_count++;
			}
			$day = substr( $l['time'], 0, 10 );
			if ( ! isset( $daily[ $day ] ) ) {
				$daily[ $day ] = array( 'sent' => 0, 'failed' => 0 );
			}
			$daily[ $day ][ 'success' === $l['status'] ? 'sent' : 'failed' ]++;
		}

		$total_sent  = $sent_count;
		$total_failed = $failed_count;
		$total_all   = $total_sent + $total_failed;
		$rate        = $total_all > 0 ? round( ( $total_sent / $total_all ) * 100 ) : 0;
		$pending     = is_array( $queue ) ? count( $queue ) : 0;

		$range = isset( $_GET['report_range'] ) ? max( 7, min( 30, (int) $_GET['report_range'] ) ) : 7;
		$chart = array();
		for ( $i = $range - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$chart[] = array(
				'date'   => $date,
				'sent'   => isset( $daily[ $date ] ) ? $daily[ $date ]['sent'] : 0,
				'failed' => isset( $daily[ $date ] ) ? $daily[ $date ]['failed'] : 0,
			);
		}
		$max_val = 1;
		foreach ( $chart as $c ) {
			$max_val = max( $max_val, $c['sent'] + $c['failed'] );
		}

		$base_url = admin_url( 'admin.php?page=ciwa-auto-sms&tab=reports' );
		?>
		<div class="ciwa-kpis">
			<div class="ciwa-kpi ciwa-kpi--sent">
				<div class="ciwa-kpi__value"><?php echo esc_html( $total_sent ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'پیامک موفق', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--failed">
				<div class="ciwa-kpi__value"><?php echo esc_html( $total_failed ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'پیامک ناموفق', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--rate">
				<div class="ciwa-kpi__value"><?php echo esc_html( $rate ); ?>%</div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'نرخ موفقیت', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--pending">
				<div class="ciwa-kpi__value"><?php echo esc_html( $pending ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'در صف ارسال', 'ciwa-auto-sms' ); ?></div>
			</div>
			<div class="ciwa-kpi ciwa-kpi--campaign">
				<div class="ciwa-kpi__value"><?php echo esc_html( count( $campaigns ) ); ?></div>
				<div class="ciwa-kpi__label"><?php esc_html_e( 'کمپین', 'ciwa-auto-sms' ); ?></div>
			</div>
		</div>

		<div class="ciwa-card">
			<div class="ciwa-card__head">
				<h3 class="ciwa-card__title"><?php esc_html_e( 'نمودار ارسال روزانه', 'ciwa-auto-sms' ); ?></h3>
				<div class="ciwa-range">
					<a class="ciwa-range__btn <?php echo 7 === $range ? 'ciwa-range__btn--active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'report_range', 7, $base_url ) ); ?>">۷ <?php esc_html_e( 'روز', 'ciwa-auto-sms' ); ?></a>
					<a class="ciwa-range__btn <?php echo 30 === $range ? 'ciwa-range__btn--active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'report_range', 30, $base_url ) ); ?>">۳۰ <?php esc_html_e( 'روز', 'ciwa-auto-sms' ); ?></a>
				</div>
			</div>
			<div class="ciwa-chart">
				<?php foreach ( $chart as $c ) : ?>
					<?php
					$sent_h   = round( ( $c['sent'] / $max_val ) * 100 );
					$fail_h   = round( ( $c['failed'] / $max_val ) * 100 );
					$label    = substr( $c['date'], 5 );
					$total_d  = $c['sent'] + $c['failed'];
					?>
					<div class="ciwa-chart__col" title="<?php echo esc_attr( $c['date'] . ' — ' . $total_d . ' پیامک' ); ?>">
						<div class="ciwa-chart__bars">
							<div class="ciwa-chart__bar ciwa-chart__bar--sent" style="height:<?php echo esc_attr( $sent_h ); ?>%"></div>
							<div class="ciwa-chart__bar ciwa-chart__bar--failed" style="height:<?php echo esc_attr( $fail_h ); ?>%"></div>
						</div>
						<div class="ciwa-chart__label"><?php echo esc_html( $label ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="ciwa-chart__legend">
				<span><i class="ciwa-dot ciwa-dot--sent"></i> <?php esc_html_e( 'موفق', 'ciwa-auto-sms' ); ?></span>
				<span><i class="ciwa-dot ciwa-dot--failed"></i> <?php esc_html_e( 'ناموفق', 'ciwa-auto-sms' ); ?></span>
			</div>
		</div>

		<div class="ciwa-card">
			<div class="ciwa-card__head">
				<h3 class="ciwa-card__title"><?php esc_html_e( 'گزارش کمپین‌ها', 'ciwa-auto-sms' ); ?></h3>
				<div class="ciwa-actions" style="margin:0">
					<a class="ciwa-btn ciwa-btn--primary ciwa-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_export', 'campaigns', $base_url ), 'ciwa_export' ) ); ?>"><?php esc_html_e( 'خروجی CSV کمپین‌ها', 'ciwa-auto-sms' ); ?></a>
					<a class="ciwa-btn ciwa-btn--accent ciwa-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_export', 'logs', $base_url ), 'ciwa_export' ) ); ?>"><?php esc_html_e( 'خروجی CSV لاگ‌ها', 'ciwa-auto-sms' ); ?></a>
				</div>
			</div>

			<?php if ( empty( $campaigns ) ) : ?>
				<p class="description"><?php esc_html_e( 'هنوز کمپینی ثبت نشده است.', 'ciwa-auto-sms' ); ?></p>
			<?php else : ?>
				<table class="ciwa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'نام', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'هدف', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'پیشرفت ارسال', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'موفق', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'ناموفق', 'ciwa-auto-sms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $campaigns as $c ) : ?>
							<?php
							$sent   = isset( $c['sent'] ) ? (int) $c['sent'] : 0;
							$failed = isset( $c['failed'] ) ? (int) $c['failed'] : 0;
							$done   = $sent + $failed;
							$limit  = ! empty( $c['limit'] ) ? (int) $c['limit'] : 0;
							$progress = $limit > 0 ? min( 100, round( ( $done / $limit ) * 100 ) ) : ( $done > 0 ? 100 : 0 );
							?>
							<tr>
								<td><?php echo esc_html( $c['name'] ); ?></td>
								<td><?php echo esc_html( isset( $targets[ $c['target'] ] ) ? $targets[ $c['target'] ] : $c['target'] ); ?></td>
								<td>
									<span class="ciwa-badge ciwa-badge--<?php echo esc_attr( $c['status'] ); ?>">
										<?php echo esc_html( 'sent' === $c['status'] ? __( 'ارسال‌شده', 'ciwa-auto-sms' ) : __( 'در انتظار', 'ciwa-auto-sms' ) ); ?>
									</span>
								</td>
								<td>
									<div class="ciwa-progress"><div class="ciwa-progress__bar" style="width:<?php echo esc_attr( $progress ); ?>%"></div></div>
									<small><?php echo esc_html( $progress ); ?>%</small>
								</td>
								<td><?php echo esc_html( $sent ); ?></td>
								<td><?php echo esc_html( $failed ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="ciwa-card">
			<h3 class="ciwa-card__title"><?php esc_html_e( 'وضعیت درگاه پیامکی', 'ciwa-auto-sms' ); ?></h3>
			<?php if ( empty( $settings['gateway'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'درگاهی تنظیم نشده است. لطفا از تب تنظیمات درگاه را فعال کنید.', 'ciwa-auto-sms' ); ?></p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'درگاه فعال:', 'ciwa-auto-sms' ); ?>
					<strong><?php echo esc_html( isset( $gateways[ $settings['gateway'] ] ) ? $gateways[ $settings['gateway'] ] : $settings['gateway'] ); ?></strong>
				</p>
				<div class="ciwa-actions">
					<a class="ciwa-btn ciwa-btn--accent" href="<?php echo esc_url( admin_url( 'admin.php?page=ciwa-auto-sms&tab=settings' ) ); ?>"><?php esc_html_e( 'تنظیمات درگاه / ارسال تست', 'ciwa-auto-sms' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_tab_campaigns() {
		$targets  = CIWA_Auto_SMS_Campaigns::get_target_labels();
		$notice   = get_transient( 'ciwa_auto_sms_notice' );
		if ( $notice ) {
			delete_transient( 'ciwa_auto_sms_notice' );
			?>
			<div class="ciwa-notice ciwa-notice--<?php echo esc_attr( $notice['type'] ); ?>">
				<div class="ciwa-notice__icon"><?php echo ( 'success' === $notice['type'] ) ? '✅' : ( 'error' === $notice['type'] ? '⚠️' : 'ℹ️' ); ?></div>
				<div class="ciwa-notice__body"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			</div>
			<?php
		}
		?>

		<div class="ciwa-card">
			<h3 class="ciwa-card__title"><?php esc_html_e( 'ثبت کمپین جدید', 'ciwa-auto-sms' ); ?></h3>

			<form method="post" class="ciwa-form">
				<?php wp_nonce_field( 'ciwa_save_campaign', 'ciwa_campaign_nonce' ); ?>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'نام کمپین', 'ciwa-auto-sms' ); ?></span>
					<input type="text" name="ciwa_campaign_name" class="ciwa-input" placeholder="<?php esc_attr_e( 'مثلا: جشنواره تابستانه', 'ciwa-auto-sms' ); ?>">
				</label>

				<div class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'زمان ارسال (تاریخ شمسی و ساعت)', 'ciwa-auto-sms' ); ?></span>
					<div class="ciwa-datetime">
						<input type="text" id="ciwa_campaign_date" class="ciwa-input" readonly placeholder="<?php esc_attr_e( 'انتخاب تاریخ و ساعت', 'ciwa-auto-sms' ); ?>">
						<input type="hidden" name="ciwa_campaign_datetime" id="ciwa_campaign_datetime">
						<select id="ciwa_campaign_hour" class="ciwa-input ciwa-datetime__sel">
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>"><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
							<?php endfor; ?>
						</select>
						<span class="ciwa-datetime__sep">:</span>
						<select id="ciwa_campaign_minute" class="ciwa-input ciwa-datetime__sel">
							<?php for ( $m = 0; $m < 60; $m += 5 ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d', $m ) ); ?>"><?php echo esc_html( sprintf( '%02d', $m ) ); ?></option>
							<?php endfor; ?>
						</select>
						<div id="ciwa_jalali_picker" class="ciwa-jalali" style="display:none"></div>
					</div>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'کاربران هدف', 'ciwa-auto-sms' ); ?></span>
					<select name="ciwa_campaign_target" class="ciwa-input">
						<?php foreach ( $targets as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'تعداد ارسال (محدودیت تعداد مخاطب)', 'ciwa-auto-sms' ); ?></span>
					<input type="number" min="0" name="ciwa_campaign_limit" class="ciwa-input" value="0" placeholder="<?php esc_attr_e( '0 = بدون محدودیت', 'ciwa-auto-sms' ); ?>">
					<span class="ciwa-field__hint"><?php esc_html_e( 'عدد ۰ یعنی «بدون محدودیت»: پیامک برای همه کاربران هدف ارسال می‌شود. برای محدود کردن (مثلاً ۱۰۰ نفر اول) عدد دلخواه را وارد کنید.', 'ciwa-auto-sms' ); ?></span>
				</label>

				<label class="ciwa-field">
					<span class="ciwa-field__label"><?php esc_html_e( 'متن پیام', 'ciwa-auto-sms' ); ?></span>
					<textarea name="ciwa_campaign_message" class="ciwa-input ciwa-textarea" rows="4" placeholder="<?php esc_attr_e( 'متن پیامک کمپین...', 'ciwa-auto-sms' ); ?>"></textarea>
				</label>

				<div class="ciwa-actions">
					<button type="submit" name="ciwa_save_campaign" class="ciwa-btn ciwa-btn--primary"><?php esc_html_e( 'ذخیره کمپین', 'ciwa-auto-sms' ); ?></button>
				</div>
			</form>
		</div>

		<div class="ciwa-card" style="margin-top:22px">
			<h3 class="ciwa-card__title"><?php esc_html_e( 'کمپین‌های ذخیره‌شده', 'ciwa-auto-sms' ); ?></h3>
			<?php
			$all_campaigns = array_reverse( CIWA_Auto_SMS_Campaigns::get_campaigns() );
			$per_page      = 20;
			$total         = count( $all_campaigns );
			$total_pages   = max( 1, ceil( $total / $per_page ) );
			$paged         = isset( $_GET['p'] ) ? max( 1, (int) $_GET['p'] ) : 1;
			$paged         = min( $paged, $total_pages );
			$campaigns     = array_slice( $all_campaigns, ( $paged - 1 ) * $per_page, $per_page );

			$base_url = admin_url( 'admin.php?page=ciwa-auto-sms&tab=campaigns' );
			?>
			<?php if ( empty( $all_campaigns ) ) : ?>
				<p class="description"><?php esc_html_e( 'هنوز کمپینی ثبت نشده است.', 'ciwa-auto-sms' ); ?></p>
			<?php else : ?>
				<table class="ciwa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'نام', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'زمان ارسال', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'وضعیت ارسال', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'تعداد ارسال شده', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'تعداد ارسال نشده', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'تاریخ ایجاد', 'ciwa-auto-sms' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'ciwa-auto-sms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $campaigns as $c ) : ?>
							<tr>
								<td><?php echo esc_html( $c['name'] ); ?></td>
								<td><?php echo esc_html( $c['datetime'] ); ?></td>
								<td>
									<span class="ciwa-badge ciwa-badge--<?php echo esc_attr( $c['status'] ); ?>">
										<?php echo esc_html( 'sent' === $c['status'] ? __( 'ارسال‌شده', 'ciwa-auto-sms' ) : __( 'در انتظار', 'ciwa-auto-sms' ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( isset( $c['sent'] ) ? (int) $c['sent'] : 0 ); ?></td>
								<td><?php echo esc_html( isset( $c['failed'] ) ? (int) $c['failed'] : 0 ); ?></td>
								<td><?php echo esc_html( $c['created'] ); ?></td>
								<td class="ciwa-table__actions">
									<a class="ciwa-btn ciwa-btn--accent ciwa-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_campaign_action', 'run', add_query_arg( 'id', $c['id'], $base_url ) ), 'ciwa_campaign_action' ) ); ?>"><?php esc_html_e( 'اجرا', 'ciwa-auto-sms' ); ?></a>
									<button type="button" class="ciwa-btn ciwa-btn--primary ciwa-btn--sm ciwa-campaign-detail" data-name="<?php echo esc_attr( $c['name'] ); ?>" data-target="<?php echo esc_attr( isset( $targets[ $c['target'] ] ) ? $targets[ $c['target'] ] : $c['target'] ); ?>" data-limit="<?php echo esc_attr( $c['limit'] > 0 ? $c['limit'] : '—' ); ?>" data-datetime="<?php echo esc_attr( $c['datetime'] ); ?>" data-status="<?php echo esc_attr( 'sent' === $c['status'] ? __( 'ارسال‌شده', 'ciwa-auto-sms' ) : __( 'در انتظار', 'ciwa-auto-sms' ) ); ?>" data-sent="<?php echo esc_attr( isset( $c['sent'] ) ? (int) $c['sent'] : 0 ); ?>" data-failed="<?php echo esc_attr( isset( $c['failed'] ) ? (int) $c['failed'] : 0 ); ?>" data-created="<?php echo esc_attr( $c['created'] ); ?>" data-message="<?php echo esc_attr( $c['message'] ); ?>"><?php esc_html_e( 'جزئیات', 'ciwa-auto-sms' ); ?></button>
									<a class="ciwa-btn ciwa-btn--ghost ciwa-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_campaign_action', 'delete', add_query_arg( 'id', $c['id'], $base_url ) ), 'ciwa_campaign_action' ) ); ?>">🗑️</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="ciwa-pagination">
						<nav aria-label="Pagination">
							<ul class="ciwa-pagination-list">
								<li class="ciwa-pagination__item" <?php if ( $paged === 1 ) echo 'class="ciwa-pagination__item--disabled"'; ?>>
									<a href="<?php echo esc_url( add_query_arg( 'p', 1, $base_url ) ); ?>">
										<span aria-label="First page">1</span>
									</a>
								</li>
								<li class="ciwa-pagination__item" <?php if ( $paged === 1 ) echo 'class="ciwa-pagination__item--disabled"'; ?>>
									<a href="#" class="ciwa-pagination__link">
										<span aria-hidden="true">&larr;</span>
									</a>
								</li>

								<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
									<li class="ciwa-pagination__item" <?php if ( $i === $paged ) echo 'class="ciwa-pagination__item--active"'; ?>>
										<a href="<?php echo esc_url( add_query_arg( 'p', $i, $base_url ) ); ?>">
											<span><?php echo esc_html( $i ); ?></span>
										</a>
									</li>
								<?php endfor; ?>

								<li class="ciwa-pagination__item" <?php if ( $paged === $total_pages ) echo 'class="ciwa-pagination__item--disabled"'; ?>>
									<a href="<?php echo esc_url( add_query_arg( 'p', $total_pages, $base_url ) ); ?>">
										<span aria-label="Last page"><?php echo esc_html( $total_pages ); ?></span>
									</a>
								</li>
								<li class="ciwa-pagination__item" <?php if ( $paged === $total_pages ) echo 'class="ciwa-pagination__item--disabled"'; ?>>
									<a href="#" class="ciwa-pagination__link">
										<span aria-hidden="true">&rarr;</span>
									</a>
								</li>
							</ul>
						</nav>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="ciwa-modal ciwa-modal--detail" id="ciwa-detail-modal" style="display:none">
			<div class="ciwa-modal__overlay" data-close="1"></div>
			<div class="ciwa-modal__box ciwa-modal__box--detail">
				<div class="ciwa-modal__badge">📋</div>
				<h2 id="ciwa-detail-name"></h2>
				<table class="ciwa-detail-table">
					<tbody>
						<tr><th><?php esc_html_e( 'هدف', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-target"></td></tr>
						<tr><th><?php esc_html_e( 'محدودیت تعداد', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-limit"></td></tr>
						<tr><th><?php esc_html_e( 'زمان ارسال', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-datetime"></td></tr>
						<tr><th><?php esc_html_e( 'وضعیت', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-status"></td></tr>
						<tr><th><?php esc_html_e( 'تعداد ارسال شده', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-sent"></td></tr>
						<tr><th><?php esc_html_e( 'تعداد ارسال نشده', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-failed"></td></tr>
						<tr><th><?php esc_html_e( 'تاریخ ایجاد', 'ciwa-auto-sms' ); ?></th><td id="ciwa-detail-created"></td></tr>
					</tbody>
				</table>
				<div class="ciwa-detail-message">
					<strong><?php esc_html_e( 'متن پیام:', 'ciwa-auto-sms' ); ?></strong>
					<p id="ciwa-detail-message"></p>
				</div>
				<div class="ciwa-modal__actions">
					<button type="button" class="ciwa-btn ciwa-btn--ghost" data-close="1"><?php esc_html_e( 'بستن', 'ciwa-auto-sms' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_tab_logs() {
		$filter = isset( $_GET['log_filter'] ) ? sanitize_key( wp_unslash( $_GET['log_filter'] ) ) : 'all';
		if ( ! in_array( $filter, array( 'all', 'success', 'failed' ), true ) ) {
			$filter = 'all';
		}
		$all_logs = get_option( 'ciwa_auto_sms_log', array() );
		$filtered = array();
		foreach ( $all_logs as $l ) {
			if ( 'all' === $filter || ( isset( $l['status'] ) && $l['status'] === $filter ) ) {
				$filtered[] = $l;
			}
		}

		$per_page    = 20;
		$total       = count( $filtered );
		$total_pages = max( 1, ceil( $total / $per_page ) );
		$paged       = isset( $_GET['p'] ) ? max( 1, (int) $_GET['p'] ) : 1;
		$paged       = min( $paged, $total_pages );
		$logs        = array_slice( $filtered, ( $paged - 1 ) * $per_page, $per_page );

		$base_url    = admin_url( 'admin.php?page=ciwa-auto-sms&tab=logs' );
		$filter_url  = function ( $f ) use ( $base_url ) {
			return add_query_arg( 'log_filter', $f, $base_url );
		};
		?>
		<div class="ciwa-card">
			<h3 class="ciwa-card__title"><?php esc_html_e( 'لاگ ارسال پیامک‌ها', 'ciwa-auto-sms' ); ?></h3>
			<p class="ciwa-card__hint">
				<?php esc_html_e( 'در صورت بروز خطا در ارسال پیامک، لاگ مربوطه را برای توسعه‌دهنده (آیدی تلگرام ciwaseo) ارسال کنید تا بررسی شود.', 'ciwa-auto-sms' ); ?>
			</p>

			<div class="ciwa-filters">
				<a class="ciwa-filter <?php echo 'all' === $filter ? 'ciwa-filter--active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'all' ) ); ?>"><?php esc_html_e( 'همه', 'ciwa-auto-sms' ); ?></a>
				<a class="ciwa-filter <?php echo 'success' === $filter ? 'ciwa-filter--active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'success' ) ); ?>"><?php esc_html_e( 'موفق', 'ciwa-auto-sms' ); ?></a>
				<a class="ciwa-filter <?php echo 'failed' === $filter ? 'ciwa-filter--active' : ''; ?>" href="<?php echo esc_url( $filter_url( 'failed' ) ); ?>"><?php esc_html_e( 'ناموفق', 'ciwa-auto-sms' ); ?></a>
			</div>

			<?php if ( empty( $filtered ) ) : ?>
				<p class="description"><?php esc_html_e( 'لاگی ثبت نشده است.', 'ciwa-auto-sms' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'ciwa_log_bulk', 'ciwa_log_bulk_nonce' ); ?>
					<table class="ciwa-table">
						<thead>
							<tr>
								<th><input type="checkbox" id="ciwa-log-checkall"></th>
								<th><?php esc_html_e( 'زمان', 'ciwa-auto-sms' ); ?></th>
								<th><?php esc_html_e( 'گیرنده', 'ciwa-auto-sms' ); ?></th>
								<th><?php esc_html_e( 'پیام', 'ciwa-auto-sms' ); ?></th>
								<th><?php esc_html_e( 'وضعیت', 'ciwa-auto-sms' ); ?></th>
								<th><?php esc_html_e( 'توضیح', 'ciwa-auto-sms' ); ?></th>
								<th><?php esc_html_e( 'حذف', 'ciwa-auto-sms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $l ) : ?>
								<tr>
									<td><input type="checkbox" name="ciwa_log_ids[]" value="<?php echo esc_attr( $l['id'] ); ?>" class="ciwa-log-check"></td>
									<td><?php echo esc_html( $l['time'] ); ?></td>
									<td><?php echo esc_html( $l['to'] ); ?></td>
									<td class="ciwa-log-msg"><?php echo esc_html( $l['message'] ); ?></td>
									<td>
										<span class="ciwa-badge ciwa-badge--<?php echo esc_attr( $l['status'] ); ?>">
											<?php echo esc_html( 'success' === $l['status'] ? __( 'موفق', 'ciwa-auto-sms' ) : __( 'ناموفق', 'ciwa-auto-sms' ) ); ?>
										</span>
									</td>
									<td class="ciwa-log-msg"><?php echo esc_html( $l['note'] ); ?></td>
									<td>
										<a class="ciwa-btn ciwa-btn--ghost ciwa-btn--sm" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_log_action', 'delete', add_query_arg( 'id', $l['id'], $base_url ) ), 'ciwa_log_action' ) ); ?>">🗑️</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="ciwa-actions">
						<button type="submit" name="ciwa_delete_logs" class="ciwa-btn ciwa-btn--accent"><?php esc_html_e( 'حذف انتخاب‌شده‌ها', 'ciwa-auto-sms' ); ?></button>
						<a class="ciwa-btn ciwa-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ciwa_log_action', 'delete_all', $base_url ), 'ciwa_log_action' ) ); ?>"><?php esc_html_e( 'حذف همه لاگ‌ها', 'ciwa-auto-sms' ); ?></a>
					</div>
					
				</form>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="ciwa-pagination">
						<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
							<a class="ciwa-page <?php echo ( $i === $paged ) ? 'ciwa-page--active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'log_filter' => $filter, 'p' => $i ), $base_url ) ); ?>"><?php echo esc_html( $i ); ?></a>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function test_message() {
		return __( 'این یک پیام تست از افزونه اتومیشن سیوا است.', 'ciwa-auto-sms' );
	}

	private function success_message() {
		return __( 'ایول! ارتباط با موفقیت برقرار شد ✅', 'ciwa-auto-sms' ) . ' ' .
			__( 'تنظیمات درگاه به درستی انجام شد و افزونه آماده به کار است. اگر برای اولین بار است که از سیوا استفاده می‌کنید، پیشنهاد می‌کنیم راهنمای سریع ما را مشاهده کنید.', 'ciwa-auto-sms' );
	}

	private function failure_message() {
		return __( 'متاسفانه پیام ارسال نشد . تنظیمات خود را مجدد بررسی کنید و در صورتی که از صحت اطلاعات اطمینان دارید لاگ افزونه را به آیدی ciwaseo در تلگرام ارسال نمایید.', 'ciwa-auto-sms' );
	}
}
