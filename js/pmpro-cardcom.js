var eventMethod = window.addEventListener ? "addEventListener" : "attachEvent";
var eventer = window[eventMethod];
var messageEvent = eventMethod == "attachEvent" ? "onmessage" : "message";
var creditCardCollected = false;

// Listen for a message from the iframe.
eventer(messageEvent, function (e) {
	const allowedOrigins = ['https://secure.cardcom.solutions', 'https://cardcom.solutions'];
	if (!allowedOrigins.includes(e.origin) || (e.data.source === 'react-devtools-content-script')) {
		return;
	}
	console.log('Received valid Cardcom message from iframe:', e.data, 'Origin:', e.origin);
	if (e.data.message === 'paymentreplay') {
		let resp = e.data.value;
		if (resp && !isNaN(resp.ResponseCode)) {
			if (resp.ResponseCode != 0 || (resp.OperationResponse && resp.OperationResponse != 0)) {
				jQuery('#pmpro_message').text(resp.Description || 'Payment error occurred').addClass('pmpro_error').removeClass('pmpro_alert').removeClass('pmpro_success').show();
				jQuery('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
				jQuery('#cardcom_payment_popup').modal('hide');
			} else {
				creditCardCollected = true;
				let form = jQuery('#pmpro_form, .pmpro_form');
				form.append('<input type="hidden" name="payment_intent_id" value="' + (resp.InternalDealNumber || '') + '" />');
				form.append('<input type="hidden" name="setup_intent_id" value="' + (resp.InternalDealNumber || '') + '" />');
				form.append('<input type="hidden" name="subscription_id" value="' + (resp.InternalDealNumber || '') + '" />');
				form.append('<input type="hidden" name="cardcom_token" value="' + (resp.Token || '') + '" />');
				form.append('<input type="hidden" name="AccountNumber" value="XXXXXXXXXXXX' + (resp.Last4Digits || '') + '"/>');
				form.append('<input type="hidden" name="ExpirationMonth" value="' + (('0' + (resp.CardTokenExpirationMonth || '')).slice(-2)) + '"/>');
				form.append('<input type="hidden" name="ExpirationYear" value="' + (resp.CardTokenExpirationYear || '') + '"/>');
				if (resp.InternalDealNumber) {
					form.get(0).submit();
				} else {
					jQuery('#pmpro_message').text('Failed to initiate payment: Missing InternalDealNumber').addClass('pmpro_error').show();
					jQuery('#cardcom_payment_popup').modal('hide');
					jQuery('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
				}
			}
		}
	}
}, false);

jQuery(document).ready(function ($) {
	if (typeof pmpro_require_billing === 'undefined') {
		pmpro_require_billing = pmproCardcomVars.data.pmpro_require_billing;
	}

	$('#cardcom_payment_popup').on('hidden.bs.modal', function () {
		$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
	});

	var readyToprocess = $('#readyToProcessByCardcom').val();
	var orderCode = $('#orderCode').val();
	if (readyToprocess && orderCode) {
		$('#pmpro_message').text(pmproCardcomVars.data.messages.processing).removeClass('pmpro_error').removeClass('pmpro_alert').addClass('pmpro_success');
		$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').attr('disabled', 'disabled');
		var redirectAddress = pmproCardcomVars.data.redirect_url + '?DealNum=' + orderCode;
		if (redirectAddress) {
			$('#wc_cardcom_iframe').attr('src', redirectAddress);
			$('#cardcom_payment_popup').modal('show');
			console.log('Iframe src set to:', redirectAddress);
		} else {
			$('#pmpro_message').text(pmproCardcomVars.data.messages.error).addClass('pmpro_error').removeClass('pmpro_alert').removeClass('pmpro_success').show();
			$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
		}
	}

	$('.pmpro_form').submit(function (event) {
		if ($('input[name=gateway]:checked').val() != 'paypalexpress' && pmpro_require_billing === true) {
			processCardcom();
			event.preventDefault();
		}
	});

	function processCardcom() {
		var name = $('#bfirstname').val();
		if ($('#bfirstname').length && $('#blastname').length) {
			name = $.trim($('#bfirstname').val() + ' ' + $('#blastname').val());
		}
		var customer = {
			'CustomerFullName': name,
			'FirstName': $.trim($('#bfirstname').val()),
			'LastName': $.trim($('#blastname').val()),
			'Email': $('#bemail').val(),
			'PhoneNumber': $('#bphone').val()
		};
		var dealType = 6;

		var paymentRequest = {
			"DealType": dealType,
			"CustomerFullName": name,
			"City": $('#bcity').val(),
			"Country": $('#bcountry').val(),
			"Customer": customer,
			"DisplayType": "iframe",
			"PostProcessMethod": 1,
			"FormData": $(".pmpro_form").serialize()
		};

		jQuery.ajax({
			url: pmproCardcomVars.data.url,
			type: "post",
			dataType: "json",
			data: {
				action: pmproCardcomVars.data.action,
				nonce: pmproCardcomVars.data.nonce,
				req: paymentRequest // Отправляем объект, не строку
			},
			success: function (apiresponse) {
				console.log('API response (raw):', apiresponse);
				if (!apiresponse.success) {
					$('#pmpro_message').text(apiresponse.data || 'Payment initiation failed').addClass('pmpro_error').show();
					$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
					return;
				}
				const data = apiresponse.data;
				if (!data || !data.InternalDealNumber) {
					$('#pmpro_message').text('Failed to initiate payment: Missing InternalDealNumber').addClass('pmpro_error').show();
					$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
					return;
				}
				if (data.ResponseCode != 0) {
					$('#pmpro_message').text(data.Description || 'Failed to initiate payment').addClass('pmpro_error').show();
					$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
				} else {
					$('#wc_cardcom_iframe').attr('src', pmproCardcomVars.data.redirect_url + '?DealNum=' + data.InternalDealNumber);
					$('#cardcom_payment_popup').modal('show');
					setTimeout(function () {
						if (!creditCardCollected) {
							$('#pmpro_message').text('Timeout: Payment processing failed').addClass('pmpro_error').show();
							$('#cardcom_payment_popup').modal('hide');
							$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
						}
					}, 30000);
				}
			},
			error: function (request, status, error) {
				console.log('API error:', request.responseText, status, error);
				$('#pmpro_message').text(request.responseText || 'Error connecting to payment gateway').addClass('pmpro_error').removeClass('pmpro_alert').removeClass('pmpro_success').show();
				$('.pmpro_btn-submit-checkout,.pmpro_btn-submit').removeAttr('disabled');
			}
		});
	}
});