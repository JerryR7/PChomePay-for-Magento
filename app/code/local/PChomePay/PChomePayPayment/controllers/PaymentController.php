<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2018/1/25
 * Time: 上午11:03
 */

class PChomePay_PChomePayPayment_PaymentController extends Mage_Core_Controller_Front_Action
{
    private $prefix = 'pchomepay_';
    private $version = '1.4';
    private $order_condition;
    private $order_notification;

    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * when customer selects pchomepaypayment payment method
     */
    public function redirectAction()
    {
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
    public function pchomepayAction()
    {
        try {
            // =========================== GET PARAMS OP ===========================
            // init
            $session = $this->_getCheckout();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());

            // 在controller須先呼叫model cvs後才能使用getConfigData()
            $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');

            $appID = $mageModel->getPChomePayConfig('appID');
            $secret = $mageModel->getPChomePayConfig('secret');
            $sandboxSecret = $mageModel->getPChomePayConfig('sandboxSecret');
            $testMode = $mageModel->getPChomePayConfig('testMode');

            $mageModel->loadLibrary();

            $pchomepayPaymentClient = new PChomePayClient($appID, $secret, $sandboxSecret, $testMode);

            $pchomepayRequestData = json_encode($this->getPChomepayPaymentRequestData());

            // =========================== POST DATA OP ===========================
            $result = $pchomepayPaymentClient->postPayment($pchomepayRequestData);
            // =========================== POST DATA ED ===========================

            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, $this->getPendingPaymentStatus(), '訂單編號：' . $result->order_id)->save();

            $this->_redirectUrl($result->payment_url);
            return;

        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    private function getPChomepayPaymentRequestData()
    {
        $session = $this->_getCheckout();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');

        $orderID = 'AM' . date('Ymd') . $session->getLastRealOrderId();
        $payType = explode(',', $mageModel->getPChomePayConfig('paymentMethods'));
        $amount = ceil($this->translateNumberFormat($order['base_grand_total']));
        $returnUrl = Mage::getUrl('pchomepaypayment/payment/paymentresult/') . '?result=success';
        $failReturnUrl = Mage::getUrl('pchomepaypayment/payment/paymentresult/') . '?result=fail';
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
            'fail_return_url' => $failReturnUrl,
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
    public function responseAction()
    {

        usleep(500000);

        $request = $this->getRequest()->getPost();

        $notify_type = $request['notify_type'];
        $notify_message = $request['notify_message'];

        if (!$notify_type || !$notify_message) {
            http_response_code(404);
            exit;
        }

        $order_data = json_decode(str_replace('\"', '"', $notify_message));

        Mage::log($order_data);

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId(substr($order_data->order_id, 10));
        $mageModel = Mage::getModel('PChomePay_PChomePayPayment_Model_PaymentModel');
        $mageModel->loadLibrary();

        # 紀錄訂單付款方式
        switch ($order_data->pay_type) {
            case 'ATM':
                $pay_type_note = 'ATM 付款';
                $pay_type_note .= '<br>ATM虛擬帳號: ' . $order_data->payment_info->bank_code . ' - ' . $order_data->payment_info->virtual_account;
                break;
            case 'CARD':
                if ($order_data->payment_info->installment == 1) {
                    $pay_type_note = '信用卡 付款 (一次付清)';
                } else {
                    $pay_type_note = '信用卡 分期付款 (' . $order_data->payment_info->installment . '期)';
                }

                if ($mageModel->getPChomePayConfig('cardLastNumber') == '1') $pay_type_note .= '<br>末四碼: ' . $order_data->payment_info->card_last_number;

                break;
            case 'ACCT':
                $pay_type_note = '支付連餘額 付款';
                break;
            case 'EACH':
                $pay_type_note = '銀行支付 付款';
                break;
            default:
                $pay_type_note = '未選擇付款方式';
        }

        if ($notify_type == 'order_audit') {
            $status = $order->getState();
            $comment = $pay_type_note . '<br>' . sprintf('訂單交易等待中。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code));
            $order->setState($status, $status, $comment, false)->save();
        } elseif ($notify_type == 'order_expired') {
            $status = $mageModel->getPChomePayConfig('failed_status');
            if ($order_data->status_code) {
                $comment = $pay_type_note . '<br>' . sprintf('訂單已失敗。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code));
                $order->setState($status, $status, $comment, true)->save();
            } else {
                $order->setState($status, $status, $pay_type_note . '<br>訂單已失敗。', true)->save();
            }
        } elseif ($notify_type == 'order_confirm') {
            $status = $mageModel->getPChomePayConfig('success_status');
            $order->setState($status, $status, $pay_type_note . '<br>訂單已成功。', true)->save();
        }
        unset($status, $comment);

        echo 'success';
        exit();
    }

    public function paymentresultAction()
    {
        $result = $_GET["result"];

        if ($result == 'success') {
            //A Success Message
            Mage::getSingleton('core/session')->addSuccess("付款成功!");
        } else {
            //A Error Message
            Mage::getSingleton('core/session')->addError("付款失敗!");
        }

        //These lines are required to get it to work
        session_write_close(); //THIS LINE IS VERY IMPORTANT!

        $this->_redirect('checkout/cart');
    }

    protected function getPendingPaymentStatus()
    {
        return Mage::helper('pchomepaypayment')->getPendingPaymentStatus();
    }

    Public function translateNumberFormat($price)
    {
        $priceTranslate = explode(".", $price);
        $result = $priceTranslate[0];

        return $result;
    }

}