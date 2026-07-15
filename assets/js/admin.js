(function ($) {
	'use strict';

	function gatewayToggle() {
		var gateway = $('#ciwa_gateway').val();
		$('.ciwa-field--apikey, .ciwa-field--creds').each(function () {
			var allowed = $(this).data('gateways').split(',');
			if ($.inArray(gateway, allowed) !== -1) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	}

	$(document).ready(function () {
		gatewayToggle();
		$('#ciwa_gateway').on('change', gatewayToggle);

		var $modal = $('#ciwa-welcome-modal');
		if ($modal.length) {
			$modal.find('[data-dismiss]').on('click', function (e) {
				e.preventDefault();
				$.post(
					ciwaAdmin.ajaxurl,
					{ action: 'ciwa_dismiss_welcome', nonce: ciwaAdmin.nonce },
					function () {
						$modal.fadeOut(250);
					}
				);
			});
		}

		function syncDelayState() {
			$('.ciwa-rules__time').each(function () {
				var disabled = ($(this).val() === 'immediately');
				$(this).closest('.ciwa-rules__row').find('.ciwa-rules__delay').prop('disabled', disabled);
			});
		}

		syncDelayState();
		$('.ciwa-rules__time').on('change', syncDelayState);

		$('#ciwa-send-test').on('click', function () {
			var $btn = $(this);
			var number = $('#ciwa_test_number').val();
			var $result = $('#ciwa-test-result');

			if (!number) {
				$result.removeClass('ciwa-test-result--ok')
					.addClass('ciwa-test-result--err')
					.text('لطفا شماره تست را وارد کنید.');
				return;
			}

			$btn.prop('disabled', true).css('opacity', 0.7);
			$result.removeClass('ciwa-test-result--ok ciwa-test-result--err')
				.text('در حال ارسال پیام تست...');

			$.post(
				ciwaAdmin.ajaxurl,
				{ action: 'ciwa_auto_sms_test', nonce: ciwaAdmin.nonce, number: number },
				function (res) {
					if (res.success) {
						$result.removeClass('ciwa-test-result--err')
							.addClass('ciwa-test-result--ok')
							.text('✅ ' + res.data);
					} else {
						$result.removeClass('ciwa-test-result--ok')
							.addClass('ciwa-test-result--err')
							.text('⚠️ ' + res.data);
					}
				}
			).fail(function () {
				$result.removeClass('ciwa-test-result--ok')
					.addClass('ciwa-test-result--err')
					.text('خطا در ارتباط با سرور.');
			}).always(function () {
				$btn.prop('disabled', false).css('opacity', 1);
			});
		});

		initJalaliPicker();
		initCampaignDetail();
		initLogChecks();
	});

	function initCampaignDetail() {
		var $modal = $('#ciwa-detail-modal');
		if ( ! $modal.length ) { return; }
		$('.ciwa-campaign-detail').on('click', function () {
			var d = $(this).data();
			$('#ciwa-detail-name').text(d.name);
			$('#ciwa-detail-target').text(d.target);
			$('#ciwa-detail-limit').text(d.limit);
			$('#ciwa-detail-datetime').text(d.datetime);
			$('#ciwa-detail-status').text(d.status);
			$('#ciwa-detail-sent').text(d.sent);
			$('#ciwa-detail-failed').text(d.failed);
			$('#ciwa-detail-created').text(d.created);
			$('#ciwa-detail-message').text(d.message);
			$modal.show();
		});
		$modal.find('[data-close]').on('click', function () { $modal.hide(); });
	}

	function initLogChecks() {
		var $checkAll = $('#ciwa-log-checkall');
		if ( ! $checkAll.length ) { return; }
		$checkAll.on('change', function () {
			$('.ciwa-log-check').prop('checked', this.checked);
		});
	}

	function initJalaliPicker() {
		if ( ! $('#ciwa_campaign_date').length ) {
			return;
		}

		var months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
		var wd = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
		var selectedGreg = null;

		function pad(n) { return (n < 10 ? '0' : '') + n; }
		function isLeap(jy) { var r = (jy - 979) % 33; if (r < 0) r += 33; return [1, 5, 9, 13, 17, 22, 26, 30].indexOf(r) !== -1; }
		function daysInMonth(jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : (isLeap(jy) ? 30 : 29)); }

function g2j(gy, gm, gd) {
  gy = parseInt(gy, 10);
  gm = parseInt(gm, 10);
  gd = parseInt(gd, 10);
  var g_day_no = 365 * (gy - 1) + Math.floor((gy - 1) / 4) - Math.floor((gy - 1) / 100) + Math.floor((gy - 1) / 400);
  switch (gm) {
    case 2: g_day_no += 31; break;
    case 3: g_day_no += 59; break;
    case 4: g_day_no += 90; break;
    case 5: g_day_no += 120; break;
    case 6: g_day_no += 151; break;
    case 7: g_day_no += 181; break;
    case 8: g_day_no += 212; break;
    case 9: g_day_no += 243; break;
    case 10: g_day_no += 273; break;
    case 11: g_day_no += 304; break;
    case 12: g_day_no += 334; break;
  }
  g_day_no += gd - 1;
  var j_day_no = g_day_no - 79;
  var j_np = Math.floor(j_day_no / 12053);
  j_day_no = j_day_no % 12053;
  var jy = 979 + 33 * j_np + 4 * Math.floor(j_day_no / 1461);
  j_day_no %= 1461;
  if (j_day_no >= 366) {
    jy += Math.floor((j_day_no - 1) / 365);
    j_day_no = (j_day_no - 1) % 365;
  }
  var jm = (j_day_no < 186) ? 1 + Math.floor(j_day_no / 31) : 7 + Math.floor((j_day_no - 186) / 30);
  var jd = 1 + ((j_day_no < 186) ? j_day_no % 31 : (j_day_no - 186) % 30);
  return { jy: jy, jm: jm, jd: jd };
}

function j2g(jy, jm, jd) {
  jy = parseInt(jy, 10);
  jm = parseInt(jm, 10);
  jd = parseInt(jd, 10);
  var j_day_no = 365 * (jy - 1) + Math.floor((jy - 1) / 33) * 8 + Math.floor((jy % 33 + 3) / 4);
  j_day_no += (jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30 + 186);
  j_day_no += jd - 1;
  var g_day_no = j_day_no + 79;
  var gy = 400 * Math.floor(g_day_no / 146097);
  g_day_no %= 146097;
  var leap = true;
  if (g_day_no >= 36525) {
    g_day_no--;
    gy += 100;
    leap = false;
  }
  gy += 4 * Math.floor(g_day_no / 1461);
  g_day_no %= 1461;
  if (g_day_no >= 366) {
    g_day_no--;
    gy += Math.floor(g_day_no / 365);
    g_day_no %= 365;
  }
  var gm = Math.floor(g_day_no / 31) + 1;
  var gd = g_day_no % 31 + 1;
  return { gy: gy, gm: gm, gd: gd };
}

		function gregorianToJdn(gy, gm, gd) {
			var a = Math.floor((14 - gm) / 12);
			var y = gy + 4800 - a;
			var m = gm + 12 * a - 3;
			return gd + Math.floor((153 * m + 2) / 5) + 365 * y + Math.floor(y / 4) - Math.floor(y / 100) + Math.floor(y / 400) - 32045;
		}

function jdnToJalali(jdn) {
  var j = jdn - 1948320;
  var jy = 979 + Math.floor(j / 12053) * 33;
  var jdn_rem = j % 12053;
  var tmp = Math.floor(jdn_rem / 1461);
  jy += 4 * tmp;
  jdn_rem = jdn_rem % 1461;
  if (jdn_rem >= 366) {
    jy += Math.floor((jdn_rem - 1) / 365);
    jdn_rem = (jdn_rem - 1) % 365;
  }
  var jm, jd;
  if (jdn_rem < 186) {
    jm = 1 + Math.floor(jdn_rem / 31);
    jd = 1 + (jdn_rem % 31);
  } else {
    jm = 7 + Math.floor((jdn_rem - 186) / 30);
    jd = 1 + ((jdn_rem - 186) % 30);
  }
  return { jy: jy, jm: jm, jd: jd };
}



		var today = new Date();
		var jt = g2j(today.getFullYear(), today.getMonth() + 1, today.getDate());
		var view = { y: jt.jy, m: jt.jm };

		function updateDateTime() {
			if ( ! selectedGreg ) { return; }
			var g = j2g(selectedGreg.y, selectedGreg.m, selectedGreg.d);
			var hh = $('#ciwa_campaign_hour').val();
			var mm = $('#ciwa_campaign_minute').val();
			$('#ciwa_campaign_datetime').val(g.gy + '-' + pad(g.gm) + '-' + pad(g.gd) + ' ' + hh + ':' + mm + ':00');
			$('#ciwa_campaign_date').val(selectedGreg.y + '/' + pad(selectedGreg.m) + '/' + pad(selectedGreg.d) + ' ' + hh + ':' + mm);
		}

		function render() {
			var html = '';
			html += '<div class="ciwa-jalali__head">';
			html += '<button type="button" class="ciwa-jalali__nav" data-dir="-1">‹</button>';
			html += '<div class="ciwa-jalali__title">' + months[view.m - 1] + ' ' + view.y + '</div>';
			html += '<button type="button" class="ciwa-jalali__nav" data-dir="1">›</button>';
			html += '</div>';
			html += '<div class="ciwa-jalali__grid">';
			for (var i = 0; i < wd.length; i++) { html += '<div class="ciwa-jalali__wd">' + wd[i].charAt(0) + '</div>'; }
			var first = j2g(view.y, view.m, 1);
			var dow = new Date(first.gy, first.gm - 1, first.gd).getDay();
			var offset = (dow + 1) % 7;
			for (var e = 0; e < offset; e++) { html += '<div class="ciwa-jalali__day ciwa-jalali__day--empty"></div>'; }
			var dim = daysInMonth(view.y, view.m);
			for (var d = 1; d <= dim; d++) {
				var cls = 'ciwa-jalali__day';
				if (selectedGreg && selectedGreg.y === view.y && selectedGreg.m === view.m && selectedGreg.d === d) { cls += ' ciwa-jalali__day--sel'; }
				html += '<div class="' + cls + '" data-d="' + d + '">' + d + '</div>';
			}
			html += '</div>';
			$('#ciwa_jalali_picker').html(html);
		}

		$('#ciwa_campaign_date').on('click', function (e) {
			e.stopPropagation();
			$('#ciwa_jalali_picker').toggle();
			render();
		});
		$('#ciwa_jalali_picker').on('click', function (e) { e.stopPropagation(); });
		$(document).on('click', function () { $('#ciwa_jalali_picker').hide(); });

		$('#ciwa_jalali_picker').on('click', '.ciwa-jalali__nav', function () {
			view.m += parseInt($(this).data('dir'), 10);
			if (view.m < 1) { view.m = 12; view.y--; }
			if (view.m > 12) { view.m = 1; view.y++; }
			render();
		});
		$('#ciwa_jalali_picker').on('click', '.ciwa-jalali__day', function () {
			var d = parseInt($(this).data('d'), 10);
			if ( ! d ) { return; }
			selectedGreg = { y: view.y, m: view.m, d: d };
			updateDateTime();
			$('#ciwa_jalali_picker').hide();
		});
		$('#ciwa_campaign_hour, #ciwa_campaign_minute').on('change', updateDateTime);
	}
})(jQuery);