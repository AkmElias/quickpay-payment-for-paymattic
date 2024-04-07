<?php

/**
 * @package quickpay-payment-for-paymattic
 *
 *
 */

/**
 * Plugin Name: QuickPay Payment for paymattic
 * Plugin URI: https://paymattic.com/
 * Description: QuickPay payment gateway for paymattic. QuickPay is the leading payment gateway in Northern Europe.
 * Version: 1.0.0
 * Author: WPManageNinja LLC
 * Author URI: https://paymattic.com/
 * License: GPLv2 or later
 * Text Domain: quickpay-payment-for-paymattic
 * Domain Path: /language
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('ABSPATH') or die;

define('MONERIS_PAYMENT_FOR_PAYMATTIC', true);
define('MONERIS_PAYMENT_FOR_PAYMATTIC_DIR', __DIR__);
define('MONERIS_PAYMENT_FOR_PAYMATTIC_URL', plugin_dir_url(__FILE__));
define('MONERIS_PAYMENT_FOR_PAYMATTIC_VERSION', '1.0.0');


if (!class_exists('QuickPayPaymentForPaymattic')) {
    class QuickPayPaymentForPaymattic
    {
        public function boot()
        {
            if (!class_exists('MonerisPaymentForPaymattic\API\QuickPayProcessor.php')) {
                $this->init();
            };
        }

        public function init()
        {
            // require_once MONERIS_PAYMENT_FOR_PAYMATTIC_DIR . '/API/MonerisProcessor.php';

            // (new MonerisPaymentForPaymattic\API\MonerisProcessor())->init();

            $this->loadTextDomain();
        }

        public function loadTextDomain()
        {
            load_plugin_textdomain('quickpay-payment-for-paymattic', false, dirname(plugin_basename(__FILE__)) . '/language');
        }

        public function hasPro()
        {
            return defined('WPPAYFORMPRO_DIR_PATH') || defined('WPPAYFORMPRO_VERSION');
        }

        public function hasFree()
        {

            return defined('WPPAYFORM_VERSION');
        }

        public function versionCheck()
        {
            $currentFreeVersion = WPPAYFORM_VERSION;
            $currentProVersion = WPPAYFORMPRO_VERSION;

            return version_compare($currentFreeVersion, '4.5.2', '>=') && version_compare($currentProVersion, '4.5.2', '>=');
        }

        public function renderNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please install & Activate Paymattic and Paymattic Pro to use quickpay-payment-for-paymattic plugin.', 'quickpay-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }

        public function updateVersionNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use quickpay-payment-for-paymattic plugin!', 'quickpay-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }


    add_action('init', function () {

        $quickpay = new QuickPayPaymentForPaymattic;

        if (!$quickpay->hasFree() || !$quickpay->hasPro()) {
            $quickpay->renderNotice();
        } else if (!$quickpay->versionCheck()) {
            $quickpay->updateVersionNotice();
        } else {
            $quickpay->boot();
        }
    });
}