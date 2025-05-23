<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Communicates with Cardcom API.
 */
class Cardcom_API
{
    /**
     * Cardcom API Endpoint
     */
    const ENDPOINT = 'https://secure.cardcom.solutions/Interface/BillGoldService.asmx';
    const CARDCOM_API_VERSION = '1.0.0';

    /**
     * Terminal Number for Cardcom.
     * @var string
     */
    private static $terminal_number = '';

    /**
     * Username for Cardcom.
     * @var string
     */
    private static $username = '';

    /**
     * Set Terminal Number.
     * @param string $terminal_number
     */
    public static function set_terminal_number($terminal_number)
    {
        self::$terminal_number = $terminal_number;
    }

    /**
     * Set Username.
     * @param string $username
     */
    public static function set_username($username)
    {
        self::$username = $username;
    }

    /**
     * Get Terminal Number.
     * @return string
     */
    public static function get_terminal_number()
    {
        return self::$terminal_number;
    }

    /**
     * Get Username.
     * @return string
     */
    public static function get_username()
    {
        return self::$username;
    }

    /**
     * Get redirect URL for payment.
     * @return string
     */
    public static function get_redirect_order_api()
    {
        return 'https://secure.cardcom.solutions/Interface/StartPayment.aspx';
    }

    /**
     * Generates the user agent for API requests.
     */
    public static function get_user_agent()
    {
        $app_info = array(
            'name'    => 'Paid Memberships Pro Cardcom Gateway',
            'version' => self::CARDCOM_API_VERSION,
        );

        return array(
            'lang'         => 'php',
            'lang_version' => phpversion(),
            'publisher'    => 'paid-memberships-pro',
            'uname'        => php_uname(),
            'application'  => $app_info,
        );
    }

    /**
     * Generates headers for API requests.
     */
    public static function get_headers()
    {
        $user_agent = self::get_user_agent();
        $app_info   = $user_agent['application'];

        return apply_filters(
            'pmpro_cardcom_request_headers',
            array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent'   => $app_info['name'] . '/' . $app_info['version'],
                'X-Cardcom-Client-User-Agent' => json_encode($user_agent),
            )
        );
    }

    /**
     * Send request to Cardcom API.
     * @param array $request
     * @param string $api
     * @param string $method
     * @param bool $with_headers
     * @return array
     * @throws Exception
     */
    public static function request($request, $api = '', $method = 'POST', $with_headers = false)
    {
        $terminal_number = self::get_terminal_number();
        $username = self::get_username();
        if (empty($terminal_number) || empty($username)) {
            error_log('[' . date('Y-m-d H:i:s') . '] Cardcom API Error: TerminalNumber or Username is empty.', 3, CARDCOM_LOG_FILE);
            throw new Exception(__('Terminal Number or Username is not set.', 'pmpro-cardcom'));
        }

        $request['TerminalNumber'] = $terminal_number;
        $request['UserName'] = $username;

        $headers = self::get_headers();
        $response = wp_remote_post(
            self::ENDPOINT . $api,
            array(
                'method'  => $method,
                'headers' => $headers,
                'body'    => http_build_query(apply_filters('pmpro_cardcom_request_body', $request, $api)),
                'timeout' => 70,
            )
        );

        if (is_wp_error($response) || empty($response['body'])) {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] Cardcom Error Response: ' . print_r($response, true) . PHP_EOL . 'Failed request: ' . print_r(
                    array(
                        'api'     => $api,
                        'request' => $request,
                    ),
                    true
                ), 3, CARDCOM_LOG_FILE
            );
            throw new Exception(__('There was a problem connecting to the Cardcom API endpoint.', 'pmpro-cardcom'));
        }

        $body = $response['body'];
        if (!json_decode($body, true)) {
            parse_str($body, $parsed_body);
            $body = $parsed_body;
        } else {
            $body = json_decode($body, true) ?: $body;
        }

        return array(
            'headers' => wp_remote_retrieve_headers($response),
            'body'    => $body,
        );
    }

    /**
     * Send GET request to Cardcom API.
     * @param array $request
     * @return array
     * @throws Exception
     */
    public static function get_request($request)
    {
        $headers = self::get_headers();
        $api_url = self::get_redirect_order_api();
        error_log('[' . date('Y-m-d H:i:s') . '] Cardcom API URL: ' . $api_url, 3, CARDCOM_LOG_FILE);

        $response = wp_remote_post(
            $api_url,
            array(
                'headers' => $headers,
                'body'    => $request,
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] Cardcom Error: WP_Error - ' . $response->get_error_message() . PHP_EOL .
                'Request: ' . print_r($request, true),
                3, CARDCOM_LOG_FILE
            );
            throw new Exception(__('Connection error to Cardcom API: ' . $response->get_error_message(), 'pmpro-cardcom'));
        }

        if (empty($response['body'])) {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] Cardcom Error: Empty response' . PHP_EOL .
                'Request: ' . print_r($request, true),
                3, CARDCOM_LOG_FILE
            );
            throw new Exception(__('Empty response from Cardcom API.', 'pmpro-cardcom'));
        }

        $body = $response['body'];
        error_log(
            '[' . date('Y-m-d H:i:s') . '] Cardcom Response: ' . $body,
            3, CARDCOM_LOG_FILE
        );

        $decoded_body = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $decoded_body;
        } else {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] Cardcom Warning: Response is not JSON: ' . $body,
                3, CARDCOM_LOG_FILE
            );
        }

        return array(
            'headers' => wp_remote_retrieve_headers($response),
            'body'    => $body,
        );
    }
}