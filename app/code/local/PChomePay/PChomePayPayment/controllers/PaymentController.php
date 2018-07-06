<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/25
 * Time: 上午11:03
 */

class PChomePay_PChomePayPayment_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout() {
        Mage::log(123123123);

        return Mage::getSingleton('checkout/session');
    }

    /**
     * when customer selects pchomepaypayment payment method
     */
    public function redirectAction() {
        Mage::log(123123123);
        try {
            $session = $this->_getCheckout();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());

            if ($order->getState() != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $order->setState(
                    Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, $this->_getPendingPaymentStatus(), Mage::helper('pchomepaypayment')->__('轉向至PChomePay中，請稍候...')
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

            if (Mage::getSingleton('customer/session')->isLoggedIn()) {
                $customer = Mage::getSingleton('customer/session')->getCustomer();
                $CustomerName = $customer->getName();
                $CustomerEmail = $customer->getEmail();
                $CustomerTelephone = $customer->getPrimaryBillingAddress()->getTelephone();
            } else {
                $CustomerName = $order->getCustomerName();
                $CustomerEmail = $order->getCustomerEmail();
                $CustomerTelephone = $order->getShippingAddress()->getTelephone();
            }

            // price
            $price = $this->translateNumberFormat($order['base_grand_total']);

            // 在controller須先呼叫model cvs後才能使用getConfigData()
            $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');

            // merchantID, hashkey, hashiv
            $pchomepay_merchantID = trim($mageModel->getConfigData('pchomepay_merchantID'));
            $pchomepay_hashkey = trim($mageModel->getConfigData('pchomepay_hashkey'));
            $pchomepay_hashiv = trim($mageModel->getConfigData('pchomepay_hashiv'));
            $pchomepay_locale = $mageModel->getConfigData('pchomepay_locale');

            // order_amt, order_id
            $order_amt = $price;
            $order_id = $session->getLastRealOrderId();

            // testmode
            $pchomepay_testmode = $mageModel->getConfigData('pchomepay_testMode');
            // =========================== GET PARAMS ED ===========================
            // =========================== PREPARE POST DATA OP ===========================
            $MerchantID = $pchomepay_merchantID;
            $hashkey = $pchomepay_hashkey;
            $hashiv = $pchomepay_hashiv;

            $amt = (int) $order_amt;
            $ReturnURL = Mage::getUrl('pchomepaypayment/processing/view');
            $NotifyURL = Mage::getUrl('pchomepaypayment/processing/response');
            $ClientBackURL = Mage::getUrl('/');
            $Email = $CustomerEmail;
            $LoginType = '0';
            $MerchantOrderNo = $order_id;

            $timestamp = time();
            $version = '1.1';
            $RespondType = 'String';
            $itemdesc = '';

            // create check code
            $check_code = "&Amt=" . $amt . "&MerchantID=" . $MerchantID . "&MerchantOrderNo=" . $MerchantOrderNo . "&TimeStamp=" . $timestamp . "&Version=" . $version;
            $check_code = "HashKey=" . $hashkey . $check_code . "&HashIV=" . $hashiv;
            $check_code = strtoupper(hash("sha256", $check_code));

            // =========================== PREPARE POST DATA ED ===========================
            // =========================== POST DATA OP ===========================

            $post_url = ($pchomepay_testmode == '1') ? 'https://ccore.pchomepay.com/MPG/mpg_gateway' : 'https://core.pchomepay.com/MPG/mpg_gateway';

            $results = "<form method='post' action='" . $post_url . "' name='PChomePay'>";
            $results .= "<input type='hidden' name='MerchantID' value='" . $MerchantID . "' />";
            $results .= "<input type='hidden' name='RespondType' value='" . $RespondType . "' />";
            $results .= "<input type='hidden' name='CheckValue' value='" . $check_code . "' />";
            $results .= "<input type='hidden' name='TimeStamp' value='" . time() . "' />";
            $results .= "<input type='hidden' name='Version' value='" . $version . "' />";
            $results .= "<input type='hidden' name='MerchantOrderNo' value='" . $MerchantOrderNo . "' />";
            $results .= "<input type='hidden' name='Amt' value='" . $amt . "' />";
            $results .= "<input type='hidden' name='ReturnURL' value='" . $ReturnURL . "' />";
            $results .= "<input type='hidden' name='NotifyURL' value='" . $NotifyURL . "' />";
            $results .= "<input type='hidden' name='ClientBackURL' value='" . $ClientBackURL . "' />";
            $results .= "<input type='hidden' name='Email' value='" . $Email . "' />";
            $results .= "<input type='hidden' name='LoginType' value='" . $LoginType . "' />";
            $results .= "<input type='hidden' name='Receiver' value='" . $CustomerName . "' />";
            $results .= "<input type='hidden' name='Tel1' value='" . $CustomerTelephone . "' />";
            $results .= "<input type='hidden' name='Tel2' value='" . $CustomerTelephone . "' />";
            $results .= "<input type='hidden' name='LangType' value='" . $pchomepay_locale . "' />";

            $orderItems = $order->getItemsCollection();
            $item_cnt = 1;
            foreach ($orderItems as $item) {
                if ($item_cnt != count($orderItems)) {
                    $itemdesc .= $item->getName() . " × " . $item->getQtyToInvoice() . "，";
                } elseif ($item_cnt == count($orderItems)) {
                    $itemdesc .= $item->getName() . " × " . $item->getQtyToInvoice();
                }

                $product_id = $item->product_id;
                $product_price = (int) $item->getPrice();
                $product_name = $item->getName();
                $product_qty = $item->getData('qty_ordered');

                $product_price = $this->translateNumberFormat($product_price);
                $product_qty = $this->translateNumberFormat($product_qty);

                $results .= "<input type='hidden' name='Title" . $item_cnt . "' value='" . $product_name . "' />";
                $results .= "<input type='hidden' name='Desc" . $item_cnt . "' value='" . $product_name . "' />";
                $results .= "<input type='hidden' name='Pid" . $item_cnt . "' value='" . $product_id . "' />";
                $results .= "<input type='hidden' name='Qty" . $item_cnt . "' value='" . $product_qty . "' />";
                $results .= "<input type='hidden' name='Price" . $item_cnt . "' value='" . $product_price . "' />";

                $item_cnt++;
            }

            $results .= "<input type='hidden' name='ItemDesc' value='" . $itemdesc . "' />";
            $results .= "<input type='hidden' name='Count' value='" . ($item_cnt - 1) . "' />";
            $results .= "</form></body><script>PChomePay.submit();</script>";

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

    protected function _getPendingPaymentStatus() {
        return Mage::helper('pchomepaypayment')->getPendingPaymentStatus();
    }

    Public function translateNumberFormat($price) {
        $priceTranslate = explode(".", $price);
        $result = $priceTranslate[0];

        return $result;
    }

}