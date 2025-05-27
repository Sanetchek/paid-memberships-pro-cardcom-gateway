<?php

if (!defined('PMPRO_DIR') || !defined('PMPRO_CARDCOMGATEWAY_DIR')) {
    error_log(__('[' . date('Y-m-d H:i:s') . '] Paid Memberships Pro and the PMPro Cardcom Add On must be activated for the PMPro Cardcom IPN handler to function.', 'pmpro-cardcom') . "\n", 3, CARDCOM_LOG_FILE);
    exit;
}

//some globals
global $wpdb, $gateway_environment, $logstr;
$logstr = "";

PmPro_Cardcom_Logger::log("pmpro_cardcom_webhook_hit:: " . print_r($_GET, true));
$statusCode               = pmpro_getParam("ResponseCode", "GET");
$statusDescription        = pmpro_getParam("Description", "GET");
$uniqId                   = pmpro_getParam("InternalDealNumber", "GET");
$token                    = pmpro_getParam("Token", "GET");
$order_reference          = $orderId = wp_unslash($_GET['order_reference']); // WPCS: CSRF ok, input var ok.
$gateway = new PMProGateway_cardcom();

if (!empty($order_reference)) {
    $transaction = new Pmpro_Cardcom_Transaction($uniqId, $order_reference);
    $morder = new MemberOrder($order_reference);
    $user = $morder->user_id ? get_userdata($morder->user_id) : null;
    if (empty($morder)) {
        PmPro_Cardcom_Logger::log("ERROR: order wasn't found :: " . $order_reference);
        exit;
    } else {
        do_action("cardcom_ipn_hit", $transaction, $morder);

        if ($statusCode != 0) {
            try {
                PmPro_Cardcom_Logger::log("ERROR: status code :: " . $statusCode);
                $pmproemail = new PMProEmail();
                if ($user) {
                    $pmproemail->sendBillingFailureEmail($user, $morder);
                }

                // Email admin so they are aware of the failure
                $pmproemail = new PMProEmail();
                $pmproemail->sendBillingFailureAdminEmail(get_bloginfo("admin_email"), $morder);
                PmPro_Cardcom_Logger::log("ERROR: order failed statusDescription :: " . $statusDescription);
                exit;
            } catch (Exception $e) {
                PmPro_Cardcom_Logger::log("Exception: order failed statusDescription :: " . print_r($e, true));
            }
        } else {
            PmPro_Cardcom_Logger::log("update morder uniq id: " . $uniqId);
            $id = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_membership_orders WHERE payment_transaction_id = '" . esc_sql($uniqId) . "' ORDER BY id DESC LIMIT 1");
            PmPro_Cardcom_Logger::log("update morder id: " . $id);
            if (!$id) {
                PmPro_Cardcom_Logger::log("new order recurring payment was successful");
                $display_type = pmpro_getOption("display_type");
                if ($display_type == 2) {
                    PmPro_Cardcom_Logger::log("update morder after redirect");
                    pmpro_ipnChangeMembershipLevel($uniqId, $morder);
                } else {
                    if ($morder->status != 'success') {
                        $morder->payment_transaction_id      = $uniqId;
                        $morder->subscription_transaction_id = $uniqId;
                        $morder->status = 'success'; // We have confirmed that and that's the reason we are here.
                    }
                }
            } else {
                $morder->status = 'success'; // We have confirmed that and that's the reason we are here.
                PmPro_Cardcom_Logger::log("Recurring payment was successful");
            }
            $morder->timestamp = strtotime(date("Y-m-d H:i:s"));
            $morder->saveOrder();
        }
    }
}

function pmpro_cardcom_Validate($uniqId)
{
    if (pmpro_getParam("ResponseCode", "GET") != 0) {
        return false;
    }
    $validateReq = array(
        'InternalDealNumber' => $uniqId,
        'Operation' => 'GetTransaction'
    );
    $response = Cardcom_API::request($validateReq);
    PmPro_Cardcom_Logger::log('Checking PMPRO Cardcom IPN response is valid response::: ' . print_r($response, true));
    if (isset($response['body']['OperationResponse']) && $response['body']['OperationResponse'] != 0) {
        return false;
    }
    if (isset($response['body']['ResponseCode']) && $response['body']['ResponseCode'] != 0) {
        return false;
    }
    update_option('cardcom_processed_' . $uniqId, true);
    return true;
}

function pmpro_ipnExit()
{
    exit;
}

function pmpro_ipnChangeMembershipLevel($txn_id, &$morder)
{
    global $wpdb;
    $morder->getMembershipLevel();
    $morder->getUser();
    //filter for level
    $morder->membership_level = apply_filters("pmpro_ipnhandler_level", $morder->membership_level, $morder->user_id);

    //set the start date to current_time('timestamp') but allow filters (documented in preheaders/checkout.php)
    $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time('mysql') . "'", $morder->user_id, $morder->membership_level);

    //fix expiration date
    if (!empty($morder->membership_level->expiration_number)) {
        $enddate = "'" . date_i18n("Y-m-d", strtotime("+ " . $morder->membership_level->expiration_number . " " . $morder->membership_level->expiration_period, current_time("timestamp"))) . "'";
    } else {
        $enddate = "NULL";
    }

    //filter the enddate (documented in preheaders/checkout.php)
    $enddate = apply_filters("pmpro_checkout_end_date", $enddate, $morder->user_id, $morder->membership_level, $startdate);

    //get discount code
    $morder->getDiscountCode();
    if (!empty($morder->discount_code)) {
        //update membership level
        $morder->getMembershipLevel(true);
        $discount_code_id = $morder->discount_code->id;
    } else {
        $discount_code_id = "";
    }

    //custom level to change user to
    $custom_level = array(
        'user_id'         => $morder->user_id,
        'membership_id'   => $morder->membership_level->id,
        'code_id'         => $discount_code_id,
        'initial_payment' => $morder->membership_level->initial_payment,
        'billing_amount'  => $morder->membership_level->billing_amount,
        'cycle_number'    => $morder->membership_level->cycle_number,
        'cycle_period'    => $morder->membership_level->cycle_period,
        'billing_limit'   => $morder->membership_level->billing_limit,
        'trial_amount'    => $morder->membership_level->trial_amount,
        'trial_limit'     => $morder->membership_level->trial_limit,
        'startdate'       => $startdate,
        'enddate'         => $enddate
    );

    global $pmpro_error;
    if (!empty($pmpro_error)) {
        echo esc_html($pmpro_error);
        PmPro_Cardcom_Logger::log($pmpro_error);
    }
    PmPro_Cardcom_Logger::log("before pmpro_changeMembershipLevel");
    //change level and continue "checkout"
    if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed') !== false) {
        PmPro_Cardcom_Logger::log("in pmpro_changeMembershipLevel");
        //update order status and transaction ids
        $morder->status                 = "success";
        $morder->payment_transaction_id = $txn_id;
        $morder->subscription_transaction_id = $txn_id;
        $morder->saveOrder();

        //add discount code use
        if (!empty($morder->discount_code)) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->pmpro_discount_codes_uses}
                        (code_id, user_id, order_id, timestamp)
                        VALUES(%d, %d, %d, %s)",
                    $discount_code_id,
                    $morder->user_id,
                    $morder->id,
                    current_time('mysql')
                )
            );
        }

        //hook
        do_action("pmpro_after_checkout", $morder->user_id, $morder);

        //setup some values for the emails
        if (!empty($morder)) {
            $invoice = new MemberOrder($morder->id);
        } else {
            $invoice = null;
        }

        $user                   = get_userdata($morder->user_id);
        $user->membership_level = $morder->membership_level; //make sure they have the right level info

        //send email to member
        $pmproemail = new PMProEmail();
        $pmproemail->sendCheckoutEmail($user, $invoice);

        //send email to admin
        $pmproemail = new PMProEmail();
        $pmproemail->sendCheckoutAdminEmail($user, $invoice);

        return true;
    } else {
        return false;
    }
}