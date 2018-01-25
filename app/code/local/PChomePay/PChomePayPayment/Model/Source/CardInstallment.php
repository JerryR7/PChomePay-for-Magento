<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/22
 * Time: 下午1:56
 */

class PChomePay_PChomePayPayment_Model_Source_CardInstallment
{
    public function toOptionArray()
    {
        $optionArray = array();
        $list = array(
            'CRD_0',
            'CRD_3',
            'CRD_6',
            'CRD_12'
        );
        foreach ($list as $installment) {
            array_push($optionArray, array('value' => $installment, 'label' => Mage::helper('adminhtml')->__('pchomepay_payment_text_' . strtolower($installment))));
        }

        return $optionArray;
    }
}