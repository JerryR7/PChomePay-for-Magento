<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/22
 * Time: 下午1:56
 */

class PChomePay_PChomePayPayment_Model_Source_PaymentMethods
{
    public function toOptionArray()
    {
        $optionArray = array();
        $list = array(
            'CARD',
            'ATM',
            'EACH',
            'ACCT'
        );
        foreach ($list as $payment) {
            array_push($optionArray, array('value' => $payment, 'label' => Mage::helper('adminhtml')->__('pchomepay_payment_text_' . strtolower($payment))));
        }

        return $optionArray;
    }
}