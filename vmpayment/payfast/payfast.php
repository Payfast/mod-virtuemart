<?php

/**
 * payfast.php
 *
 * Copyright (c) 2025 Payfast (Pty) Ltd
 * You (being anyone who is not Payfast (Pty) Ltd) may download and use this plugin / code in your own website in
 * conjunction with a registered and active Payfast account. If your Payfast account is terminated for any reason,
 * you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code
 * or part thereof in any way.
 *
 * @author      Payfast (Pty) Ltd
 * @link        https://payfast.io/integration/shopping-carts/virtuemart/
 * @version     1.6.0
 */
require_once 'vendor/autoload.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Payfast\PayfastCommon\Aggregator\Request\PaymentRequest;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
    require JPATH_VM_PLUGINS . DS . 'vmpsplugin.php';
}

const SANDBOX_MERCHANT_ID = '10000100';
const SANDBOX_MERCHANT_KEY = '46f0cd694581a';
const PF_ORDER = 'orders.php';
const PF_SOFTWARE_NAME = 'VirtueMart';
const PF_SOFTWARE_VER = "4.4.4";
const PF_MODULE_NAME = 'Payfast-Virtuemart';
const PF_MODULE_VER = '1.6.0';
const PF_DEBUG = true;

class plgVmPaymentPayfast extends vmPSPlugin
{
    /**
     * @param $subject
     * @param $config
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = array(
            'payfast_merchant_key' => array('', 'char'),
            'payfast_merchant_id' => array('', 'char'),
            'payfast_passphrase' => array('', 'char'),
            'payfast_verified_only' => array('', 'int'),
            'payment_currency' => array(0, 'int'),
            'sandbox' => array(0, 'int'),
            'sandbox_merchant_key' => array('', 'char'),
            'sandbox_merchant_id' => array('', 'char'),
            'debug' => array(0, 'int'),
            'status_pending' => array('', 'char'),
            'status_success' => array('', 'char'),
            'status_canceled' => array('', 'char'),
            'countries' => array(0, 'char'),
            'min_amount' => array(0, 'int'),
            'max_amount' => array(0, 'int'),
            'cost_per_transaction' => array(0, 'int'),
            'cost_percent_total' => array(0, 'int'),
            'tax_id' => array(0, 'int')
        );
        $this->addVarsToPushCore($varsToPush);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * @return string[]
     */
    public function getTableSQLFields(): array
    {
        return array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => ' int(11) UNSIGNED',
            'order_number' => ' char(32)',
            'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED',
            'payment_name' => 'VARCHAR(75) NOT NULL DEFAULT "Payfast"',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2)',
            'cost_percent_total' => ' decimal(10,2)',
            'tax_id' => ' smallint(1)',
            'payfast_response' => ' varchar(255)  ',
            'payfast_response_payment_date' => ' char(28)'
        );
    }

    /**
     * @param $cart
     * @param $order
     * @return bool|null
     * @throws Exception
     */
    public function plgVmConfirmedOrder($cart, $order): ?bool
    {
        $retvar = null;

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            $retvar = null;
        } elseif (!$this->selectedThisElement($method->payment_element)) {
            $retvar = false;
        } else {
            $session = Factory::getApplication()->getSession();
            $return_context = $session->getId();
            $this->_debug = $method->debug;
            $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number);
            if (!class_exists('VirtueMartModelOrders')) {
                require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . PF_ORDER;
            }
            if (!class_exists('VirtueMartModelCurrency')) {
                require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php';
            }

            $dbValues['payment_name'] = $this->renderPluginName($method);
            if (!empty($method->payment_info)) {
                $dbValues['payment_name'] .= '<br />' . $method->payment_info;
            }

            $new_status = '';
            $vendorModel = new VirtueMartModelVendor();
            $vendorModel->setId(1);
            $this->getPaymentCurrency($method);
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'
                . $db->quote($method->payment_currency) . '" ';
            $db->setQuery($q);
            $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
            $totalInPaymentCurrency = round(
                $paymentCurrency->convertCurrencyTo(
                    $method->payment_currency,
                    $order['details']['BT']->order_total,
                    false
                ),
                2
            );
            $payfastDetails = $this->_getPayfastDetails($method);

            if (empty($payfastDetails['merchant_id'])) {
                vmInfo(JText::_('VMPAYMENT_PAYFAST_MERCHANT_ID_NOT_SET'));
                $retvar = false;
            } else {
                $post_variables = array(
                    // Merchant details
                    'merchant_id' => $payfastDetails['merchant_id'],
                    'merchant_key' => $payfastDetails['merchant_key'],
                    'return_url' => JROUTE::_(
                        JURI::root() .
                        'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&pm=' .
                        $order['details']['BT']->virtuemart_paymentmethod_id .
                        "&o_id={$order['details']['BT']->order_number}"
                    ),
                    'cancel_url' => JROUTE::_(
                        JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginUserPaymentCancel&on=' .
                        $order['details']['BT']->order_number . '&pm=' .
                        $order['details']['BT']->virtuemart_paymentmethod_id
                    ),
                    'notify_url' => JROUTE::_(
                        JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginnotification&tmpl=component&on=' .
                        $order['details']['BT']->order_number . '&pm=' .
                        $order['details']['BT']->virtuemart_paymentmethod_id . "&XDEBUG_SESSION_START=session_name" .
                        "&o_id={$order['details']['BT']->order_number}"
                    ),

                    // User details
                    'name_first' => $order['details']['BT']->first_name,
                    'name_last' => $order['details']['BT']->last_name,
                    'email_address' => $order['details']['BT']->email,

                    // Item details
                    'm_payment_id' => $order['details']['BT']->order_number,
                    'amount' => number_format(sprintf("%01.2f", $totalInPaymentCurrency), 2, '.', ''),
                    'item_name' => JText::_(
                            'VMPAYMENT_payfast_ORDER_NUMBER'
                        ) . ': ' . $order['details']['BT']->order_number,

                    //'currency_code' => $currency_code_3,
                    'custom_str1' => 'PF_' . PF_SOFTWARE_NAME . '_' . PF_SOFTWARE_VER . '_' . PF_MODULE_VER,
                    'custom_str2' => 'User ID: ' . $order['details']['BT']->virtuemart_user_id,

                );
                $pfOutput = '';
                // Create output string
                foreach ($post_variables as $key => $val) {
                    $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
                }

                $passPhrase = $method->payfast_passphrase;
                if (empty($passPhrase)) {
                    $pfOutput = substr($pfOutput, 0, -1);
                } else {
                    $pfOutput = $pfOutput . "passphrase=" . urlencode($passPhrase);
                }

                $post_variables['signature'] = md5($pfOutput);
                // Prepare data that should be stored in the database
                $dbValues['order_number'] = $order['details']['BT']->order_number;
                $dbValues['payment_name'] = $this->renderPluginName($method);
                $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
                $dbValues['payfast_custom'] = $return_context;
                $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
                $dbValues['cost_percent_total'] = $method->cost_percent_total;
                $dbValues['payment_currency'] = $method->payment_currency;
                $dbValues['payment_order_total'] = $totalInPaymentCurrency;
                $dbValues['tax_id'] = $method->tax_id;
                $this->storePSPluginInternalData($dbValues);
                $html = '<form action="' . $payfastDetails['url'] . '" method="post" name="vm_payfast_form" >';
                $html .= '<input type="image" name="submit"
 src="images/stories/virtuemart/payment/payfast.svg" alt="Click to pay with Payfast"
  style="width: 122px;vertical-align: middle;"/>';
                foreach ($post_variables as $name => $value) {
                    $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
                }
                $html .= '</form>';
                $html .= ' <script type="text/javascript">';
                $html .= ' document.vm_payfast_form.submit();';
                $html .= ' </script>';
                // 2 = don't delete the cart, don't send email and don't redirect
                $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $new_status);
            }
        }

        return $retvar;
    }

    /**
     * @param $method
     * @return array
     */
    public function _getPayfastDetails($method): array
    {
        if ($method->sandbox) {
            $sandBoxMerchantId = empty($method->payfast_merchant_id)
                ? SANDBOX_MERCHANT_ID
                : $method->payfast_merchant_id;
            $sandBoxMerchantKey = empty($method->payfast_merchant_key)
                ? SANDBOX_MERCHANT_KEY
                : $method->payfast_merchant_key;
            $payfastDetails = array(
                'merchant_id' => $sandBoxMerchantId,
                'merchant_key' => $sandBoxMerchantKey,
                'url' => 'https://sandbox.payfast.co.za/eng/process'
            );
        } else {
            $payfastDetails = array(
                'merchant_id' => $method->payfast_merchant_id,
                'merchant_key' => $method->payfast_merchant_key,
                'url' => 'https://www.payfast.co.za/eng/process'
            );
        }

        return $payfastDetails;
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return false|void|null
     */
    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
            // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * @param $html
     * @return bool|null
     * @throws Exception
     */
    public function plgVmOnPaymentResponseReceived(&$html): ?bool
    {
        // the payment itself should send the parameter needed.
        $payment_data = Factory::getApplication()->input->getArray();
        $virtuemart_paymentmethod_id = $payment_data['pm'] ?? 0;
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
            // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
        $order_number = $payment_data['o_id'];
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . PF_ORDER;
        }

        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        $html = $this->getPaymentResponseHtml();
        if ($virtuemart_order_id) {
            if (!class_exists('VirtueMartCart')) {
                require JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php';
            }

            // get the correct cart / session
            $cart = VirtueMartCart::getCart();
            // send the email ONLY if payment has been accepted
            if (!class_exists('VirtueMartModelOrders')) {
                require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . PF_ORDER;
            }

            $cart->emptyCart();
        }

        return true;
    }

    /**
     * @return string
     */
    private function getPaymentResponseHtml(): string
    {
        return "";
    }

    /**
     * @return bool|null
     * @throws Exception
     */
    public function plgVmOnUserPaymentCancel(): ?bool
    {
        $paymentData = JFactory::getApplication()->input->getArray();
        $virtuemartPaymentmethodId = $paymentData['pm'];
        $orderNumber = $paymentData['on'];
        if (!($method = $this->getVmPluginMethod($virtuemartPaymentmethodId))) {
            return null;
            // Another method was selected, do nothing
        }

        $virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber);
        $payment = $this->getDataByOrderId($virtuemartOrderId);
        $paymentFields = json_decode(json_encode($payment), true);
        $paymentFields['paygate_response'] = 'User cancelled';
        $paymentFields['paygate_response_payment_date'] = date('Y-m-d H:i:s');

        $this->storePSPluginInternalData($paymentFields);
        $orderModel = new VirtueMartModelOrders();
        $order['virtuemart_order_id'] = $virtuemartOrderId;
        $order['order_status'] = $method->status_canceled;
        $order['customer_notified'] = 0;
        $order['comments'] = 'The transaction was cancelled by the user';

        VmInfo(vmText::_('VMPAYMENT_PAYFAST_PAYMENT_CANCELLED'));
        try {
            $orderModel->updateStatusForOneOrder($virtuemartOrderId, $order);
        } catch (Exception $e) {
            $this->logInfo('Email sending error: ' . $e->getMessage(), 'ERROR');
        }

        return true;
    }

    /**
     * @return true
     * @throws Exception
     */
    public function plgVmOnPaymentNotification()
    {
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
        }

        // Include Payfast Common File
        $paymentRequest = new PaymentRequest(true);

        // Variable Initialization
        $pfError = false;
        $pfErrMsg = '';
        $pfData = array();
        $pfParamString = '';
        $app = Factory::getApplication();
        $moduleInfo = [
            'pfSoftwareName' => PF_SOFTWARE_NAME,
            'pfSoftwareVer' => PF_SOFTWARE_VER,
            'pfSoftwareModuleName' => PF_MODULE_NAME,
            'pfModuleVer' => PF_MODULE_VER,
        ];

        // Get data sent by Payfast
        $paymentRequest->pflog('Get posted data');
        // Posted variables from ITN
        $pfData = $paymentRequest->pfGetData();
        $payfast_data = $pfData;

        if (!is_array($payfast_data) || empty($payfast_data['m_payment_id'])) {
            vmError('Error: PayFast data is missing or invalid.');
            return false;
        }

        $paymentRequest->pflog('Payfast Data: ' . print_r($pfData, true));
        if ($pfData === false) {
            $pfError = true;
            $pfErrMsg = $paymentRequest::PF_ERR_BAD_ACCESS;
        }

        $order_number = $payfast_data['m_payment_id'];
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($payfast_data['m_payment_id']);
        $this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id);
        if (!$virtuemart_order_id) {
            $this->_debug = true;
            // force debug here
            $this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id not found ', 'ERROR');
            exit;
        }

        $payment = $this->getDataByOrderId($virtuemart_order_id);
        $method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
        $pfHost = ($method->sandbox ? 'sandbox' : 'www') . '.payfast.co.za';

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->_debug = $method->debug;
        if (!$payment) {
            $this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');

            return null;
        }
        $this->logInfo('payfast_data ' . implode('   ', $payfast_data));
        $paymentRequest->pflog('Payfast ITN call received');
        // Verify security signature
        if (!$pfError) {
            $paymentRequest->pflog('Verify security signature');
            $passPhrase = $method->payfast_passphrase;
            $pfPassPhrase = empty($passPhrase) ? null : $passPhrase;
            // If signature different, log for debugging
            if (!$paymentRequest->pfValidSignature($pfData, $pfParamString, $pfPassPhrase)) {
                $pfError = true;
                $pfErrMsg = $paymentRequest::PF_ERR_INVALID_SIGNATURE;
            }
        }

        // Verify data received
        if (!$pfError) {
            $paymentRequest->pflog('Verify data received');
            $pfValid = $paymentRequest->pfValidData($moduleInfo, $pfHost, $pfParamString);
            if (!$pfValid) {
                $pfError = true;
                $pfErrMsg = $paymentRequest::PF_ERR_BAD_ACCESS;
            }
        }

        // Check data against internal order
        if (!$pfError && !$paymentRequest->pfAmountsEqual($pfData['amount_gross'], $payment->payment_order_total)) {
            $pfError = true;
            $pfErrMsg = $paymentRequest::PF_ERR_AMOUNT_MISMATCH;
        }

        // Check status and update order
        if (!$pfError) {
            $paymentRequest->pflog('Check status and update order');
            switch ($pfData['payment_status']) {
                case 'COMPLETE':
                    $paymentRequest->pflog('- Complete');
                    $new_status = $method->status_success;
                    break;
                case 'FAILED':
                    $paymentRequest->pflog('- Failed');
                    $new_status = $method->status_canceled;
                    break;
                case 'PENDING':
                    $paymentRequest->pflog('- Pending');
                    // Need to wait for "Completed" before processing
                    break;
                default:
                    // If unknown status, do nothing (safest course of action)
                    break;
            }
        }


        // If an error occurred
        if ($pfError) {
            $paymentRequest->pflog('Error occurred: ' . $pfErrMsg);
        }

        // get all know columns of the table
        $response_fields['virtuemart_order_id'] = $virtuemart_order_id;
        $response_fields['order_number'] = $order_number;
        $response_fields['virtuemart_payment_method_id'] = $payment->virtuemart_paymentmethod_id;
        $response_fields['payment_name'] = $this->renderPluginName($method);
        $response_fields['cost_per_transaction'] = $payment->cost_per_transaction;
        $response_fields['cost_percent_total'] = $payment->cost_percent_total;
        $response_fields['payment_currency'] = $payment->payment_currency;
        $response_fields['payment_order_total'] = $payment->payment_order_total;
        $response_fields['tax_id'] = $method->tax_id;
        $response_fields['payfast_response'] = $pfData['payment_status'];
        $response_fields['payfast_response_payment_date'] = date('Y-m-d H:i:s');
        $this->storePSPluginInternalData($response_fields);
        $this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status);
        if ($pfData['payment_status'] == 'COMPLETE') {
            // send the email only if payment has been accepted
            if (!class_exists('VirtueMartModelOrders')) {
                require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . PF_ORDER;
            }

            $isEmailSet = empty($pfData['email_address']) ? 0 : 1;
            $modelOrder = new VirtueMartModelOrders();
            $order['order_status'] = $new_status;
            $order['virtuemart_order_id'] = $virtuemart_order_id;
            $order['customer_notified'] = $isEmailSet;
            $order['comments'] = JTExt::sprintf(
                'VMPAYMENT_PAYFAST_PAYMENT_CONFIRMED',
                $order_number,
                $payfast_data['pf_payment_id']
            );

            try {
                $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order);
            } catch (Exception $e) {
                JFactory::getApplication()->enqueueMessage(
                    'An error occurred while sending the email: '
                    . $e->getMessage(), 'ERROR'
                );
                $this->logInfo('Email sending error: ' . $e->getMessage(), 'ERROR');
            }

            $displayComment = vmText::_('VMPAYMENT_PAYFAST_SUCCESSFUL_PAYMENT');

        } else {
            $displayComment = vmText::_('VMPAYMENT_PAYFAST_DECLINED_PAYMENT');
        }

        // Close log
        $paymentRequest->pflog('', true);

        return true;
    }


    /**
     * @param $virtuemart_order_id
     * @param $payment_method_id
     * @return string|null
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id): ?string
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null;
            // Another method was selected, do nothing
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
            . 'WHERE `virtuemart_order_id` = ' . $db->quote($virtuemart_order_id);
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            return '';
        }

        $this->getPaymentCurrency($paymentTable);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'
            . $db->quote($paymentTable->payment_currency) . '" ';
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery($q);
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $code = "payfast_response_";
        foreach ($paymentTable as $key => $value) {
            if (str_starts_with($key, $code)) {
                $html .= $this->getHtmlRowBE($key, $value);
            }
        }
        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @param $jplugin_id
     *
     * @return bool|null
     * @author Valérie Isaksen
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id): ?bool
    {
        return $this->onStoreInstallPluginTable();
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     *
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, int $selected, &$htmlIn): bool
    {
        if (isset($_POST['virtuemart_paymentmethod_id'])) {
            $selected = (int)htmlspecialchars($_POST['virtuemart_paymentmethod_id']);
        }
        if (isset($this->methods) && (int)$this->methods[0]->virtuemart_paymentmethod_id === $selected) {
            $cart->cartData['paymentName'] = "Payfast";
        }

        return $this->displayListFE($cart, $selected, $htmlIn);
    }


    /**
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $cart_prices_name
     * @return bool|null
     */
    public function plgVmOnSelectedCalculatePricePayment(
        VirtueMartCart $cart,
        array          &$cart_prices,
                       &$cart_prices_name
    ): ?bool
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart $cart cart: the cart object
     *
     * @return null if no plugin was found, 0 if more than one plugin was found,
     *  virtuemart_xxx_id if only one plugin is found
     *
     * @author Valerie Isaksen
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return void
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name): void
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when printing an Order
     * It displays the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    public function plgVmonShowOrderPrintPayment($order_number, int $method_id): mixed
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param $name
     * @param $id
     * @param $data
     * @return bool
     * @author Oscar van Eijk
     *
     * public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
     * return null;
     * }
     */
    public function plgVmDeclarePluginParamsPayment($name, $id, $data): bool
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    /**
     * @param $data
     * @return bool
     */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data): bool
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * @param $psType
     * @param $name
     * @param $id
     * @param $xParams
     * @param $varsToPush
     * @return bool
     */
    public function plgVmGetTablePluginParams($psType, $name, $id, &$xParams, &$varsToPush): bool
    {
        return $this->getTablePluginParams($psType, $name, $id, $xParams, $varsToPush);
    }

    /**
     * @param $name
     * @param $id
     * @param $table
     * @return bool
     */
    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table): bool
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart
     * @param $method
     * @param $cart_prices : cart prices
     * @return bool: if the conditions are fulfilled, false otherwise
     *
     * @author: Valerie Isaksen
     */
    protected function checkConditions($cart, $method, $cart_prices): bool
    {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount && $amount <= $method->max_amount
            ||
            ($method->min_amount <= $amount && ($method->max_amount == 0)));
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (in_array($address['virtuemart_country_id'], $countries) || empty($countries) || $amount_cond) {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    protected function getVmPluginCreateTableSQL(): string
    {
        return $this->createTableSQL('Payment Payfast Table');
    }
}
