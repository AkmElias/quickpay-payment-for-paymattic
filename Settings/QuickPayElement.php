<?php

namespace QuickPayPaymentForPaymattic\Settings;

use WPPayForm\App\Modules\FormComponents\BaseComponent;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class QuickPayElement extends BaseComponent
{
    public $gateWayName = 'quickpay';

    public function __construct()
    {
        parent::__construct('quickpay_gateway_element', 11);
        add_filter('wppayform/validate_gateway_api_' . $this->gateWayName, function ($data, $form) {
            return $this->validateApi();
        }, 2, 10);
        add_action('wppayform/payment_method_choose_element_render_quickpay', array($this, 'renderForMultiple'), 10, 3);
        add_filter('wppayform/available_payment_methods', array($this, 'pushPaymentMethod'), 2, 1);
    }

    public function pushPaymentMethod($methods)
    {
        $methods['quickpay'] = array(
            'label' => 'quickpay',
            'isActive' => true,
            'logo' => QUICKPAY_PAYMENT_FOR_PAYMATTIC_URL . 'assets/quickpay.svg',
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Payment Option Label',
                    'type' => 'text',
                    'default' => 'Pay with Quickpay'
                ),
                'require_billing_address' => array(
                    'label' => 'Require Billing Address',
                    'type' => 'switch',
                ),
            )
        );
        return $methods;
    }


    public function component()
    {
        return array(
            'type' => 'quickpay_gateway_element',
            'editor_title' => 'QuickPay Payment',
            'editor_icon' => '',
            'conditional_hide' => true,
            'group' => 'payment_method_element',
            'method_handler' => $this->gateWayName,
            'postion_group' => 'payment_method',
            'single_only' => true,
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Field Label',
                    'type' => 'text'
                ),
                'require_billing_address' => array(
                    'label' => 'Require Billing Address',
                    'type' => 'switch',
                ),
            ),
            'field_options' => array(
                'label' => __('QuickPay Payment Gateway', 'wp-payment-form-pro'),
                'require_billing_address' => 'yes'
            )
        );
    }

    public function validateApi()
    {
        $apiKeys = (new QuickPaySettings())->getApiKeys();
        return strlen($apiKeys['api_token']) > 0 || strlen($apiKeys['store_id']) > 0 || strlen($apiKeys['checkout_id']) > 0;
    }

    public function render($element, $form, $elements)
    {
        do_action('wppayform_load_checkout_js_quickpay');

        if (!$this->validateApi()) { ?>
            <p style="color: red">You did not configure QuickPay payment gateway. Please configure quickpay payment
                gateway from <b>Paymattic->Payment Gateway->QuickPay Settings</b> to start accepting payments</p>
            <?php
        }

        if (Arr::get($element, 'field_options.require_billing_address') == 'yes') {
            echo '<input type="hidden" name="__payment_require_billing_address" value="yes" />';
        }
        echo '<input data-wpf_payment_method="quickpay" type="hidden" name="__quickpay_payment_gateway" value="quickpay" />';
    }

    public function renderForMultiple($paymentSettings, $form, $elements)
    {
        $settings = (new QuickPaySettings())->getPaymentSettings();
        do_action('wppayform_load_checkout_js_quickpay', $settings);

        $component = $this->component();
        $component['id'] = 'quickpay_gateway_element';
        $component['field_options'] = $paymentSettings;
        $this->render($component, $form, $elements);
    }
}
