<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/22
 * Time: 上午11:06
 */

class PChomePay_PChomePayPayment_Helper_Data extends Mage_Payment_Helper_Data
{
    private $paymentModel = null;
    private $prefix = 'pchomepay_';
    private $moduleName = 'pchomepaypayment';
    private $resultNotify = true;
    private $obtainCodeNotify = false;

    private $errorMessages = array();

    public function __construct()
    {
        $this->paymentModel = Mage::getModel($this->moduleName . '/paymentmodel');
        $this->errorMessages = array(
            'invalidPayment' => $this->__($this->prefix . 'payment_checkout_invalid_payment'),
            'invalidOrder' => $this->__($this->prefix . 'payment_checkout_invalid_order'),
        );
    }

    public function getPendingPaymentStatus()
    {
        if (version_compare(Mage::getVersion(), '1.4.0', '<')) {
            return Mage_Sales_Model_Order::STATE_HOLDED;
        }
        return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
    }

    public function getPaymentTranslation($payment)
    {
        return $this->__($this->prefix . 'payment_text_' . strtolower($payment));
    }
}