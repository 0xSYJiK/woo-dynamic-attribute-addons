/**
 * WooCommerce Dynamic Attribute Add-ons - Frontend Script
 * Optimized & High-Performance Version
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $masterBox = $('#wdaa-master-box');
        if (!$masterBox.length) {
            return;
        }

        var $form = $('form.cart');
        if (!$form.length) {
            return;
        }

        var isVariable = $form.hasClass('variations_form') || $masterBox.data('is-variable') == '1';
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
        var labelAddonsCost = (typeof wdaa_vars !== 'undefined' && wdaa_vars.i18n_addons_cost) ? wdaa_vars.i18n_addons_cost : 'هزینه گزینه‌های انتخابی:';

        // 1. Initial Base Price from PHP data-attribute
        var serverPrice = parseFloat($masterBox.data('base-price'));
        if (!isNaN(serverPrice) && serverPrice > 0 && serverPrice < 1000000000000) {
            currentBasePrice = serverPrice;
            hasValidBasePrice = true;
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
            if (!$resetTr.length) {
                $resetTr = $('tr.woocommerce_reset_variations_link_tr');
            }

            var $resetBtn = $form.find('.reset_variations');
            if (!$resetBtn.length) {
                $resetBtn = $('.reset_variations');
            }

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

        // Update live price display
        function updateLivePriceDisplay() {
            try {
                var totalExtra = getTotalExtraPrice();
                var $notice = $masterBox.find('.wdaa-live-price-notice');
                if (!$notice.length) {
                    $notice = $('<div class="wdaa-live-price-notice wdaa-hidden"></div>');
                    $masterBox.append($notice);
                }

                function createPriceNodes(amountText) {
                    var $currencySpan = $('<span class="woocommerce-Price-currencySymbol wdaa-currency"></span>').text(currency);
                    var $amountSpan = $('<span class="wdaa-amount"></span>').text(amountText);
                    return [$currencySpan, $amountSpan];
                }

                if (hasValidBasePrice && currentBasePrice > 0 && currentBasePrice < 1000000000000) {
                    var finalTotal = currentBasePrice + totalExtra;
                    var formattedTotal = formatMoney(finalTotal);

                    // Update notice inside the box
                    if (totalExtra > 0) {
                        var $strongPrice = $('<strong class="wdaa-price-display"></strong>').append(createPriceNodes(formattedTotal));
                        $notice.empty()
                            .append($('<span></span>').text(labelTotalWithAddons))
                            .append(' ')
                            .append($strongPrice)
                            .removeClass('wdaa-hidden');
                    } else {
                        $notice.addClass('wdaa-hidden');
                    }

                    // Update standard variation price container
                    var $varPrice = $form.find('.woocommerce-variation-price .price, .single_variation .price');
                    if ($varPrice.length) {
                        var $varAmount = $varPrice.find('.amount');
                        if ($varAmount.length) {
                            var $priceDisplay = $('<span class="wdaa-price-display"></span>').append(createPriceNodes(formattedTotal));
                            $varAmount.last().empty().append($priceDisplay);
                        }
                    }
                } else {
                    if (totalExtra > 0) {
                        var $extraStrong = $('<strong class="wdaa-price-display"></strong>').append(createPriceNodes(formatMoney(totalExtra) + '+'));
                        $notice.empty()
                            .append($('<span></span>').text(labelAddonsCost))
                            .append(' ')
                            .append($extraStrong)
                            .removeClass('wdaa-hidden');
                    } else {
                        $notice.addClass('wdaa-hidden');
                    }
                }
            } catch (err) {
                console.warn('WDAA Price update error:', err);
            }
        }

        // Manage reset variations button ("صاف") visibility and zero-space collapse
        function syncResetButton() {
            var $resetBtn = $form.find('.reset_variations');
            if (!$resetBtn.length) {
                $resetBtn = $('.reset_variations');
            }
            if (!$resetBtn.length) return;

            var $resetTr = $resetBtn.closest('tr.woocommerce_reset_variations_link_tr, tr');

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
                if ($resetTr.length) {
                    $resetTr.removeClass('wdaa-hidden');
                }
                $resetBtn.removeClass('wdaa-hidden');
            } else {
                if ($resetTr.length) {
                    $resetTr.addClass('wdaa-hidden');
                }
                $resetBtn.addClass('wdaa-hidden');
            }
        }

        // Handle Radio Pill click and styling
        $masterBox.on('change', '.wdaa-pill-item input[type="radio"]', function () {
            var $row = $(this).closest('.wdaa-addon-row');
            $row.find('.wdaa-pill-item').removeClass('is-selected');
            $(this).closest('.wdaa-pill-item').addClass('is-selected');
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
            $form.on('show_variation', function (event, variation) {
                syncResetButton();
                if (variation && typeof variation.display_price !== 'undefined') {
                    var vPrice = parseFloat(variation.display_price);
                    if (!isNaN(vPrice) && vPrice > 0 && vPrice < 1000000000000) {
                        currentBasePrice = vPrice;
                        hasValidBasePrice = true;
                        updateLivePriceDisplay();
                    }
                }
            });

            $form.on('hide_variation reset_data', function () {
                syncResetButton();
                if (serverPrice > 0) {
                    currentBasePrice = serverPrice;
                } else {
                    hasValidBasePrice = false;
                }
                updateLivePriceDisplay();
            });
        }

        // Initial execution
        repositionMasterBox();
        syncResetButton();
        updateLivePriceDisplay();

        setTimeout(function () {
            repositionMasterBox();
            syncResetButton();
            updateLivePriceDisplay();
        }, 120);
    });
})(jQuery);
