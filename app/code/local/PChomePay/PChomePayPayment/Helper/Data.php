<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/22
 * Time: 上午11:06
 */

include_once('Library/PChomePayClient.php');

class PChomePay_PChomePayPayment_Helper_Data extends Mage_Payment_Helper_Data
{
    private $prefix = 'pchomepay_';

    public function getPendingPaymentStatus()
    {
        Mage::log(123);
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