<?php
// Add at the top of class.pmprogateway_cardcom.php
require_once PMPRO_CARDCOMGATEWAY_DIR . '/includes/cardcom_logger.php';

// Require the default PMPro Gateway Class.
require_once PMPRO_DIR . '/classes/gateways/class.pmprogateway.php';

// Load classes init method
add_action('init', array('PMProGateway_cardcom', 'cardcom_init'));

class PMProGateway_cardcom extends PMProGateway
{
    function __construct($gateway = null)
    {
        $this->gateway = $gateway ? $gateway : 'cardcom';
        $this->gateway_environment = pmpro_getOption("gateway_environment");
    }

    /**
     * Run on WP init
     */
    static function cardcom_init()
    {
        add_filter('pmpro_gateways', array('PMProGateway_cardcom', 'cardcom_pmpro_gateways'));
        add_filter('pmpro_payment_options', array('PMProGateway_cardcom', 'cardcom_pmpro_payment_options'));
        // Prevent duplicate filter application
        if (!has_filter('pmpro_payment_option_fields', array('PMProGateway_cardcom', 'cardcom_pmpro_payment_option_fields'))) {
            add_filter('pmpro_payment_option_fields', array('PMProGateway_cardcom', 'cardcom_pmpro_payment_option_fields'), 10, 2);
        }

        $gateway = pmpro_getOption("gateway");
        if ($gateway == "cardcom") {
            add_filter('pmpro_include_payment_information_fields', '__return_false');
            add_filter('pmpro_required_billing_fields', array('PMProGateway_cardcom', 'cardcom_pmpro_required_billing_fields'));
            add_action('pmpro_checkout_after_form', array('PMProGateway_cardcom', 'cardcom_pmpro_checkout_preheader'), 10);
            add_action('pmpro_checkout_after_form', array('PMProGateway_cardcom', 'cardcom_pmpro_checkout_after_form'), 11);
        }
        add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_cardcom', 'cardcom_pmpro_checkout_before_change_membership_level'), 11, 2);

        add_action('wp_ajax_nopriv_cardcom_ipn_handler', array('PMProGateway_cardcom', 'cardcom_wp_ajax_ipn_handler'));
        add_action('wp_ajax_cardcom_ipn_handler', array('PMProGateway_cardcom', 'cardcom_wp_ajax_ipn_handler'));

        add_action('wp_ajax_nopriv_cardcom_get_redirect', array('PMProGateway_cardcom', 'cardcom_wp_ajax_get_redirect'));
        add_action('wp_ajax_cardcom_get_redirect', array('PMProGateway_cardcom', 'cardcom_wp_ajax_get_redirect'));

        add_filter('pmpro_gateways_with_pending_status', array('PMProGateway_cardcom', 'cardcom_pmpro_gateways_with_pending_status'));

        add_action('pmpro_save_settings', array('PMProGateway_cardcom', 'cardcom_save_settings'));
    }

    /**
     * Handle Cardcom webhook
     */
    static function cardcom_wp_ajax_ipn_handler()
    {
        error_log('[' . date('Y-m-d H:i:s') . '] Cardcom webhook received: ' . print_r($_REQUEST, true) . "\n", 3, CARDCOM_LOG_FILE);
        PmPro_Cardcom_Logger::log("Webhook handler triggered: " . print_r($_REQUEST, true));
        $response = array('status' => 'success');
        if (isset($_REQUEST['ResponseCode']) && $_REQUEST['ResponseCode'] != 0) {
            $response['status'] = 'error';
            $response['message'] = $_REQUEST['Description'] ?? 'Unknown error';
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom webhook error: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
        }
        wp_send_json($response);
        exit;
    }

    /**
     * Get redirect URL for Cardcom payment
     */
    static function cardcom_wp_ajax_get_redirect()
    {
        global $pmpro_currency;

        try {
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            $gateway = pmpro_getOption("gateway");
            if (!$gateway) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Gateway not set' . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('Gateway not configured');
                exit;
            }
            if (!wp_verify_nonce($nonce, 'ajax-nonce' . $gateway)) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Invalid nonce: ' . $nonce . ' for gateway: ' . $gateway . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('Invalid nonce');
                exit;
            }

            PmPro_Cardcom_Logger::log("cardcom_wp_ajax_get_redirect called at " . current_time('mysql') . " :: POST: " . print_r($_POST, true));
            $terminal_number = pmpro_getOption("cardcom_terminal_number");
            $username = pmpro_getOption("cardcom_username");
            $password = pmpro_getOption("cardcom_password");
            if (!$terminal_number || !$username || !$password) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Missing credentials' . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('Missing TerminalNumber, Username, or Password');
                exit;
            }

            $request_data = isset($_POST['req']) ? $_POST['req'] : [];
            if (is_string($request_data)) {
                $decoded = json_decode($request_data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request_data = $decoded;
                } else {
                    parse_str($request_data, $request_data);
                }
            }

            if (isset($_POST['req']['Customer']['FirstName']) && empty($_POST['req']['Customer']['FirstName'])) {
                parse_str($_POST['req']['FormData'], $form_data);
                $request_data['Customer']['FirstName'] = $form_data['first_name'] ?? '';
                $request_data['Customer']['LastName'] = $form_data['last_name'] ?? '';
            }

            if (!is_array($request_data)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom: Invalid request_data format: " . print_r($request_data, true) . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('Invalid request data format');
                exit;
            }

            parse_str($request_data['FormData'], $form_data);
            $order_id = isset($form_data['order']) ? $form_data['order'] : (isset($_REQUEST['order']) ? $_REQUEST['order'] : null);

            $morder = new MemberOrder($order_id);
            if (!$morder->id) {
                $morder = new MemberOrder();
                $morder->user_id = get_current_user_id();
                $morder->membership_id = intval($form_data['pmpro_level'] ?? 0);
                $membership_level = pmpro_getLevel($morder->membership_id);
                $morder->subtotal = $membership_level->initial_payment ?? 0;
                $morder->tax = $morder->getTaxForPrice($morder->subtotal);
                $morder->total = $morder->subtotal + $morder->tax;
                $morder->gateway = 'cardcom';
                $morder->gateway_environment = pmpro_getOption("gateway_environment");
                $morder->code = $morder->getRandomCode();
                $morder->status = 'pending';
                $morder->saveOrder();
            }

            if (!$morder || !$morder->id) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: No valid order found' . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('No valid order found');
                exit;
            }

            $amount = $morder->total ?: 0;
            $user = get_userdata($morder->user_id);
            $email = $user ? $user->user_email : '';
            $first_name = $request_data['Customer']['FirstName'] ?: ($morder->billing->first_name ?? $form_data['first_name']);
            $last_name = $request_data['Customer']['LastName'] ?: ($morder->billing->last_name ?? $form_data['last_name']);
            $card_holder_name = trim($first_name . ' ' . $last_name);

            $scheme = (is_ssl() || strpos(home_url(), 'https') !== false) ? 'https' : 'http';
            if ($scheme === 'http') {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom Warning: HTTPS is not supported, using HTTP' . "\n", 3, CARDCOM_LOG_FILE);
                // wp_send_json_error('HTTPS is required for Cardcom payments');
                // exit;
            }

            $request_data['TerminalNumber'] = $terminal_number;
            $request_data['UserName'] = $username;
            $request_data['Password'] = $password;
            $request_data['Language'] = 'he';
            $request_data['SumToBill'] = $amount;
            $request_data['CoinId'] = 1;
            $request_data['SuccessRedirectUrl'] = home_url('/payment-success', $scheme);
            $request_data['ErrorRedirectUrl'] = home_url('/payment-cancel', $scheme);
            $request_data['IndicatorUrl'] = home_url('/cardcom-callback', $scheme);
            $request_data['Operation'] = 1;
            $request_data['MaxNumOfPayments'] = 12;
            $request_data['APILevel'] = 10;
            $request_data['Codepage'] = 65001;
            $request_data['ProductName'] = 'Order_' . $morder->code;
            $request_data['DealNum'] = $morder->code ?: uniqid('order_');
            $request_data['TimeStamp'] = date('YmdHis');
            $request_data['CardHolderName'] = $card_holder_name;
            $request_data['InternalDealNumber'] = uniqid('intdeal_');
            $request_data['CustomerEmail'] = $email;
            $request_data['CustomerPhone'] = $form_data['user_phone_number'] ?: ($morder->billing->phone ?: '');

            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom Final Request: ' . print_r($request_data, true) . "\n", 3, CARDCOM_LOG_FILE);

            $response = Cardcom_API::get_request($request_data);

            if ($response === false || !isset($response['body'])) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: API request failed, response: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('API request failed');
                exit;
            }

            $body = $response['body'];
            parse_str($body, $body_array);
            if (isset($body_array['ResponseCode']) && $body_array['ResponseCode'] != 0) {
                error_log('Cardcom redirect error: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error($body_array['Description'] ?? 'Redirect error');
                exit;
            }

            $transaction_id = $body_array['InternalDealNumber'] ?? $body_array['LowProfileCode'] ?? $request_data['InternalDealNumber'];
            if (empty($transaction_id)) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Missing transaction ID in response: ' . print_r($body_array, true) . "\n", 3, CARDCOM_LOG_FILE);
                wp_send_json_error('Failed to initiate payment: Missing InternalDealNumber');
                exit;
            }

            $morder->payment_transaction_id = $transaction_id;
            $morder->subscription_transaction_id = $transaction_id;
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Saving transaction ID: ' . $transaction_id . "\n", 3, CARDCOM_LOG_FILE);
            $morder->saveOrder();

            wp_send_json_success($body_array);
        } catch (Exception $e) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Fatal error: ' . $e->getMessage() . "\n", 3, CARDCOM_LOG_FILE);
            wp_send_json_error('Internal server error: ' . $e->getMessage());
        }
    }

    /**
     * Filtering orders at checkout
     */
    static function cardcom_pmpro_checkout_order($morder)
    {
        return $morder;
    }

    /**
     * Add Cardcom to gateways list
     */
    static function cardcom_pmpro_gateways($gateways)
    {
        if (empty($gateways['cardcom'])) {
            $gateways['cardcom'] = __('Cardcom', 'pmpro-cardcom');
        }
        return $gateways;
    }

    /**
     * Add Cardcom to pending status gateways
     */
    static function cardcom_pmpro_gateways_with_pending_status($gateways)
    {
        $gateways[] = 'cardcom';
        return $gateways;
    }

    /**
     * Get payment options for Cardcom
     */
    static function cardcom_getGatewayOptions()
    {
        return array(
            'cardcom_terminal_number',
            'cardcom_username',
            'cardcom_password',
            'cardcom_create_invoice',
            'cardcom_is_taxtable',
            'cardcom_is_sub_date_same_as_charge',
            'cardcom_delay_to_next_period_after_initial_payment',
            'cardcom_display_type',
            'cardcom_logging',
            'cardcom_api_version',
        );
    }

    /**
     * Set payment options
     */
    static function cardcom_pmpro_payment_options($options)
    {
        $cardcom_options = self::cardcom_getGatewayOptions();
        return array_merge($cardcom_options, $options);
    }

    /**
     * Display fields for Cardcom options
     */
    static function cardcom_pmpro_payment_option_fields($values, $gateway)
    {
        // Prevent rendering multiple times
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        // Log the current values passed to the function
        error_log('[' . date('Y-m-d H:i:s') . '] Settings values: ' . print_r($values, true) . "\n", 3, CARDCOM_LOG_FILE);

        // Get all Cardcom options to retrieve their saved values from the database
        $cardcom_options = self::cardcom_getGatewayOptions();
        $saved_values = array();

        // Retrieve saved values from the database
        foreach ($cardcom_options as $option) {
            $value = pmpro_getOption($option);

            if ($option === 'cardcom_password') {
                $saved_values[$option] = '********';
            } else {
                $saved_values[$option] = $value;
            }
        }

        // Log the saved values from the database
        error_log('[' . date('Y-m-d H:i:s') . '] Saved values from database: ' . print_r($saved_values, true) . "\n", 3, CARDCOM_LOG_FILE);

        ?>
        <tr class="pmpro_settings_divider gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <td colspan="2">
                <hr />
                <h2 class="title"><?php esc_html_e('Cardcom Settings', 'pmpro-cardcom'); ?></h2>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_terminal_number"><?php _e('Terminal Number', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <input type="text" id="cardcom_terminal_number" name="cardcom_terminal_number" value="<?php echo esc_attr($values['cardcom_terminal_number']); ?>" class="regular-text code" />
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_username"><?php _e('Username', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <input type="text" id="cardcom_username" name="cardcom_username" value="<?php echo esc_attr($values['cardcom_username']); ?>" class="regular-text code" />
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_password"><?php _e('Password', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <input type="password" id="cardcom_password" name="cardcom_password" value="<?php echo esc_attr($values['cardcom_password']); ?>" class="regular-text code" />
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_is_sub_date_same_as_charge"><?php _e('Set subscription date same as charge date', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_is_sub_date_same_as_charge" name="cardcom_is_sub_date_same_as_charge">
                    <option value="0" <?php selected(empty($values['cardcom_is_sub_date_same_as_charge'])); ?>><?php _e('No', 'pmpro-cardcom'); ?></option>
                    <option value="1" <?php selected(!empty($values['cardcom_is_sub_date_same_as_charge'])); ?>><?php _e('Yes', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_delay_to_next_period_after_initial_payment"><?php _e('Delay payment to next period after initial payment', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_delay_to_next_period_after_initial_payment" name="cardcom_delay_to_next_period_after_initial_payment">
                    <option value="0" <?php selected(empty($values['cardcom_delay_to_next_period_after_initial_payment'])); ?>><?php _e('No', 'pmpro-cardcom'); ?></option>
                    <option value="1" <?php selected(!empty($values['cardcom_delay_to_next_period_after_initial_payment'])); ?>><?php _e('Yes', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_display_type"><?php _e('Payment fields display type', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_display_type" name="cardcom_display_type">
                    <option value="1" <?php selected($values['cardcom_display_type'], 1); ?>><?php _e('Popup', 'pmpro-cardcom'); ?></option>
                    <option value="2" <?php selected($values['cardcom_display_type'], 2); ?>><?php _e('Redirect', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_create_invoice"><?php _e('Create invoice', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_create_invoice" name="cardcom_create_invoice">
                    <option value="0" <?php selected(empty($values['cardcom_create_invoice'])); ?>><?php _e('No', 'pmpro-cardcom'); ?></option>
                    <option value="1" <?php selected(!empty($values['cardcom_create_invoice'])); ?>><?php _e('Yes', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_is_taxtable"><?php _e('Order include TAX', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_is_taxtable" name="cardcom_is_taxtable">
                    <option value="0" <?php selected(empty($values['cardcom_is_taxtable'])); ?>><?php _e('No', 'pmpro-cardcom'); ?></option>
                    <option value="1" <?php selected(!empty($values['cardcom_is_taxtable'])); ?>><?php _e('Yes', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_logging"><?php _e('Enable debug log', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_logging" name="cardcom_logging">
                    <option value="0" <?php selected(empty($values['cardcom_logging'])); ?>><?php _e('No', 'pmpro-cardcom'); ?></option>
                    <option value="1" <?php selected(!empty($values['cardcom_logging'])); ?>><?php _e('Yes', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="gateway gateway_cardcom" <?php if ($gateway != "cardcom") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="cardcom_api_version"><?php _e('API Version', 'pmpro-cardcom'); ?>:</label>
            </th>
            <td>
                <select id="cardcom_api_version" name="cardcom_api_version">
                    <option value="v11" <?php selected($values['cardcom_api_version'] ?? 'v11', 'v11'); ?>><?php _e('JSON API v11', 'pmpro-cardcom'); ?></option>
                </select>
            </td>
        </tr>
        <?php
    }

    /**
     * Include billing address fields
     */
    static function cardcom_pmpro_include_billing_address_fields($include)
    {
        return $include;
    }

    /**
     * Remove required billing fields
     */
    static function cardcom_pmpro_required_billing_fields($fields)
    {
        unset($fields['bstate']);
        unset($fields['bcountry']);
        unset($fields['baddress1']);
        unset($fields['bemail']);
        unset($fields['CardType']);
        unset($fields['AccountNumber']);
        unset($fields['ExpirationMonth']);
        unset($fields['ExpirationYear']);
        unset($fields['CVV']);
        return $fields;
    }

    /**
     * Checkout confirmed
     */
    static function cardcom_pmpro_checkout_confirmed($pmpro_confirmed)
    {
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: pmpro_checkout_confirmed' . "\n", 3, CARDCOM_LOG_FILE);
        }
    }

    /**
     * Before membership level change
     */
    static function cardcom_pmpro_checkout_before_change_membership_level($user_id, $morder)
    {
        global $wpdb;
        if (empty($morder) || $morder->gateway != 'cardcom') {
            return;
        }
        $morder->user_id = $user_id;
        $morder->saveOrder();
        do_action("pmpro_before_send_to_cardcom", $user_id, $morder);
        $display_type = pmpro_getOption("cardcom_display_type");
        if ($display_type == 1) {
            return;
        }
        $endpoint = $morder->Gateway->cardcom_sendToCardcom($morder);
        wp_redirect($endpoint);
        exit;
    }

    /**
     * Prepare data for Cardcom
     */
    function cardcom_getDataToSend($order)
    {
        global $pmpro_currency, $user;
        $terminal_number = pmpro_getOption("cardcom_terminal_number");
        $username = pmpro_getOption("cardcom_username");
        $password = pmpro_getOption("cardcom_password");
        $api_version = pmpro_getOption("cardcom_api_version") ?? 'v11';
        $this->create_document = pmpro_getOption("cardcom_create_invoice") == 1;
        $this->is_taxtable = pmpro_getOption("cardcom_is_taxtable");
        $is_sub_date_same_as_charge = pmpro_getOption("cardcom_is_sub_date_same_as_charge") == 1;
        $delay_to_next_period_after_initial_payment = pmpro_getOption("cardcom_delay_to_next_period_after_initial_payment") == 1;
        $display_type = pmpro_getOption("cardcom_display_type");

        if (empty($order->code)) {
            $order->code = $order->getRandomCode();
        }
        $order->status = 'pending';
        $order->payment_transaction_id = $order->code;
        $order->subscription_transaction_id = $order->code;
        $order->saveOrder();

        $first_name = sanitize_text_field(pmpro_getParam('bfirstname', 'REQUEST'));
        $last_name = sanitize_text_field(pmpro_getParam('blastname', 'REQUEST'));
        $baddress1 = sanitize_text_field(pmpro_getParam('baddress1', 'REQUEST'));
        $baddress2 = sanitize_text_field(pmpro_getParam('baddress2', 'REQUEST'));
        $bcity = sanitize_text_field(pmpro_getParam('bcity', 'REQUEST'));
        $bstate = sanitize_text_field(pmpro_getParam('bstate', 'REQUEST'));
        $bzipcode = sanitize_text_field(pmpro_getParam('bzipcode', 'REQUEST'));
        $bcountry = sanitize_text_field(pmpro_getParam('bcountry', 'REQUEST'));
        $bphone = sanitize_text_field(pmpro_getParam('bphone', 'REQUEST'));
        $bemail = sanitize_email(pmpro_getParam('bemail', 'REQUEST'));
        $name = trim($first_name . ' ' . $last_name);

        $email = $order->Email ?: ($user->ID && $user->user_email ? $user->user_email : ($bemail ? $bemail : ''));

        $amount = $order->PaymentAmount;
        $amount_tax = $order->getTaxForPrice($amount);
        $amount = pmpro_round_price((float)$amount + (float)$amount_tax);

        $cardcom_data = array(
            'TerminalNumber' => $terminal_number,
            'UserName' => $username,
            'Password' => $password,
            'Operation' => 'CreateInvoice',
            'InvoiceAmount' => $amount,
            'InvoiceCurrency' => $pmpro_currency,
            'InvoiceDescription' => $order->membership_level->name,
            'CustomerName' => $name,
            'CustomerEmail' => $email,
            'CustomerPhone' => $order->billing->phone,
            'ReturnValue' => $order->code,
            'SuccessRedirectUrl' => esc_url_raw(add_query_arg('level', $order->membership_level->id, pmpro_url("confirmation"))),
            'FailedRedirectUrl' => esc_url_raw(pmpro_url("levels")),
            'CancelUrl' => esc_url_raw(pmpro_url("levels")),
            'IndicatorUrl' => esc_url_raw(add_query_arg('action', 'cardcom_ipn_handler', admin_url('admin-ajax.php'))),
            'Language' => get_locale(),
            'ApiVersion' => $api_version,
        );

        if ($this->create_document) {
            $cardcom_data['CreateInvoice'] = 'true';
        }
        if ($this->is_taxtable) {
            $cardcom_data['IsTaxable'] = 'true';
            $cardcom_data['TaxAmount'] = $amount_tax;
        }
        $cardcom_data['DisplayType'] = $display_type == 1 ? 'iframe' : 'redirect';

        if ($order->membership_level->billing_amount > 0) {
            $cardcom_data['Operation'] = 'TokenAndCharge';
            $cardcom_data['NumberOfPayments'] = $order->membership_level->billing_limit ?: 0;
            $cardcom_data['RecurringPayment'] = 'true';
            $cardcom_data['RecurringAmount'] = $order->membership_level->billing_amount;

            $interval = $order->BillingFrequency;
            switch ($order->BillingPeriod) {
                case 'Day':
                    $cardcom_data['RecurringPeriod'] = 'Daily';
                    $recurring_date = date('d/m/Y', strtotime('+' . $interval . ' day'));
                    break;
                case 'Week':
                    $cardcom_data['RecurringPeriod'] = 'Weekly';
                    $recurring_date = date('d/m/Y', strtotime('+7 day'));
                    break;
                case 'Month':
                    $cardcom_data['RecurringPeriod'] = 'Monthly';
                    $recurring_date = date('d/m/Y', mktime(0, 0, 0, date('m'), $is_sub_date_same_as_charge ? date('j') : $interval, date('Y')));
                    break;
                case 'Year':
                    $cardcom_data['RecurringPeriod'] = 'Yearly';
                    $recurring_date = date('d/m/Y', strtotime('+1 year'));
                    break;
            }
            $cardcom_data['RecurringStartDate'] = $recurring_date;

            if ($order->membership_level->trial_limit) {
                $cardcom_data['InitialAmount'] = $order->membership_level->trial_amount;
                $this->cardcom_schedule_subscription_update($order->membership_level->trial_limit, $order->membership_level->cycle_period, $order->id);
            }
            if ($order->membership_level->expiration_number && $order->membership_level->expiration_period) {
                $cardcom_data['RecurringEndDate'] = date('d/m/Y', strtotime("+{$order->membership_level->expiration_number} {$order->membership_level->expiration_period}", current_time('timestamp')));
            }
            if (function_exists('pmprosd_getDelay') && $order->discount_code) {
                global $wpdb;
                $code_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = %s LIMIT 1", $order->discount_code));
                $subscription_delay = pmprosd_getDelay($order->membership_id, $code_id) ?: pmprosd_getDelay($order->membership_id);
                if ($subscription_delay) {
                    $cardcom_data['RecurringStartDate'] = date('d/m/Y', strtotime("+{$subscription_delay} days", current_time('timestamp')));
                    if ($is_sub_date_same_as_charge && $order->BillingPeriod == 'Month') {
                        $cardcom_data['RecurringDay'] = date('j', strtotime("+{$subscription_delay} days", current_time('timestamp')));
                    }
                }
            } elseif ($delay_to_next_period_after_initial_payment && $order->InitialPayment > 0) {
                $cardcom_data['RecurringStartDate'] = date('d/m/Y', strtotime("+{$order->BillingFrequency} {$order->BillingPeriod}", current_time('timestamp')));
            }
        } else {
            $cardcom_data['Items'] = array(
                array(
                    'Description' => substr(strip_tags($order->membership_level->name), 0, 200),
                    'Price' => $order->InitialPayment,
                    'Quantity' => 1,
                )
            );
        }
        return $cardcom_data;
    }

    /**
     * Send to Cardcom
     */
    function cardcom_sendToCardcom(&$order)
    {
        try {
            $terminal_number = pmpro_getOption("cardcom_terminal_number");
            $username = pmpro_getOption("cardcom_username");
            $password = pmpro_getOption("cardcom_password");
            if (!$terminal_number || !$username || !$password) {
                throw new Exception('Missing TerminalNumber, Username, or Password');
            }
            $cardcom_data = $this->cardcom_getDataToSend($order);
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom data sent: ' . print_r($cardcom_data, true) . "\n", 3, CARDCOM_LOG_FILE);
            $response = Cardcom_API::get_request($cardcom_data);
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom response: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
            if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] != 0) {
                if (pmpro_getOption('cardcom_logging')) {
                    error_log('[' . date('Y-m-d H:i:s') . '] Cardcom error: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
                }
                throw new Exception($response['body']['Description'] ?? 'Payment processing failed');
            }
            $endpoint = Cardcom_API::get_redirect_order_api() . '?' . http_build_query($cardcom_data);
            error_log('[' . date('Y-m-d H:i:s') . '] Generated endpoint: ' . $endpoint . "\n", 3, CARDCOM_LOG_FILE);
            return $endpoint;
        } catch (Exception $e) {
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom sendToCardcom error: ' . $e->getMessage() . "\n", 3, CARDCOM_LOG_FILE);
            }
            pmpro_setMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Checkout preheader
     */
    static function cardcom_pmpro_checkout_preheader()
    {
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: pmpro_checkout_preheader init' . "\n", 3, CARDCOM_LOG_FILE);
        }
        global $gateway, $pmpro_level, $pmpro_requirebilling;
        $default_gateway = pmpro_getOption("gateway");
        $display_type = pmpro_getOption("cardcom_display_type");
        if ($display_type != 1 || ($gateway != "cardcom" && $default_gateway != "cardcom")) {
            return;
        }

        wp_register_script('cardcom_bootstrap', '//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js');
        wp_enqueue_script('cardcom_bootstrap');
        wp_register_style('cardcom_bootstrap', plugin_dir_url(__DIR__) . '/css/modal.css', array(), time());
        wp_enqueue_style('cardcom_bootstrap');

        $localize_vars = array(
            'data' => array(
                'url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ajax-nonce' . pmpro_getOption("gateway")),
                'action' => 'cardcom_get_redirect',
                'pmpro_require_billing' => $pmpro_requirebilling,
                'redirect_url' => Cardcom_API::get_redirect_order_api() . '?',
                'order_reference' => wp_create_nonce('cardcom_order_ref' . $pmpro_level->id),
                'messages' => array(
                    'processing' => __('Processing......', 'pmpro-cardcom'),
                    'error' => __('ERROR', 'pmpro-cardcom')
                )
            )
        );

        wp_register_script('pmpro_cardcom', plugin_dir_url(__DIR__) . '/js/pmpro-cardcom.js', array('jquery'), time());
        wp_localize_script('pmpro_cardcom', 'pmproCardcomVars', $localize_vars);
        wp_enqueue_script('pmpro_cardcom');
    }

    /**
     * Process payment
     */
    function cardcom_process(&$order)
    {
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: process Order hit: ' . print_r($order, true) . "\n", 3, CARDCOM_LOG_FILE);
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: process _REQUEST: ' . print_r($_REQUEST, true) . "\n", 3, CARDCOM_LOG_FILE);
        }
        try {
            if (empty($order->code)) {
                $order->code = $order->getRandomCode();
            }
            $order->payment_type = "Cardcom";
            $order->status = "pending";
            $cardcom_token = !empty($_REQUEST['cardcom_token']) ? $_REQUEST['cardcom_token'] : '';
            if ($cardcom_token) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Token received: ' . $cardcom_token . "\n", 3, CARDCOM_LOG_FILE);
                if (!preg_match('/^[a-f0-9]{32}$/', $cardcom_token)) {
                    throw new Exception('Invalid token format');
                }
            }

            $order->saveOrder();
            $display_type = pmpro_getOption("cardcom_display_type");

            if ($cardcom_token && $display_type == 1) {
                $terminal_number = pmpro_getOption("cardcom_terminal_number");
                $username = pmpro_getOption("cardcom_username");
                $password = pmpro_getOption("cardcom_password");
                if (!$terminal_number || !$username || !$password) {
                    throw new Exception('Missing TerminalNumber, Username, or Password');
                }
                $cardcom_data = $this->cardcom_getDataToSend($order);
                $cardcom_data['Token'] = $cardcom_token;
                $response = Cardcom_API::request($cardcom_data, '?Operation=TokenAndCharge');

                // Log full API response
                if (pmpro_getOption('cardcom_logging')) {
                    error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: API response in process: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
                }

                if (empty($response['body'])) {
                    throw new Exception('Empty response from Cardcom API');
                }

                if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] != 0) {
                    throw new Exception($response['body']['Description'] ?? 'Payment failed with ResponseCode: ' . $response['body']['ResponseCode']);
                }

                if (empty($response['body']['InternalDealNumber'])) {
                    throw new Exception('Missing InternalDealNumber in Cardcom API response');
                }

                $order->payment_transaction_id = $response['body']['InternalDealNumber'];
                $order->subscription_transaction_id = $response['body']['InternalDealNumber'];
                $order->status = 'success';
                $order->saveOrder();
                return true;
            }
            return true;
        } catch (Exception $e) {
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom process error: ' . $e->getMessage() . "\n", 3, CARDCOM_LOG_FILE);
            }
            pmpro_setMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Checkout after form (iframe for popup)
     */
    static function cardcom_pmpro_checkout_after_form()
    {
        $display_type = pmpro_getOption("cardcom_display_type");
        if ($display_type != 1) {
            return;
        }
        echo '
        <div class="modal fade" id="cardcom_payment_popup" tabindex="-1" role="dialog" data-backdrop="false">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-body">
                        <iframe id="cardcom_wc_iframe" name="cardcom_wc_iframe" width="100%" height="600px" style="border: 0;"></iframe>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">' . __('Close', 'pmpro-cardcom') . '</button>
                    </div>
                </div>
            </div>
        </div>';
    }

    /**
     * Cancel order
     */
    function cardcom_cancel(&$order)
    {
        global $wpdb;
        try {
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel Order hit' . "\n", 3, CARDCOM_LOG_FILE);
            }
            if (empty($order->payment_transaction_id)) {
                return false;
            }
            $uniqId = $wpdb->get_var($wpdb->prepare("SELECT payment_transaction_id FROM $wpdb->pmpro_membership_orders WHERE payment_transaction_id = %s ORDER BY id DESC LIMIT 1", $order->payment_transaction_id));
            do_action("hook_before_subscription_cancel_cardcom", $order);

            if (!empty($_POST['payment_status']) && $_POST['payment_status'] == 'CANCELLED') {
                return true;
            }

            $terminal_number = pmpro_getOption("cardcom_terminal_number");
            $username = pmpro_getOption("cardcom_username");
            $password = pmpro_getOption("cardcom_password");
            if (!$terminal_number || !$username || !$password) {
                throw new Exception('Missing TerminalNumber, Username, or Password');
            }
            $response = Cardcom_API::request(array('TerminalNumber' => $terminal_number, 'UserName' => $username, 'Password' => $password, 'DealNumber' => $uniqId), '?Operation=CancelDeal');
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel process: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
            }

            if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] != 0) {
                throw new Exception($response['body']['Description'] ?? 'Cancel failed');
            }
            $order->updateStatus('cancelled');
            return true;
        } catch (Exception $e) {
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel Order Exception:' . $e->getMessage() . "\n", 3, CARDCOM_LOG_FILE);
            }
            pmpro_setMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Cancel subscription
     */
    function cardcom_cancel_subscription($subscription)
    {
        global $wpdb;
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel_subscription hit: ' . print_r($subscription, true) . "\n", 3, CARDCOM_LOG_FILE);
        }
        try {
            $uniqId = $wpdb->get_var($wpdb->prepare("SELECT payment_transaction_id FROM $wpdb->pmpro_membership_orders WHERE subscription_transaction_id = %s ORDER BY id DESC LIMIT 1", $subscription->get_subscription_transaction_id()));
            do_action("hook_before_subscription_cancel_cardcom", $subscription);

            $terminal_number = pmpro_getOption("cardcom_terminal_number");
            $username = pmpro_getOption("cardcom_username");
            $password = pmpro_getOption("cardcom_password");
            if (!$terminal_number || !$username || !$password) {
                throw new Exception('Missing TerminalNumber, Username, or Password');
            }
            $response = Cardcom_API::request(array('TerminalNumber' => $terminal_number, 'UserName' => $username, 'Password' => $password, 'DealNumber' => $uniqId), '?Operation=CancelDeal');
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel subscription process:' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
            }

            if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] != 0) {
                throw new Exception($response['body']['Description'] ?? 'Cancel subscription failed');
            }
            return true;
        } catch (Exception $e) {
            if (pmpro_getOption('cardcom_logging')) {
                error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: cancel_subscription Exception: ' . $e->getMessage() . "\n", 3, CARDCOM_LOG_FILE);
            }
            pmpro_setMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get subscription status
     */
    function cardcom_getSubscriptionStatus(&$order)
    {
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: getSubscriptionStatus' . "\n", 3, CARDCOM_LOG_FILE);
        }
        $terminal_number = pmpro_getOption("cardcom_terminal_number");
        $username = pmpro_getOption("cardcom_username");
        $password = pmpro_getOption("cardcom_password");
        if (!$terminal_number || !$username || !$password) {
            return false;
        }
        $response = Cardcom_API::request(array('TerminalNumber' => $terminal_number, 'UserName' => $username, 'Password' => $password, 'DealNumber' => $order->subscription_transaction_id), '?Operation=GetDealStatus');
        if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] == 0) {
            return $response['body']['Status'] ?? 'unknown';
        }
        return false;
    }

    /**
     * Schedule subscription update
     */
    protected function cardcom_schedule_subscription_update($trial_limit, $cycle_period, $order_id)
    {
        $trial_end_time = strtotime("+{$trial_limit} {$cycle_period}", current_time('timestamp'));
        wp_schedule_single_event($trial_end_time, 'cardcom_update_subscription_amount', array($order_id));
    }

    /**
     * Update subscription amount
     */
    public function cardcom_update_subscription_amount($order_id)
    {
        $order = new MemberOrder($order_id);
        $new_amount = $order->PaymentAmount;
        $this->cardcom_update_gateway_subscription_amount($order, $new_amount);
    }

    /**
     * Update subscription amount in Cardcom
     */
    protected function cardcom_update_gateway_subscription_amount($order, $new_amount)
    {
        $terminal_number = pmpro_getOption("cardcom_terminal_number");
        $username = pmpro_getOption("cardcom_username");
        $password = pmpro_getOption("cardcom_password");
        if (!$terminal_number || !$username || !$password) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Missing TerminalNumber, Username, or Password for update' . "\n", 3, CARDCOM_LOG_FILE);
            return;
        }
        $response = Cardcom_API::request(
            array(
                'TerminalNumber' => $terminal_number,
                'UserName' => $username,
                'Password' => $password,
                'DealNumber' => $order->subscription_transaction_id,
                'RecurringAmount' => $new_amount
            ),
            '?Operation=UpdateRecurring'
        );
        if (pmpro_getOption('cardcom_logging')) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: update_subscription_amount result: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
        }
        if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] == 0) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Subscription amount updated for order ' . $order->id . "\n", 3, CARDCOM_LOG_FILE);
        } else {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Failed to update subscription amount for order ' . $order->id . ': ' . ($response['body']['Description'] ?? 'Unknown error') . "\n", 3, CARDCOM_LOG_FILE);
        }
    }

    /**
     * Save Cardcom settings
     */
    static function cardcom_save_settings()
    {
        if (pmpro_getOption('gateway') == 'cardcom') {
            $options = self::cardcom_getGatewayOptions();
            foreach ($options as $option) {
                $value = isset($_POST[$option]) ? sanitize_text_field($_POST[$option]) : '';
                pmpro_setOption($option, $value);
                $log_value = ($option === 'cardcom_password') ? '********' : pmpro_getOption($option);
                error_log("Saved $option: " . $log_value . "\n", 3, CARDCOM_LOG_FILE);
            }
        }
    }
}
add_action('cardcom_update_subscription_amount', array('PMProGateway_cardcom', 'cardcom_update_subscription_amount'), 10, 1);