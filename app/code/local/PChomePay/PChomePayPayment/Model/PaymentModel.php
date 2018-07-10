<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/18
 * Time: 下午4:09
 */

class PChomePay_PChomePayPayment_Model_PaymentModel extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'pchomepaypayment';
    protected $_isGateway = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_paymentMethod = '';
    protected $_testUrl = '';
    protected $_order;

    private $moduleName = 'pchomepaypayment';
    private $prefix = 'pchomepay_';
    private $libraryList = array('PChomePayClient.php', 'ApiException.php', 'OrderStatusCodeEnum.php');

    /**
     * Get order model
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder() {
        if (!$this->_order) {
            $this->_order = $this->getInfoInstance()->getOrder();
        }
        return $this->_order;
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl($this->moduleName . '/payment/redirect');
    }

    /**
     * Return payment method type string
     *
     * @return string
     */
    public function getPaymentMethodType() {
        return $this->_paymentMethod;
    }

    public function getUrl() {
        return $this->_testUrl;
    }

    public function getValidPayments()
    {
        $payments = $this->getPChomePayConfig('payment_methods', true);
        $trimed = trim($payments);
        return explode(',', $trimed);
    }

    public function isValidPayment($choosenPayment)
    {
        $payments = $this->getValidPayments();
        return (in_array($choosenPayment, $payments));
    }

    public function getPChomePayConfig($name)
    {
        return $this->getConfigData($this->prefix . $name);
    }

    public function loadLibrary() {
        foreach ($this->libraryList as $path) {
            include_once($path);
        }
    }

    public function getPChomePayClient() {
        $appID = $this->getPChomePayConfig('appID');
        $secret = $this->getPChomePayConfig('secret');
        $sandboxSecret = $this->getPChomePayConfig('sandboxSecret');
        $testMode = $this->getPChomePayConfig('testMode');
        return new PChomePayClient($appID, $secret, $sandboxSecret, $testMode);
    }

    public function getModuleUrl($action = '')
    {
        if ($action !== '') {
            $route = $this->_code . '/payment/' . $action;
        } else {
            $route = '';
        }
        return $this->getMagentoUrl($route);
    }

    public function getMagentoUrl($route)
    {
        return Mage::getUrl($route);
    }

    /**
     * Refund money
     *
     * @param   Varien_Object $invoicePayment
     * @return  Mage_GoogleCheckout_Model_Payment
     */
    public function refund(Varien_Object $payment, $amount) {
        $transactionId = $payment->getLastTransId();
        $params = $this->_prepareAdminRequestParams();

        $params['cartId'] = 'Refund';
        $params['op'] = 'refund-partial';
        $params['transId'] = $transactionId;
        $params['amount'] = $amount;
        $params['currency'] = $payment->getOrder()->getBaseCurrencyCode();

        $responseBody = $this->processAdminRequest($params);
        $response = explode(',', $responseBody);

        if (count($response) <= 0 || $response[0] != 'A' || $response[1] != $transactionId) {
            Mage::throwException($this->_getHelper()->__('Error during refunding online. Server response: %s', $responseBody));
        }

        return $this;
    }

    /**
     * Capture preatutharized amount
     * @param Varien_Object $payment
     * @param <type> $amount
     */
    public function capture(Varien_Object $payment, $amount) {
        if (!$this->canCapture()) {
            return $this;
        }

        if (Mage::app()->getRequest()->getParam('transId')) {
            // Capture is called from response action
            $payment->setStatus(self::STATUS_APPROVED);
            return $this;
        }
    }

    /**
     * Check refund availability
     *
     * @return bool
     */
    public function canRefund() {
        return $this->getConfigData('enable_online_operations');
    }

    public function canRefundInvoicePartial() {
        return $this->getConfigData('enable_online_operations');
    }

    public function canRefundPartialPerInvoice() {
        return $this->canRefundInvoicePartial();
    }

    public function canCapturePartial() {
        if (Mage::app()->getFrontController()->getAction()->getFullActionName() != 'adminhtml_sales_order_creditmemo_new') {
            return false;
        }

        return $this->getConfigData('enable_online_operations');
    }

    protected function processAdminRequest($params, $requestTimeout = 60) {
        try {
            $client = new Varien_Http_Client();
            $client->setUri($this->getAdminUrl())
                ->setConfig(array('timeout' => $requestTimeout,))
                ->setParameterPost($params)
                ->setMethod(Zend_Http_Client::POST);

            $response = $client->request();
            $responseBody = $response->getBody();

            if (empty($responseBody))
                Mage::throwException($this->_getHelper()->__('alipay API failure. The request has not been processed.'));
            // create array out of response
        } catch (Exception $e) {
            Mage::log($e->getMessage());
            Mage::throwException($this->_getHelper()->__('alipay API connection error. The request has not been processed.'));
        }

        return $responseBody;
    }

    protected function _prepareAdminRequestParams() {
        $params = array
        (
            'authPW' => $this->getConfigData('auth_password'),
            'instId' => $this->getConfigData('admin_inst_id'),
        );

        if ($this->getConfigData('transaction_mode') == 'test') {
            $params['testMode'] = 100;
        }

        return $params;
    }

}