<?php

/**
 * Class Transaction
 */
class Pmpro_Cardcom_Transaction
{
    protected $data = array(
        'order_id' => 0,
        'internalDealNumber' => '', // Changed from transactionInternalNumber to match Cardcom
        'status'     => '',
        'statusCode'     => 0,
        'statusDescription'     => '',
        'last4DigitsCardNumber'     => '',
        'invoiceId'     => 0,
        'numberOfPayments'     => 0,
        'cardtype'     => '',
        'cardCompanyType'     => '', // Adjusted for consistency
        'clearer'     => '',
        'dealType'     => 0,
        'invoiceLink'     => '',
        'isDocumentCreated'     => false,
        'transactionDate'     => '',
        'transactionType' => 0,
        'amount' => 0
    );

    /**
     * This is false until the object is read from the DB.
     *
     * @since 3.0.0
     * @var bool
     */
    protected $object_read = false;

    /**
     * Transaction constructor.
     *
     * @param string $internalDealNumber
     */
    public function __construct($internalDealNumber = '', $order_id = 0)
    {
        $this->set_order_id($order_id);
        if (!empty($internalDealNumber)) {
            $this->set_id($internalDealNumber);
            $this->get_transaction();
        }
    }

    public function set_id($id)
    {
        $this->id = $id;
    }

    public function get_transaction()
    {
        $response = Cardcom_API::get_request(array(
            'Operation' => 'GetLowProfileIndicator',
            'Token' => $this->get_id(),
            'Language' => 'he'
        ));
        if (is_wp_error($response) || empty($response['body']) || !isset($response['body']['OperationResponse'])) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: Failed to connect to API endpoint for transaction: ' . print_r($response, true) . "\n", 3, CARDCOM_LOG_FILE);
            return false;
        }
        $body = $response['body'];
        $this->set_data($body);
        $this->save();
        return true;
    }

    /**
     * Returns the unique ID for this object.
     *
     * @since  2.6.0
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    public function save()
    {
        global $wpdb;
        $order = $this->get_order();
        if (!$order) {
            return;
        }
        $dealNumber = $this->get_id();
        $updated = false;
        $transactions = get_pmpro_membership_order_meta($order->id, PMPRO_CARDCOM_META_KEY);
        if ($transactions) {
            foreach ($transactions as $meta) {
                if ($updated) {
                    break;
                }
                if (json_decode($meta)->id == $dealNumber) {
                    error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: ' . sprintf('Updating transaction meta: %s', $dealNumber) . "\n", 3, CARDCOM_LOG_FILE);
                    $updated = true;
                }
            }
        }
        if (!$updated) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom: ' . sprintf('Adding new transaction meta: %s', $dealNumber) . "\n", 3, CARDCOM_LOG_FILE);
            do_action("cardcom_new_transaction", $this, $order);
            add_pmpro_membership_order_meta($order->id, PMPRO_CARDCOM_META_KEY, (string)$this);
        }
    }

    /**
     * Change data to JSON format.
     *
     * @since  2.6.0
     * @return string Data in JSON format.
     */
    public function __toString()
    {
        return wp_json_encode($this->get_data());
    }

    /**
     * Returns all data for this object.
     *
     * @since  2.6.0
     * @return array
     */
    public function get_data()
    {
        return array_merge(array('id' => $this->get_id()), $this->data);
    }

    public function set_data($data)
    {
        if (isset($data['OperationResponse']) && $data['OperationResponse'] != 0) {
            $this->set_props([
                'status' => isset($data['Status']) ? $data['Status'] : '',
                'statusCode' => isset($data['OperationResponse']) ? $data['OperationResponse'] : 0,
                'statusDescription' => isset($data['Description']) ? $data['Description'] : '',
                'dealType' => isset($data['DealType']) ? $data['DealType'] : 0,
                'amount' => isset($data['Amount']) ? $data['Amount'] : 0,
                'transactionDate' => isset($data['TransactionDate']) ? $data['TransactionDate'] : '',
            ]);
        } elseif (isset($data['ResponseCode']) && $data['ResponseCode'] != 0) {
            $this->set_props([
                'status' => isset($data['Status']) ? $data['Status'] : '',
                'statusCode' => isset($data['ResponseCode']) ? $data['ResponseCode'] : 0,
                'statusDescription' => isset($data['Description']) ? $data['Description'] : '',
                'dealType' => isset($data['DealType']) ? $data['DealType'] : 0,
                'amount' => isset($data['Amount']) ? $data['Amount'] : 0,
                'transactionDate' => isset($data['TransactionDate']) ? $data['TransactionDate'] : '',
            ]);
        } else {
            $this->set_props([
                'status' => isset($data['Status']) ? $data['Status'] : '',
                'statusCode' => isset($data['ResponseCode']) ? $data['ResponseCode'] : 0,
                'statusDescription' => isset($data['Description']) ? $data['Description'] : '',
                'last4DigitsCardNumber' => isset($data['Last4Digits']) ? $data['Last4Digits'] : '',
                'numberOfPayments' => isset($data['NumberOfPayments']) ? $data['NumberOfPayments'] : 0,
                'cardtype' => isset($data['CardType']) ? $data['CardType'] : '',
                'cardCompanyType' => isset($data['CardCompanyType']) ? $data['CardCompanyType'] : '',
                'dealType' => isset($data['DealType']) ? $data['DealType'] : 0,
                'amount' => isset($data['Amount']) ? $data['Amount'] : 0,
                'transactionDate' => isset($data['TransactionDate']) ? $data['TransactionDate'] : '',
                'invoiceLink' => isset($data['InvUniqId']) && !empty($data['InvUniqId']) ? $data['InvUniqId'] : (isset($data['InvoiceUniqId']) ? $data['InvoiceUniqId'] : ''),
            ]);
        }
        return $this;
    }

    public function set_props($props, $context = 'set')
    {
        $errors = false;
        foreach ($props as $prop => $value) {
            if (is_null($value) || in_array($prop, array('prop', 'date_prop', 'meta_data'), true)) {
                continue;
            }
            $setter = "set_$prop";
            if (is_callable(array($this, $setter))) {
                $this->{$setter}($value);
            }
        }
        return $errors && count($errors->get_error_codes()) ? $errors : true;
    }

    protected function get_prop($prop, $context = 'view')
    {
        $value = null;
        if (array_key_exists($prop, $this->data)) {
            $value = $this->data[$prop];
        }
        return $value;
    }

    protected function set_prop($prop, $value)
    {
        if (array_key_exists($prop, $prop, $this->data)) {
            $this->data[$prop] = $value;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Getters
    |--------------------------------------------------------------------------
    */
    public function get_isDocumentCreated($context = 'view')
    {
        return $this->get_prop('isDocumentCreated', $context);
    }
    public function get_invoiceLink($context = 'view')
    {
        return $this->get_prop('invoiceLink', $context);
    }
    public function get_last4DigitsCardNumber($context = 'view')
    {
        return $this->get_prop('last4DigitsCardNumber', $context);
    }
    public function get_transactionDate($context = 'view')
    {
        return $this->get_prop('transactionDate', $context);
    }
    public function get_transactionType($context = 'view')
    {
        return $this->get_prop('transactionType', $context);
    }
    public function get_order_id($context = 'view')
    {
        return $this->get_prop('order_id', $context);
    }
    public function get_amount($context = 'view')
    {
        return $this->get_prop('amount', $context);
    }
    public function get_status($context = 'view')
    {
        $status = intval($this->get_prop('status', $context));
        switch ($status) {
            case 0:
                return __('Unknown', 'pmpro-cardcom');
            case 1:
                return __('Approved', 'pmpro-cardcom');
            case 2:
                return __('Declined', 'pmpro-cardcom');
            case 3:
                return __('Partial Refund', 'pmpro-cardcom');
            case 4:
                return __('Pending', 'pmpro-cardcom');
            case 5:
                return __('Refunded', 'pmpro-cardcom');
            case 6:
                return __('Failed', 'pmpro-cardcom');
            default:
                return '????';
        }
    }
    public function get_statusCode($context = 'view')
    {
        return $this->get_prop('statusCode', $context);
    }
    public function get_description($context = 'view')
    {
        return $this->get_prop('statusDescription', $context); // Changed from description to statusDescription
    }
    public function get_order()
    {
        $order_id = $this->get_order_id();
        return new MemberOrder($order_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Setters
    |--------------------------------------------------------------------------
    */
    public function set_invoiceLink($invoiceLink)
    {
        if (!empty($invoiceLink)) {
            $this->set_prop('invoiceLink', $invoiceLink);
        }
        return $this;
    }
    public function set_order_id($order_id)
    {
        return $this->set_prop('order_id', $order_id);
    }
    public function set_transactionDate($transactionDate)
    {
        return $this->set_prop('transactionDate', $transactionDate);
    }
    public function set_status($status)
    {
        return $this->set_prop('status', $status);
    }
    public function set_statusCode($statusCode)
    {
        return $this->set_prop('statusCode', $statusCode);
    }
    public function set_transactionType($transactionType)
    {
        return $this->set_prop('transactionType', $transactionType);
    }
    public function set_statusDescription($statusDescription)
    {
        return $this->set_prop('statusDescription', $statusDescription);
    }
    public function set_last4DigitsCardNumber($last4DigitsCardNumber)
    {
        return $this->set_prop('last4DigitsCardNumber', $last4DigitsCardNumber);
    }
    public function set_numberOfPayments($numberOfPayments)
    {
        return $this->set_prop('numberOfPayments', $numberOfPayments);
    }
    public function set_cardtype($cardtype)
    {
        return $this->set_prop('cardtype', $cardtype);
    }
    public function set_cardCompanyType($cardCompanyType)
    {
        return $this->set_prop('cardCompanyType', $cardCompanyType);
    }
    public function set_clearer($clearer)
    {
        return $this->set_prop('clearer', $clearer);
    }
    public function set_dealType($dealType)
    {
        return $this->set_prop('dealType', $dealType);
    }
    public function set_isDocumentCreated($isDocumentCreated)
    {
        return $this->set_prop('isDocumentCreated', $isDocumentCreated);
    }
    public function set_amount($amount)
    {
        return $this->set_prop('amount', $amount);
    }
    public function set_json_data($data)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        $this->set_props($data);
        return $this;
    }
}