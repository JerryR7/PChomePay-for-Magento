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

    private $debug;
    private $appID;
    private $secret;
    private $tokenURL;
    private $postPaymentURL;
    private $getPaymentURL;
    private $getRefundURL;
    private $postRefundURL;
    private $userAuth;

    const BASE_URL = "https://api.pchomepay.com.tw/v1";
    const SB_BASE_URL = "https://sandbox-api.pchomepay.com.tw/v1";

    public function paymentProcessSetting($appID, $secret, $sandboxSecret, $sandBox = false, $debug = false)
    {
        $baseURL = $sandBox ? self::SB_BASE_URL : self::BASE_URL;

        $this->debug = $debug;
        $this->appID = $appID;
        $this->secret = $sandBox ? $sandboxSecret : $secret;

        $this->tokenURL = $baseURL . "/token";
        $this->postPaymentURL = $baseURL . "/payment";
        $this->getPaymentURL = $baseURL . "/payment/{order_id}";
        $this->getRefundURL = $baseURL . "/refund/{refund_id}";
        $this->postRefundURL = $baseURL . "/refund";

        $this->userAuth = "{$this->appID}:{$this->secret}";
        }

    // 紀錄log
    private function log($message)
    {
        Mage::log($message);
    }

    // 建立訂單
    public function postPayment($data)
    {
        $this->log($this->postPaymentURL);
        return $this->post_request($this->postPaymentURL, $data);
    }

    // 建立退款
    public function postRefund($data)
    {
        return $this->post_request($this->postRefundURL, $data);
    }

    // 查詢訂單
    public function getPayment($orderID)
    {
        if (!is_string($orderID) || stristr($orderID, "/")) {
            throw new Exception('Order does not exist!', 20002);
        }

        return $this->get_request(str_replace("{order_id}", $orderID, $this->getPaymentURL));
    }

    // 取Token
    protected function getToken()
    {
        Mage::log($this->userAuth);
        $userAuth = "{$this->appID}:{$this->secret}";

        $client = new Varien_Http_Client($this->tokenURL);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($userAuth),
        ));

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    protected function post_request($method, $postdata)
    {
        $token = $this->getToken();

        $client = new Varien_Http_Client($method);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'pcpay-token' => $token->token,
        ));
        $client->setRawData($postdata);

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($response);
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    protected function get_request($method)
    {
        $token = $this->getToken();

        $client = new Varien_Http_Client($method);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'pcpay-token' => $token->token,
        ));

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($response);
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    private function handleResult($result)
    {
        $jsonErrMap = [
            JSON_ERROR_NONE => 'No error has occurred',
            JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded	PHP 5.3.3',
            JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given	PHP 5.5.0'
        ];

        $obj = json_decode($result);

        $err = json_last_error();

        if ($err) {
            $errStr = "($err)" . $jsonErrMap[$err];
            if (empty($errStr)) {
                $errStr = " - unknow error, error code ({$err})";
            }
            $this->log("server result error($err) {$errStr}:$result");
            throw new Exception("server result error($err) {$errStr}:$result");
        }

        if (isset($obj->error_type)) {
            $this->log("\n錯誤類型：" . $obj->error_type . "\n錯誤代碼：" . $obj->code . "\n錯誤訊息：" . ApiException::getErrMsg($obj->code));
            throw new Exception("交易失敗，請聯絡網站管理員。錯誤代碼：" . $obj->code);
        }

        if (empty($obj->token) && empty($obj->order_id)) {

            return false;
        }

        if (isset($obj->status_code)) {
            $this->log("訂單編號：" . $obj->order_id . " 已失敗。\n原因：" . OrderStatusCodeEnum::getErrMsg($obj->status_code));
        }

        return $obj;
    }







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

    /**
     * prepare params array to send it to gateway page via POST
     *
     * @return array
     */
    public function getFormFields() {
        // get transaction amount and currency
        if ($this->getConfigData('use_store_currency')) {
            $price = number_format($this->getOrder()->getGrandTotal(), 2, '.', '');
            $currency = $this->getOrder()->getOrderCurrencyCode();
        } else {
            $price = number_format($this->getOrder()->getBaseGrandTotal(), 2, '.', '');
            $currency = $this->getOrder()->getBaseCurrencyCode();
        }

        $billing = $this->getOrder()->getBillingAddress();

        $locale = explode('_', Mage::app()->getLocale()->getLocaleCode());

        if (is_array($locale) && !empty($locale))
            $locale = $locale[0];
        else
            $locale = $this->getDefaultLocale();

        return $params;
    }

    public function getValidPayments()
    {
        $payments = $this->getPChomePayConfig('payment_methods');
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
            include_once('PChomePay/PChomePayPayment/Helper/Library/'. $path);
        }
    }

    public function getPChomePayClient() {
        $appID = $this->getPChomePayConfig('appID');
        $secret = $this->getPChomePayConfig('secret');
        $sandboxSecret = $this->getPChomePayConfig('sandboxSecret');
        $testMode = $this->getPChomePayConfig('testMode');
        $this->loadLibrary();
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