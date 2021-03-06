<?php

/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 17/10/18
 * Time: 上午10:36
 */

include_once('ApiException.php');
include_once('OrderStatusCodeEnum.php');

class PChomePayClient
{
    const BASE_URL = "https://api.pchomepay.com.tw/v1";
    const SB_BASE_URL = "https://sandbox-api.pchomepay.com.tw/v1";

    public function __construct($appID, $secret, $sandboxSecret, $sandBox = false, $debug = false)
    {
        $baseURL = $sandBox ? PChomePayClient::SB_BASE_URL : PChomePayClient::BASE_URL;

        $this->debug = $debug;
        $this->appID = $appID;
        $this->secret = $sandBox ? $sandboxSecret : $secret;

        $this->tokenURL = $baseURL . "/token";
        $this->postPaymentURL = $baseURL . "/payment";
        $this->getPaymentURL = $baseURL . "/payment/{order_id}";
        $this->getRefundURL = $baseURL . "/refund/{refund_id}";
        $this->postRefundURL = $baseURL . "/refund";

        $this->userAuth = "{$this->appID}:{$this->secret}";
    }

    // 紀錄log
    private function log($message)
    {
        Mage::log($message);
    }

    // 建立訂單
    public function postPayment($data)
    {
        $this->log($this->postPaymentURL);
        return $this->post_request($this->postPaymentURL, $data);
    }

    // 建立退款
    public function postRefund($data)
    {
        return $this->post_request($this->postRefundURL, $data);
    }

    // 查詢訂單
    public function getPayment($orderID)
    {
        if (!is_string($orderID) || stristr($orderID, "/")) {
            throw new Exception('Order does not exist!', 20002);
        }

        return $this->get_request(str_replace("{order_id}", $orderID, $this->getPaymentURL));
    }

    // 取Token
    protected function getToken()
    {
        $userAuth = "{$this->appID}:{$this->secret}";

        $client = new Varien_Http_Client($this->tokenURL);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($userAuth),
        ));

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    protected function post_request($method, $postdata)
    {
        $token = $this->getToken();

        $client = new Varien_Http_Client($method);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'pcpay-token' => $token->token,
        ));
        $client->setRawData($postdata);

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($response);
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    protected function get_request($method)
    {
        $token = $this->getToken();

        $client = new Varien_Http_Client($method);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setHeaders(array(
            'Content-type' => 'application/json',
            'pcpay-token' => $token->token,
        ));

        try{
            $response = $client->request();
            if ($response->isSuccessful()) {
                $this->log($response->getBody());
                return $this->handleResult($response->getBody());
            }
        } catch (Exception $e) {
            $this->log($response);
            $this->log($e->getMessage());
            throw new $e;
        }
    }

    private function handleResult($result)
    {
        $jsonErrMap = [
            JSON_ERROR_NONE => 'No error has occurred',
            JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded	PHP 5.3.3',
            JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given	PHP 5.5.0'
        ];

        $obj = json_decode($result);

        $err = json_last_error();

        if ($err) {
            $errStr = "($err)" . $jsonErrMap[$err];
            if (empty($errStr)) {
                $errStr = " - unknow error, error code ({$err})";
            }
            $this->log("server result error($err) {$errStr}:$result");
            throw new Exception("server result error($err) {$errStr}:$result");
        }

        if (isset($obj->error_type)) {
            $this->log("\n錯誤類型：" . $obj->error_type . "\n錯誤代碼：" . $obj->code . "\n錯誤訊息：" . ApiException::getErrMsg($obj->code));
            throw new Exception("交易失敗，請聯絡網站管理員。錯誤代碼：" . $obj->code);
        }

        if (empty($obj->token) && empty($obj->order_id)) {

            return false;
        }

        if (isset($obj->status_code)) {
            $this->log("訂單編號：" . $obj->order_id . " 已失敗。\n原因：" . OrderStatusCodeEnum::getErrMsg($obj->status_code));
        }

        return $obj;
    }
}