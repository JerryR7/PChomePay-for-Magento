<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/18
 * Time: 下午4:09
 */

class PChomePay_PChomePayPayment_Model_PaymentModel extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'PChomePayPayment_PaymentModel';
    protected $_isGateway                   = true;
    protected $_canOrder                    = true;
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = false;
    protected $_canCaptureOnce              = false;
    protected $_canRefund                   = false;
    protected $_canRefundInvoicePartial     = true;
    protected $_canVoid                     = true;
    protected $_canUseInternal              = true;
    protected $_canUseCheckout              = true;
    protected $_canUseForMultishipping      = true;
    protected $_isInitializeNeeded          = false;
    protected $_canFetchTransactionInfo     = true;
    protected $_canReviewPayment            = false;
    protected $_canCreateBillingAgreement   = false;
    protected $_canManageRecurringProfiles  = true;
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
        return Mage::getUrl($this->moduleName . '/payment/redirect', array('_secure' => false));
    }

    public function assignData($data)
    {
        $pchomePayHelper = Mage::helper($this->moduleName . '');
        $pchomePayHelper->destroyChoosenPayment();
        $choosenPayment = $data->getPChomePayChoosenPayment();
        $pchomePayHelper->setChoosenPayment($choosenPayment);
        return $this;
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
        return $this->getMagentoConfig($this->prefix . $name);
    }

    public function getMagentoConfig($name)
    {
        return $this->getConfigData($name);
    }

    public function loadLibrary() {
        foreach ($this->libraryList as $path) {
            include_once($path);
        }
    }

    public function getHelper() {
        $merchant_id = $this->getPChomePayConfig('merchant_id');
        return new PChomePayCartLibrary(array('merchantId' => $merchant_id));
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

}