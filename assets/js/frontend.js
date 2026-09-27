/**
 * WooCommerce Dynamic Attribute Add-ons - Frontend Script
 * Optimized & High-Performance Version
 */
(function ($) {
	'use strict';

	// 0. Intercept ANY AJAX add-to-cart request to guarantee wdaa_option data is attached,
	// even when themes use stripped custom AJAX payloads like { product_id, quantity }
	$.ajaxPrefilter(function (options, originalOptions, jqXHR) {
		try {
			var url = (options.url || '').toString();
			var isAddToCart = (
				url.indexOf('wc-ajax=add_to_cart') !== -1 ||
				url.indexOf('add_to_cart') !== -1 ||
				(typeof options.data === 'string' && options.data.indexOf('add-to-cart=') !== -1)
			);

			if (!isAddToCart) {
				return;
			}

			var $masterBox = $('#wdaa-master-box');
			if (!$masterBox.length) {
				return;
			}

			// Validate if required addons are selected
			var isMissing = false;
			$masterBox.find('.wdaa-addon-row').each(function () {
				var $checked = $(this).find('input[type="radio"]:checked');
				if (!$checked.length || !$checked.val()) {
					isMissing = true;
					return false;
				}
			});

			if (isMissing) {
				// Abort AJAX call and trigger frontend validation UI
				if (typeof jqXHR.abort === 'function') {
					jqXHR.abort();
				}
				if (typeof window.wdaaValidateAddons === 'function') {
					window.wdaaValidateAddons();
				}
				return;
			}

			var $checkedRadios = $masterBox.find('.wdaa-addon-row input[type="radio"]:checked');
			if (!$checkedRadios.length) {
				return;
			}

			// Build dictionary of selected options
			var wdaaMap = {};
			$checkedRadios.each(function () {
				var name = $(this).attr('name');
				var val = $(this).val();
				if (name && val) {
					wdaaMap[name] = val;
				}
			});

			var nonce = $('#wdaa_cart_nonce').val();
			if (nonce) {
				wdaaMap['wdaa_cart_nonce'] = nonce;
			}

			// 1. If options.data is string
			if (typeof options.data === 'string') {
				var serialized = $checkedRadios.serialize();
				if (serialized) {
					if (options.data.indexOf('wdaa_option') === -1) {
						options.data += (options.data.length ? '&' : '') + serialized;
						if (nonce && options.data.indexOf('wdaa_cart_nonce') === -1) {
							options.data += '&wdaa_cart_nonce=' + encodeURIComponent(nonce);
						}
					}
				}
			}
			// 2. If options.data is plain object
			else if (typeof options.data === 'object' && options.data !== null && !(options.data instanceof FormData)) {
				for (var k in wdaaMap) {
					if (wdaaMap.hasOwnProperty(k)) {
						options.data[k] = wdaaMap[k];
					}
				}
			}
			// 3. If options.data is FormData
			else if (options.data instanceof FormData) {
				for (var fk in wdaaMap) {
					if (wdaaMap.hasOwnProperty(fk) && !options.data.has(fk)) {
						options.data.append(fk, wdaaMap[fk]);
					}
				}
			}
		} catch (err) {
			console.warn('WDAA ajaxPrefilter error:', err);
		}
	});

	$(document).ready(function () {
		var $masterBox = $('#wdaa-master-box');
		if (!$masterBox.length) {
			return;
		}

		var $form = $('form.cart');
		if (!$form.length) {
			return;
		}

		var isVariable = $form.hasClass('variations_form') || String($masterBox.data('is-variable')) === '1';
		var currentBasePrice = 0;
		var hasValidBasePrice = false;
		var currency = (typeof wdaa_vars !== 'undefined' && wdaa_vars.currency_symbol) ? wdaa_vars.currency_symbol : 'تومان';
		var thousandSep = (typeof wdaa_vars !== 'undefined' && typeof wdaa_vars.thousand_sep === 'string') ? wdaa_vars.thousand_sep : ',';
		var decimalSep = (typeof wdaa_vars !== 'undefined' && typeof wdaa_vars.decimal_sep === 'string') ? wdaa_vars.decimal_sep : '.';
		var decimals = (typeof wdaa_vars !== 'undefined' && typeof wdaa_vars.decimals !== 'undefined') ? parseInt(wdaa_vars.decimals, 10) : 0;
		if (isNaN(decimals) || decimals < 0) {
			decimals = 0;
		}
		var labelTotalWithAddons = (typeof wdaa_vars !== 'undefined' && wdaa_vars.i18n_total_with_addons) ? wdaa_vars.i18n_total_with_addons : 'مجموع با احتساب گزینه‌های انتخابی:';
		var labelFinalPrice = (typeof wdaa_vars !== 'undefined' && wdaa_vars.i18n_final_price) ? wdaa_vars.i18n_final_price : 'قیمت نهایی:';
		var labelAddonsCost = (typeof wdaa_vars !== 'undefined' && wdaa_vars.i18n_addons_cost) ? wdaa_vars.i18n_addons_cost : 'هزینه گزینه‌های انتخابی:';

		// Ensure body and form have addon marker classes and variation price is hidden
		$form.addClass('wdaa-has-addons');
		$('body').addClass('wdaa-has-addons');
		$form.find('.woocommerce-variation-price, .single_variation .price').addClass('wdaa-hidden').attr('style', 'display: none !important;');

		// 1. Initial Base Price from PHP data-attribute
		var serverPrice = parseFloat($masterBox.data('base-price'));
		if (isVariable) {
			var initialVarId = parseInt($form.find('input[name="variation_id"]').val(), 10);
			if (!isNaN(initialVarId) && initialVarId > 0 && !isNaN(serverPrice) && serverPrice > 0) {
				currentBasePrice = serverPrice;
				hasValidBasePrice = true;
			} else {
				hasValidBasePrice = false;
				currentBasePrice = 0;
			}
		} else {
			if (!isNaN(serverPrice) && serverPrice > 0 && serverPrice < 1000000000000) {
				currentBasePrice = serverPrice;
				hasValidBasePrice = true;
			}
		}

		// Helper: Convert English numbers to Persian digits
		function toPersianDigits(numStr) {
			if (numStr === null || numStr === undefined) return '';
			var id = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
			return numStr.toString().replace(/[0-9]/g, function (w) {
				return id[+w];
			});
		}

		// Helper: Format number using WooCommerce accounting.js and localized precision settings
		function formatMoney(num) {
			if (isNaN(num) || !isFinite(num) || num <= 0 || num > 1000000000000) {
				return '۰';
			}
			var formatted;
			if (typeof window.accounting !== 'undefined' && typeof window.accounting.formatNumber === 'function') {
				formatted = window.accounting.formatNumber(num, decimals, thousandSep, decimalSep);
			} else {
				var fixed = num.toFixed(decimals);
				var splitParts = fixed.split('.');
				splitParts[0] = splitParts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
				formatted = splitParts.length > 1 ? splitParts.join(decimalSep) : splitParts[0];
			}
			return toPersianDigits(formatted);
		}

		// Reposition add-ons box between default attributes (like Size) and the "صاف" (clear) button
		function repositionMasterBox() {
			if (!$masterBox.length || $('#wdaa-table-row').length) {
				return;
			}

			var $resetTr = $form.find('tr.woocommerce_reset_variations_link_tr');
			var $resetBtn = $form.find('.reset_variations');

			if ($resetTr.length && $resetTr.parent().is('tbody, table')) {
				var $newTr = $('<tr id="wdaa-table-row" class="wdaa-table-row"><td colspan="10" class="wdaa-table-cell"></td></tr>');
				$newTr.find('td').append($masterBox);
				$newTr.insertBefore($resetTr);
				var prev = $newTr[0].previousElementSibling;
				if (prev && prev.tagName === 'TR') {
					$(prev).addClass('wdaa-prev-row-no-margin');
				}
			} else if ($resetBtn.length) {
				var $tr = $resetBtn.closest('tr');
				if ($tr.length && $tr.parent().is('tbody, table')) {
					var $newTrFallback = $('<tr id="wdaa-table-row" class="wdaa-table-row"><td colspan="10" class="wdaa-table-cell"></td></tr>');
					$newTrFallback.find('td').append($masterBox);
					$newTrFallback.insertBefore($tr);
					var prevRow = $newTrFallback[0].previousElementSibling;
					if (prevRow && prevRow.tagName === 'TR') {
						$(prevRow).addClass('wdaa-prev-row-no-margin');
					}
				} else {
					var $wrapper = $resetBtn.closest('.reset_variations_wrap, .wd-reset-var, .clear-filter-wrap, .clear-filters');
					if ($wrapper.length) {
						$masterBox.insertBefore($wrapper);
					} else {
						$masterBox.insertBefore($resetBtn);
					}
				}
			}
		}

		// Calculate total extra price from all selected addons
		function getTotalExtraPrice() {
			var totalExtra = 0;
			$masterBox.find('.wdaa-addon-row input[type="radio"]:checked').each(function () {
				var price = parseFloat($(this).data('price')) || 0;
				totalExtra += price;
			});
			return totalExtra;
		}

		var resetHideTimer = null;
		var noticeHideTimer = null;

		function showResetButton() {
			var $resetBtn = $form.find('.reset_variations');
			if (!$resetBtn.length) return;
			var $resetTr = $resetBtn.closest('tr.woocommerce_reset_variations_link_tr, tr');

			if (resetHideTimer) {
				clearTimeout(resetHideTimer);
				resetHideTimer = null;
			}

			var wasHidden = $resetBtn.hasClass('wdaa-hidden') || $resetBtn.hasClass('wdaa-animating-out');
			$resetTr.removeClass('wdaa-hidden wdaa-animating-out');
			$resetBtn.removeClass('wdaa-hidden wdaa-animating-out');

			if (wasHidden) {
				$resetTr.removeClass('wdaa-animate-in');
				$resetBtn.removeClass('wdaa-animate-in');
				if ($resetBtn[0]) {
					void $resetBtn[0].offsetWidth;
				}
				$resetTr.addClass('wdaa-animate-in');
				$resetBtn.addClass('wdaa-animate-in');
			}
		}

		function hideResetButton(immediate) {
			var $resetBtn = $form.find('.reset_variations');
			if (!$resetBtn.length) return;
			var $resetTr = $resetBtn.closest('tr.woocommerce_reset_variations_link_tr, tr');

			if ($resetBtn.hasClass('wdaa-hidden') && !$resetBtn.hasClass('wdaa-animating-out')) {
				return;
			}

			if (immediate) {
				if (resetHideTimer) {
					clearTimeout(resetHideTimer);
					resetHideTimer = null;
				}
				$resetTr.addClass('wdaa-hidden').removeClass('wdaa-animate-in wdaa-animating-out');
				$resetBtn.addClass('wdaa-hidden').removeClass('wdaa-animate-in wdaa-animating-out');
				return;
			}

			if ($resetBtn.hasClass('wdaa-animating-out')) {
				return;
			}

			if (resetHideTimer) {
				clearTimeout(resetHideTimer);
			}

			$resetTr.removeClass('wdaa-animate-in').addClass('wdaa-animating-out');
			$resetBtn.removeClass('wdaa-animate-in').addClass('wdaa-animating-out');

			resetHideTimer = setTimeout(function () {
				$resetTr.addClass('wdaa-hidden').removeClass('wdaa-animating-out');
				$resetBtn.addClass('wdaa-hidden').removeClass('wdaa-animating-out');
				resetHideTimer = null;
			}, 290);
		}

		// Manage reset variations button ("صاف") visibility and enter/exit animation
		function syncResetButton(immediate) {
			var $resetBtn = $form.find('.reset_variations');
			if (!$resetBtn.length) return;

			// Check ONLY WooCommerce variation attributes (name starts with attribute_)
			var hasSelection = false;
			$form.find('select[name^="attribute_"]').each(function () {
				var val = $(this).val();
				if (val && val.toString().trim() !== '') {
					hasSelection = true;
					return false;
				}
			});

			if (!hasSelection) {
				$form.find('.variations input[name^="attribute_"]:checked').each(function () {
					var val = $(this).val();
					if (val && val.toString().trim() !== '') {
						hasSelection = true;
						return false;
					}
				});
			}

			if (hasSelection) {
				showResetButton();
			} else {
				hideResetButton(immediate);
			}
		}

		function showPriceNotice(labelText, $priceNode) {
			var $notice = $masterBox.find('.wdaa-live-price-notice');
			if (!$notice.length) {
				$notice = $('<div class="wdaa-live-price-notice wdaa-hidden"></div>');
				$masterBox.append($notice);
			}

			if (noticeHideTimer) {
				clearTimeout(noticeHideTimer);
				noticeHideTimer = null;
			}

			var wasHidden = $notice.hasClass('wdaa-hidden') || $notice.hasClass('wdaa-animating-out');
			$notice.empty()
				.append($('<span></span>').text(labelText))
				.append(' ')
				.append($priceNode);

			$notice.removeClass('wdaa-hidden wdaa-animating-out');
			if (wasHidden) {
				$notice.removeClass('wdaa-animate-in');
				if ($notice[0]) {
					void $notice[0].offsetWidth;
				}
				$notice.addClass('wdaa-animate-in');
			}
		}

		function hidePriceNotice(immediate) {
			var $notice = $masterBox.find('.wdaa-live-price-notice');
			if (!$notice.length) return;

			if ($notice.hasClass('wdaa-hidden') && !$notice.hasClass('wdaa-animating-out')) {
				return;
			}

			if (immediate) {
				if (noticeHideTimer) {
					clearTimeout(noticeHideTimer);
					noticeHideTimer = null;
				}
				$notice.addClass('wdaa-hidden').removeClass('wdaa-animate-in wdaa-animating-out');
				return;
			}

			if ($notice.hasClass('wdaa-animating-out')) {
				return;
			}

			if (noticeHideTimer) {
				clearTimeout(noticeHideTimer);
			}

			$notice.removeClass('wdaa-animate-in').addClass('wdaa-animating-out');
			noticeHideTimer = setTimeout(function () {
				$notice.addClass('wdaa-hidden').removeClass('wdaa-animating-out');
				noticeHideTimer = null;
			}, 290);
		}

		// Update live price display with enter and exit animations
		function updateLivePriceDisplay(immediate) {
			try {
				var totalExtra = getTotalExtraPrice();

				function createPriceNodes(amountText) {
					var $currencySpan = $('<span class="woocommerce-Price-currencySymbol wdaa-currency"><svg class="wdaa-toman-icon w-4 h-4" aria-hidden="true"><use href="#toman-icon" xlink:href="#toman-icon"></use></svg></span>');
					var $amountSpan = $('<span class="wdaa-amount"></span>').text(amountText);
					return [$currencySpan, $amountSpan];
				}

				// Always hide standard variation price elements when addons are used
				$form.find('.woocommerce-variation-price, .single_variation .price, .single_variation_wrap .woocommerce-variation-price')
					.addClass('wdaa-hidden')
					.attr('style', 'display: none !important;');

				if (hasValidBasePrice && currentBasePrice > 0 && currentBasePrice < 1000000000000) {
					var finalTotal = currentBasePrice + totalExtra;
					var formattedTotal = formatMoney(finalTotal);
					var priceLabel = (totalExtra > 0) ? labelTotalWithAddons : labelFinalPrice;

					// Render updated price notice inside master box with smooth enter/pulse
					var $strongPrice = $('<strong class="wdaa-price-display wdaa-price-updated"></strong>').append(createPriceNodes(formattedTotal));
					showPriceNotice(priceLabel, $strongPrice);
				} else {
					if (totalExtra > 0) {
						var $extraStrong = $('<strong class="wdaa-price-display wdaa-price-updated"></strong>').append(createPriceNodes(formatMoney(totalExtra) + '+'));
						showPriceNotice(labelAddonsCost, $extraStrong);
					} else {
						hidePriceNotice(immediate);
					}
				}
			} catch (err) {
				console.warn('WDAA Price update error:', err);
			}
		}

		// Handle Radio Pill click and styling
		$masterBox.on('change', '.wdaa-pill-item input[type="radio"]', function () {
			var $row = $(this).closest('.wdaa-addon-row');
			$row.find('.wdaa-pill-item').removeClass('is-selected');
			$(this).closest('.wdaa-pill-item').addClass('is-selected');
			$row.removeClass('wdaa-row-error');
			if (!$masterBox.find('.wdaa-addon-row.wdaa-row-error').length) {
				$('.wdaa-validation-notice').remove();
			}
			updateLivePriceDisplay();
		});

		$masterBox.on('click', '.wdaa-pill-item', function () {
			var $radio = $(this).find('input[type="radio"]');
			if (!$radio.is(':checked')) {
				$radio.prop('checked', true).trigger('change');
			}
		});

		// Listen for variation selection changes to sync the reset button
		$form.on('change', 'select[name^="attribute_"], input[name^="attribute_"]', function () {
			syncResetButton();
		});

		$form.on('click', '.reset_variations', function () {
			setTimeout(syncResetButton, 20);
		});

		// WooCommerce Variable Product Events
		if (isVariable) {
			$form.on('wc_variation_form', function () {
				repositionMasterBox();
				syncResetButton();
				updateLivePriceDisplay();
				$form.find('.woocommerce-variation-price, .single_variation .price').addClass('wdaa-hidden').attr('style', 'display: none !important;');
			});

			$form.on('show_variation', function (event, variation) {
				syncResetButton();
				var $vPrice = $form.find('.woocommerce-variation-price');
				if ($vPrice.length && !$vPrice.hasClass('wdaa-hidden') && $vPrice[0]) {
					$vPrice.removeClass('wdaa-animate-in');
					void $vPrice[0].offsetWidth;
					$vPrice.addClass('wdaa-animate-in');
				}
				if (variation && typeof variation.display_price !== 'undefined') {
					var vPrice = parseFloat(variation.display_price);
					if (!isNaN(vPrice) && vPrice > 0 && vPrice < 1000000000000) {
						currentBasePrice = vPrice;
						hasValidBasePrice = true;
						updateLivePriceDisplay();
					}
				}
				$form.find('.woocommerce-variation-price, .single_variation .price').addClass('wdaa-hidden').attr('style', 'display: none !important;');
			});

			$form.on('hide_variation reset_data', function () {
				syncResetButton();
				hasValidBasePrice = false;
				currentBasePrice = 0;
				updateLivePriceDisplay();
				$form.find('.woocommerce-variation-price, .single_variation .price').addClass('wdaa-hidden').attr('style', 'display: none !important;');
			});

			// Continuously guard against dynamic theme scripts reviving the variation price
			try {
				if (window.MutationObserver && $form[0]) {
					var priceObserver = new MutationObserver(function () {
						var $unhidden = $form.find('.woocommerce-variation-price:not(.wdaa-hidden), .single_variation .price:not(.wdaa-hidden)');
						if ($unhidden.length) {
							$unhidden.addClass('wdaa-hidden').attr('style', 'display: none !important;');
						}
					});
					priceObserver.observe($form[0], { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
				}
			} catch (obsErr) {
				// Fallback to event-based hiding
			}
		}

		// Ensure wdaa-master-box inputs are attached inside form.cart during submit or AJAX add-to-cart
		function ensureBoxInForm() {
			if ($masterBox.length && $form.length && !$.contains($form[0], $masterBox[0])) {
				$form.append($masterBox);
			}
		}

		// Validate that all addon options are selected before adding to cart
		function validateAddons() {
			var isValid = true;
			var firstMissingRow = null;
			var missingLabels = [];

			$masterBox.find('.wdaa-addon-row').each(function () {
				var $row = $(this);
				var $checked = $row.find('input[type="radio"]:checked');
				if (!$checked.length || !$checked.val()) {
					isValid = false;
					if (!firstMissingRow) {
						firstMissingRow = $row;
					}
					var titleText = $row.find('.wdaa-attribute-title').text().replace(/انتخاب|:|：/g, '').trim();
					if (titleText) {
						missingLabels.push(titleText);
					}
					$row.addClass('wdaa-row-error');
				} else {
					$row.removeClass('wdaa-row-error');
				}
			});

			if (!isValid) {
				$('.wdaa-validation-notice').remove();
				var $noticeList = $('<ul class="woocommerce-error" role="alert"></ul>');
				missingLabels.forEach(function (lbl) {
					$noticeList.append($('<li></li>').text('لطفاً گزینه مورد نظر برای «' + lbl + '» را انتخاب کنید.'));
				});
				var $noticeWrap = $('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-wdaa wdaa-validation-notice"></div>').append($noticeList);

				var $targetNoticeContainer = $('.woocommerce-notices-wrapper').first();
				if ($targetNoticeContainer.length) {
					$targetNoticeContainer.empty().append($noticeWrap);
				} else {
					$form.before($noticeWrap);
				}

				if (firstMissingRow && firstMissingRow.length) {
					$('html, body').animate({
						scrollTop: firstMissingRow.offset().top - 120
					}, 350);
				}
				return false;
			}

			$('.wdaa-validation-notice').remove();
			return true;
		}

		window.wdaaValidateAddons = validateAddons;

		$form.on('submit', function (e) {
			ensureBoxInForm();
			if (!validateAddons()) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return false;
			}
		});

		$(document).on('click', '.single_add_to_cart_button', function (e) {
			ensureBoxInForm();
			if (!validateAddons()) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return false;
			}
		});

		// Initial execution
		repositionMasterBox();
		ensureBoxInForm();
		syncResetButton(true);
		updateLivePriceDisplay(true);
		$form.find('.woocommerce-variation-price, .single_variation .price').addClass('wdaa-hidden').attr('style', 'display: none !important;');
	});
})(jQuery);
