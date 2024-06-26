<?php

namespace QuickPayPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\PlaceholderParser;
use WPPayForm\App\Services\ConfirmationHelper;
use WPPayForm\App\Models\SubmissionActivity;

// can't use namespace as these files are not accessible yet
require_once QUICKPAY_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/QuickPayElement.php';
require_once QUICKPAY_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/QuickPaySettings.php';
require_once QUICKPAY_PAYMENT_FOR_PAYMATTIC_DIR . '/API/IPN.php';


class QuickPayProcessor
{
    public $method = 'quickpay';

    protected $form;

    public function init()
    {
        new  \QuickPayPaymentForPaymattic\Settings\QuickPayElement();
        (new  \QuickPayPaymentForPaymattic\Settings\QuickPaySettings())->init();
        (new API())->init();

        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_quickpay', array($this, 'makeFormPayment'), 10, 6);
        add_action('wppayform_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
        // add_action('wppayform_ipn_quickpay_action_refunded', array($this, 'handleRefund'), 10, 3);
        add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);
    }



    protected function getPaymentMode($formId = false)
    {
        $isLive = (new \QuickPayPaymentForPaymattic\Settings\QuickPaySettings())->isLive($formId);

        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function addTransactionUrl($transactions, $submissionId)
    {
        foreach ($transactions as $transaction) {
            if ($transaction->payment_method == 'quickpay' && $transaction->charge_id) {
                $transactionUrl = Arr::get(unserialize($transaction->payment_note), '_links.dashboard.href');
                $transaction->transaction_url =  $transactionUrl;
            }
        }
        return $transactions;
    }

    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'quickpay_gateway_element')) {
                return 'quickpay';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form, $hasSubscriptions)
    {
        $paymentMode = $this->getPaymentMode();

        $transactionModel = new Transaction();
        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => $paymentMode
            ));
        }
        $transaction = $transactionModel->getTransaction($transactionId);

        $submission = (new Submission())->getSubmission($submissionId);
        $this->handleRedirect($transaction, $submission, $form, $paymentMode);
    }

    private function getSuccessURL($form, $submission)
    {
        // Check If the form settings have success URL
        $confirmation = Form::getConfirmationSettings($form->ID);
        $confirmation = ConfirmationHelper::parseConfirmation($confirmation, $submission);
        if (
            ($confirmation['redirectTo'] == 'customUrl' && $confirmation['customUrl']) ||
            ($confirmation['redirectTo'] == 'customPage' && $confirmation['customPage'])
        ) {
            if ($confirmation['redirectTo'] == 'customUrl') {
                $url = $confirmation['customUrl'];
            } else {
                $url = get_permalink(intval($confirmation['customPage']));
            }
            $url = add_query_arg(array(
                'payment_method' => 'quickpay'
            ), $url);
            return PlaceholderParser::parse($url, $submission);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            return add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'quickpay'
            ), get_permalink(intval($globalSettings['confirmation'])));
        }
        // In case we don't have global settings
        return add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'quickpay'
        ), home_url());
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $currencyIsSupported = $this->checkForSupportedCurrency($submission);
        
        if (!$currencyIsSupported) {
            wp_send_json_error([
                'message' => sprintf(__('%s is not supported by quickpay', 'quickpay-payment-for-paymattic'), $submission->currency),
                'payment_error' => true
            ], 423);
        }

        $successUrl = $this->getSuccessURL($form, $submission);
        $listener_url = add_query_arg(array(
            'wppayform_payment' => $submission->id,
            'payment_method' => $this->method,
            'submission_hash' => $submission->submission_hash,
        ), $successUrl);

        $customer = array(
            'email' => $submission->customer_email,
            'name' => $submission->customer_name,
        );

        // we need to change according to the payment gateway documentation
        $paymentArgs = array(
            'tx_ref' => $submission->submission_hash,
            'amount' => number_format((float) $transaction->payment_total / 100, 2, '.', ''),
            'currency' => $submission->currency,
            'redirect_url' => $listener_url,
            'customer' => $customer,
        );

        $paymentArgs = apply_filters('wppayform_quickpay_payment_args', $paymentArgs, $submission, $transaction, $form);
        $payment = (new API())->makeApiCall('payments', $paymentArgs, $form->ID, 'POST');

        if (is_wp_error($payment)) {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id'        => $submission->id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'title' => 'quickpay Payment Redirect Error',
                'content' => $payment->get_error_message()
            ]);

            wp_send_json_error([
                'message'      => $payment->get_error_message()
            ], 423);
        }

        $paymentLink = Arr::get($payment, 'data.link');

        do_action('wppayform_log_data', [
            'form_id' => $form->ID,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'quickpay Payment Redirect',
            'content' => 'User redirect to quickpay for completing the payment'
        ]);

        wp_send_json_success([
            // 'nextAction' => 'payment',
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $paymentLink,
            'message'      => __('You are redirecting to quickpay.com to complete the purchase. Please wait while you are redirecting....', 'quickpay-payment-for-paymattic'),
        ], 200);
    }

    public function checkForSupportedCurrency($submission)
    {
        $currency = $submission->currency;
        $supportedCurrencies = array(
            'GBP', 
            'CAD',
            'XAF', 
            'CLP', 
            'COP', 
            'EGP', 
            'EUR',
            'GHS', 
            'GNF', 
            'KES', 
            'MWK',
            'MAD', 
            'NGN', 
            'RWF', 
            'ZAR', 
            'TZS', 
            'UGX', 
            'USD', 
            'XOF', 
            'ZMW', 
            'SLL', 
            'STD'
            );

        // check currencyis in supported currencies
        if (!in_array($currency, $supportedCurrencies)) {
            return false;
        }

        return true;

    }
    public function handleSessionRedirectBack($data)
    {

        $submissionId = intval($data['wppayform_payment']);
        $submission = (new Submission())->getSubmission($submissionId);
        $transaction = $this->getLastTransaction($submissionId);

        $transactionId = Arr::get($data, 'transaction_id');
        $paymentStatus = Arr::get($data, 'status');
        // This hook will be usefull for the developers to do something after the payment is processed
        do_action('wppayform/form_payment_processed', $submission->form_id, $submission, $data, $paymentStatus);

        $payment = (new API())->makeApiCall('transactions/' . $transactionId . '/verify', [], $submission->form_id);

        if (!$payment || is_wp_error($payment)) {
            do_action('wppayform/form_payment_failed',$submission, $submission->form_id, $data, 'razorpay');
            return;
        }

        if (is_wp_error($payment)) {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => $payment->get_error_message()
            ]);
        }

        $transaction = $this->getLastTransaction($submissionId);

        if (!$transaction || $transaction->payment_method != $this->method || $transaction->status === 'paid') {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        if ($paymentStatus === 'successful') {
            $status = 'paid';
        } else if ($paymentStatus === 'failed') {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        $updateData = [
            'payment_note'     => maybe_serialize($payment),
            'charge_id'        => $transactionId,
        ];

        $this->markAsPaid($status, $updateData, $transaction);
    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $this->updateRefund($vendorTransaction['status'], $refundAmount, $transaction, $submission);
    }

    public function updateRefund($newStatus, $refundAmount, $transaction, $submission)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submission->id);
        if ($submission->payment_status == $newStatus) {
            return;
        }

        $submissionModel->updateSubmission($submission->id, array(
            'payment_status' => $newStatus
        ));

        Transaction::where('submission_id', $submission->id)->update(array(
            'status' => $newStatus,
            'updated_at' => current_time('mysql')
        ));

        do_action('wppayform/after_payment_status_change', $submission->id, $newStatus);

        $activityContent = 'Payment status changed from <b>' . $submission->payment_status . '</b> to <b>' . $newStatus . '</b>';
        $note = wp_kses_post('Status updated by quickpay.');
        $activityContent .= '<br />Note: ' . $note;
        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'quickpay',
            'content' => $activityContent
        ));
    }

    public function getLastTransaction($submissionId)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->where('submission_id', $submissionId)
            ->first();
        return $transaction;
    }

    public function markAsPaid($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $formDataRaw = $submission->form_data_raw;
        $formDataRaw['quickpay_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'form_data_raw' => maybe_serialize($formDataRaw),
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'],
            'payment_note' => $updateData['payment_note'],
            'status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        $transactionModel->where('id', $transaction->id)->update($data);

        $transaction = $transactionModel->getTransaction($transaction->id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid and quickpay Transaction ID: %s', 'quickpay-payment-for-paymattic'), $data['charge_id'])
        ));

        do_action('wppayform/form_payment_success_quickpay', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with quickpay is not supported yet!', 'quickpay-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}
