<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class PmPro_Cardcom_Logger
{
    public static $logger;
    const LOG_FILENAME = 'pmpro_cardcom.log'; // Name of the log file

    /**
     * Logs a message to the file if logging is enabled
     *
     * @param string $message The message to log
     * @param mixed $end_time Optional end time for the log entry
     * @return void
     */
    public static function log($message, $end_time = null)
    {
        // Получаем настройку логирования
        $cardcom_logging = pmpro_getOption("cardcom_logging");
        error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: cardcom_logging value: " . var_export($cardcom_logging, true), 3, CARDCOM_LOG_FILE);

        // Проверяем, включено ли логирование
        if (empty($cardcom_logging) || (int)$cardcom_logging !== 1) {
            error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Logging is disabled (cardcom_logging: " . var_export($cardcom_logging, true) . ")", 3, CARDCOM_LOG_FILE);
            return;
        }

        // Форматируем время и сообщение
        $timestamp = date('Y-m-d H:i:s', current_time('timestamp'));
        $log_entry = "[$timestamp] $message\n";

        try {
            $log_dir = PMPRO_CARDCOMGATEWAY_DIR . "logs";
            $log_file = $log_dir . "/" . self::LOG_FILENAME;

            // Создаём директорию, если её нет
            if (!is_dir($log_dir)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Creating directory $log_dir", 3, CARDCOM_LOG_FILE);
                mkdir($log_dir, 0755, true);
            }

            // Проверяем права записи
            if (!is_writable($log_dir)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Directory $log_dir is not writable", 3, CARDCOM_LOG_FILE);
                return;
            }

            // Создаём файл, если его нет
            if (!file_exists($log_file)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Creating file $log_file", 3, CARDCOM_LOG_FILE);
                touch($log_file);
                chmod($log_file, 0644);
            }

            if (!is_writable($log_file)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: File $log_file is not writable", 3, CARDCOM_LOG_FILE);
                return;
            }

            // Записываем в файл
            $loghandle = fopen($log_file, "a");
            if ($loghandle === false) {
                error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Failed to open $log_file", 3, CARDCOM_LOG_FILE);
                return;
            }
            fwrite($loghandle, $log_entry);
            fclose($loghandle);
            error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger: Successfully wrote to $log_file", 3, CARDCOM_LOG_FILE);
        } catch (Exception $e) {
            error_log("[" . date('Y-m-d H:i:s') . "] Cardcom Logger Error: " . $e->getMessage() . " - Log Entry: " . $log_entry, 3, CARDCOM_LOG_FILE);
        }
    }
}