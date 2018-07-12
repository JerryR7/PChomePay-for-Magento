<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/25
 * Time: 上午11:03
 */

class PChomePay_PChomePayPayment_PaymentController extends Mage_Core_Controller_Front_Action
{
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
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());

            // 在controller須先呼叫model cvs後才能使用getConfigData()
            $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');

            // testmode
            $pchomepay_testmode = $mageModel->getPChomePayConfig('testMode');

            $baseURL = $pchomepay_testmode == '1' ? $this->sb_base_url : $this->base_url;

            $appID = $mageModel->getPChomePayConfig('appID');
            $secret = $mageModel->getPChomePayConfig('secret');
            $sandboxSecret = $mageModel->getPChomePayConfig('sandboxSecret');
            $testMode = $mageModel->getPChomePayConfig('testMode');

            $mageModel->paymentProcessSetting($appID, $secret, $sandboxSecret, $testMode);

            $pchomepayRequestData = json_encode($this->getPChomepayPaymentData());

            // =========================== POST DATA OP ===========================
            $result = $mageModel->postPayment($pchomepayRequestData);
            // =========================== POST DATA ED ===========================

            $this->_redirectUrl($result->payment_url);
            return;

        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    private function getPChomepayPaymentData()
    {
        $session = $this->_getCheckout();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');


        $orderID = 'AM' . date('Ymd') . $session->getLastRealOrderId();
        $payType = explode(',', $mageModel->getPChomePayConfig('paymentMethods'));
        $amount = ceil($this->translateNumberFormat($order['base_grand_total']));
        $returnUrl = Mage::getUrl('pchomepaypayment/payment/view');
        $notifyUrl = Mage::getUrl('pchomepaypayment/payment/response');
        $atmExpiredate = $mageModel->getPChomePayConfig('atmExpiredate');

        if (isset($atmExpiredate) && (!preg_match('/^\d*$/', $atmExpiredate) || $atmExpiredate < 1 || $atmExpiredate > 5)) {
            $atmExpiredate = 5;
        }

        $atm_info = (object)['expire_days' => (int)$atmExpiredate];

        $cardInfo = [];

        $cardInstallment = explode(',', $mageModel->getPChomePayConfig('cardInstallment'));

        foreach ($cardInstallment as $items) {
            switch ($items) {
                case 'CRD_3' :
                    $card_installment['installment'] = 3;
                    break;
                case 'CRD_6' :
                    $card_installment['installment'] = 6;
                    break;
                case 'CRD_12' :
                    $card_installment['installment'] = 12;
                    break;
                default :
                    unset($card_installment);
                    break;
            }
            if (isset($card_installment)) {
                $cardInfo[] = (object)$card_installment;
            }
        }

        $orderItems = $order->getItemsCollection();

        $items = [];

        foreach ($orderItems as $item) {
            $productArray = [];
            $product = Mage::getModel('catalog/product')
                ->setStoreId(Mage::app()->getStore()->getId())
                ->load($item->product_id);

            $productName = $item->getName();
            $productUrl = $product->getProductUrl();

            $productArray['name'] = $productName;
            $productArray['url'] = $productUrl;

            $items[] = (object)$productArray;
        }

        $pchomepayRequestData = [
            'order_id' => $orderID,
            'pay_type' => $payType,
            'amount' => $amount,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'items' => $items,
            'atm_info' => $atm_info,
        ];

        if ($cardInfo) $pchomepayRequestData['card_info'] = $cardInfo;

        return $pchomepayRequestData;
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