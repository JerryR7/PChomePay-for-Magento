<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/18
 * Time: 下午4:09
 */

class PChomePay_PChomePayPayment_Model_PaymentModel extends Mage_Payment_Model_Method_Abstract
{
    const FLAG_SHOW_CONFIG = 'showConfig';
    const FLAG_SHOW_CONFIG_FORMAT = 'showConfigFormat';

    private $request;

    public function checkForConfigRequest($observer) {
        $this->request = $observer->getEvent()->getData('front')->getRequest();
        if($this->request->{self::FLAG_SHOW_CONFIG} === 'true'){
            $this->setHeader();
            $this->outputConfig();
        }
    }

    private function setHeader() {
        $format = isset($this->request->{self::FLAG_SHOW_CONFIG_FORMAT}) ?
            $this->request->{self::FLAG_SHOW_CONFIG_FORMAT} : 'xml';
        switch($format){
            case 'text':
                header("Content-Type: text/plain");
                break;
            default:
                header("Content-Type: text/xml");
        }
    }

    private function outputConfig() {
        die(Mage::app()->getConfig()->getNode()->asXML());
    }
}