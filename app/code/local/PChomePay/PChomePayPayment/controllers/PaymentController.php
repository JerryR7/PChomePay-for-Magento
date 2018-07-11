<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/25
 * Time: 上午11:03
 */

class PChomePay_PChomePayPayment_PaymentController extends Mage_Core_Controller_Front_Action
{
    private $order;
    private $version = '1.4';
    private $order_condition;
    private $order_notification;

    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * when customer selects pchomepaypayment payment method
     */
    public function redirectAction() {
        try {
            $session = $this->_getCheckout();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());

            if ($order->getState() != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $order->setState(
                    Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, $this->getPendingPaymentStatus(), '轉向至PChomePay中，請稍候...'
                )->save();

                $order->sendNewOrderEmail();
                $order->setEmailSent(true);
            }

            $this->_redirect('pchomepaypayment/payment/pchomepay');

            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }


    ///////////////////////////////// PChomePay Payment Action /////////////////////////////////////////////
    public function pchomepayAction() {
        try {
            // =========================== GET PARAMS OP ===========================
            // init
            $session = $this->_getCheckout();
            $this->order = Mage::getModel('sales/order');
            $this->order->loadByIncrementId($session->getLastRealOrderId());

            // 在controller須先呼叫model cvs後才能使用getConfigData()
            $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');

            // testmode
            $pchomepay_testmode = $mageModel->getPChomePayConfig('testMode');
            Mage::log('pchomepay_testmode: ' . $pchomepay_testmode);

            $baseURL = $pchomepay_testmode == '1' ? $this->sb_base_url : $this->base_url;

            $appID = $mageModel->getPChomePayConfig('appID');
            $secret = $mageModel->getPChomePayConfig('secret');
            $sandboxSecret = $mageModel->getPChomePayConfig('sandboxSecret');
            $testMode = $mageModel->getPChomePayConfig('testMode');

            $mageModel->paymentProcessSetting($appID, $secret, $sandboxSecret, $testMode);
//            $mageModel->getToken();

            $data = '{"order_id":"erictest0000007","pay_type": ["ATM","CARD","ACCT","EACH"],"amount":10,"return_url": "http://www.darenprint.com/pchomereturn","items":[{"name":"\u8d85\u5927\u9846\u82ad\u6a02","url":"_http:\/\/anywhere.com"},{"name":"\u8d85\u5927\u9846\u82ad\u6a02","url":"_http:\/\/anywhere.com"}],"buyer_email":"mychat.aa@gmail.com","atm_info":{"expire_days":3},"card_info":[{"installment":3, "rate":null},{"installment":6, "rate":0}]}';

            $result = $mageModel->postPayment($data);

            Mage::log($result);

            exit;


            // =========================== POST DATA OP ===========================
            $results = "<form method='post' action='" . $post_url . "' name='Spgateway'>";
            foreach ($this->generateSPGFormData() as $key => $value) {
                $results .= "<input type='hidden' name='".$key."' value='" . $value . "' />";
            }
            $results .= "</form></body><script>Spgateway.submit();</script>";

            // =========================== POST DATA ED ===========================

            echo $results;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * pchomepaypayment returns POST variables to this action
     */
    public function responseAction() {
        $status = $this->responseCheck();
        $request = $this->getRequest()->getPost();

        $MerchantOrderNo = $request['MerchantOrderNo'];
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($MerchantOrderNo);

        if ($status == 'success') {
            $order->setState('Complete', 'Complete', 'PChomePayPayment success!')->save();
        } else {
            $order->setState('Pending', 'Pending', 'PChomePayPayment failure!')->save();
        }
    }

    public function viewAction() {
        $status = $this->responseCheck();
        $request = $this->getRequest()->getPost();

        if ($status == 'success') {
            //A Success Message
            Mage::getSingleton('core/session')->addSuccess("PChomePayPayment success!");
        } else {
            //A Error Message
            Mage::getSingleton('core/session')->addError("PChomePayPayment failure!");
        }

        //These lines are required to get it to work
        session_write_close(); //THIS LINE IS VERY IMPORTANT!

        $this->_redirect('checkout/cart');
    }

    public function responseCheck() {
        $result = "";

        // 在controller須先呼叫model cvs後才能使用getConfigData()
        $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_Payment');

        $hashiv = trim($mageModel->getConfigData('pchomepay_hashiv'));
        $hashkey = trim($mageModel->getConfigData('pchomepay_hashkey'));

        $request = $this->getRequest()->getPost();

        $Amt = $request['Amt'];
        $MerchantID = $request['MerchantID'];
        $MerchantOrderNo = $request['MerchantOrderNo'];
        $TradeNo = $request['TradeNo'];
        $Status = $request['Status'];
        $CheckCode = $request['CheckCode'];

        // 訂單
        $session = $this->_getCheckout();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($MerchantOrderNo);

        // 金額
        $price = $this->translateNumberFormat($order['base_grand_total']);

        // check code
        $check_code = "&Amt=" . $price . "&MerchantID=" . $MerchantID . "&MerchantOrderNo=" . $MerchantOrderNo . "&TradeNo=" . $TradeNo;
        $check_code = "HashIV=" . $hashiv . $check_code . "&HashKey=" . $hashkey;
        $check_code = strtoupper(hash("sha256", $check_code));

        if (($Status == 'SUCCESS' or $Status = 'CUSTOM') && $Amt == $price && $CheckCode == $check_code) {
            $result = 'success';
        } else {
            $result = 'failed';
        }

        return $result;
    }

    protected function getPendingPaymentStatus() {
        return Mage::helper('pchomepaypayment')->getPendingPaymentStatus();
    }

    Public function translateNumberFormat($price) {
        $priceTranslate = explode(".", $price);
        $result = $priceTranslate[0];

        return $result;
    }

}