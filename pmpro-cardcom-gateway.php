<?php
/**
 * Plugin Name: Paid Memberships Pro - Cardcom Gateway
 * Description: Take credit card payments on your store using Cardcom.
 * Author: Hamamlitz
 * Author URI: https://hamamlitz.com/
 * Version: 1.0.0
 * Requires at least: 4.4
 * Tested up to: 6.7
 * WC requires at least: 2.6
 * WC tested up to: 4.0
 * Text Domain: pmpro-cardcom
 * Domain Path: /languages
 */

define("PMPRO_CARDCOMGATEWAY_DIR", plugin_dir_path(__FILE__));
define("PMPRO_CARDCOM_META_KEY", "_pmpro_cardcom");
define("CARDCOM_LOG_FILE", PMPRO_CARDCOMGATEWAY_DIR . "cardcom.log");

// Load payment gateway class
function pmpro_cardcom_plugins_loaded()
{
    if (!defined('PMPRO_DIR')) {
        return;
    }
    require_once(PMPRO_CARDCOMGATEWAY_DIR . "/includes/cardcom_api.php");
    require_once(PMPRO_CARDCOMGATEWAY_DIR . "/classes/class.pmprogateway_cardcom.php");
}
add_action('plugins_loaded', 'pmpro_cardcom_plugins_loaded');

// Support for ILS currency
function pmpro_currencies_ils($currencies)
{
    $currencies['ILS'] = __('שקל ישראל (₪)', 'pmpro-cardcom');
    return $currencies;
}
add_filter('pmpro_currencies', 'pmpro_currencies_ils');

// Record when users gain the trial level
function cardcom_save_trial_level_used($level_id, $user_id)
{
    update_user_meta($user_id, 'pmpro_trial_level_used', $level_id);
}
add_action('pmpro_after_change_membership_level', 'cardcom_save_trial_level_used', 10, 2);

// WooCommerce compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});