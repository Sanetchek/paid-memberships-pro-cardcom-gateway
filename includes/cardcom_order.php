<?php

class Pmpro_Order_Cardcom
{
    private static $instance = [];
    public static function getInstance()
    {
        $cls = static::class;
        if (!isset(self::$instance[$cls])) {
            self::$instance[$cls] = new static();
        }

        return self::$instance[$cls];
    }

    /**
     * Order constructor.
     */
    private function __construct()
    {
        add_action('pmpro_after_order_settings_table', array($this, 'add_meta_boxes'), 10, 2);
    }

    public function add_meta_boxes($order)
    {
        PmPro_Cardcom_Logger::log("add_meta_boxes");
        $transactions_json_array = get_pmpro_membership_order_meta($order->id, PMPRO_CARDCOM_META_KEY);
        $transactions = [];
        foreach ($transactions_json_array as $meta) {
            PmPro_Cardcom_Logger::log("add_meta_boxes tranlinr::" . $meta);
            $transactions[] = (new Pmpro_Cardcom_Transaction())->set_json_data($meta);
        }
        PmPro_Cardcom_Logger::log("add_meta_boxes trans:: " . print_r($transactions_json_array, true));

        if (empty($transactions)) {
            return;
        }
        include PMPRO_CARDCOMGATEWAY_DIR . '/templates/order.php';
    }

    public function get_transactions($order)
    {
        $transactions = [];

        return $transactions;
    }
}

Pmpro_Order_Cardcom::getInstance();