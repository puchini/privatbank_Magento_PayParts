<?php

class Payparts_Payment_Model_Redirect extends Mage_Payment_Model_Method_Abstract {
  /**
   * unique internal payment method identifier
   */
  protected $_code = 'payparts_redirect';
  protected $_formBlockType = 'payparts/message';

  /**
   * Payment Method features
   *
   * @var bool
   */
  protected $_canUseForMultishipping = false;
  protected $_canUseInternal = false;
  protected $_isInitializeNeeded = true;

  /**
   * Instantiate state and set it to state object
   *
   * @param string $paymentAction
   * @param Varien_Object $stateObject
   */
  public function initialize($paymentAction, $stateObject) {
    $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
    $stateObject->setStatus('pending_payment');
    $stateObject->setIsNotified(false);
    $stateObject->save();
  }

  /**
   * Return Order place redirect url
   *
   * @return string
   */
  public function getOrderPlaceRedirectUrl() {
    return Mage::getUrl('payparts/payment/redirect', array('_secure' => false));
  }

  /**
   * Return Payparts place URL
   *
   * @return string
   */
  public function getPaypartsPlaceUrl() {
    $resultToken = $this->getPayPartsToken();
    if(!$resultToken['status']){
      Mage::throwException(Mage::helper('payparts')->__('PayParts :', $resultToken['message']));
    }
    return '//payparts2.privatbank.ua/ipp/v2/payment?token=' . (string)$resultToken['token'];
  }

  public function deliveryCalculate($amount, $tax_amount, $total_qty){
    return (float)($amount - round($tax_amount, 2)) / (int)$total_qty;
  }

  public function getSignature($result){
    return base64_encode(
        hex2bin(
            SHA1( $result['store_passwd']
                .$result['store_id']
                .$result['order_id_unique']
                .str_replace('.', '', $result['amount'])
                .$result['currency']
                .$result['partsCount']
                .$result['merchantType']
                .$result['responseUrl']
                .$result['redirectUrl']
                .$result['products_string']
                .$result['store_passwd']
            )
        )
    );
  }

  private function getTax($order){
    return $order->getTaxAmount()+$order->getShippingAmount();
  }

  private function preparedData(){
    $result = array();
    $session = Mage::getSingleton('checkout/session');
    $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());

    foreach ($order->getAllVisibleItems() as $itemId => $item)
    {
      $result['orders_sales'][] = array(
          'name'  => $item->getName(),
          'price' => (string)number_format($item->getPrice(), 2, '.', ''),
          'count' => (int)$item->getQtyOrdered()

      );
    }

    $result['order_id_unique'] = $order->getRealOrderId().'_'.uniqid();
    $result['store_passwd'] = $this->getConfigData('shoppassword');
    $result['store_id'] = $this->getConfigData('shopident');
    $result['amount'] = (string)number_format($order->getGrandTotal(), 2, '.', '');
    $result['merchantType'] = (string)$session->system;
    $result['partsCount'] = (string)$session->parts;
    $result['currency'] = $order->getOrderCurrencyCode();
    $result['responseUrl'] = Mage::getUrl('payparts/payment/return/', array('transaction_id' => $order->getRealOrderId()));
    $result['redirectUrl'] = Mage::getUrl('payparts/payment/fail/');
    $result['products_string'] = "";

    if($order->getTaxAmount()){
      $result['orders_sales'][] = array(
          'name' => 'Tax',
          'price' => (string)number_format($this->getTax($order), 2, '.', ''),
          'count' => 1
      );
    }

    for ($i=0; $i<count($result['orders_sales']);$i++)
    {
      $result['products_string'] .= $result['orders_sales'][$i]['name']
          .(string)$result['orders_sales'][$i]['count']
          .str_replace('.', '', $result['orders_sales'][$i]['price']);
    }

    $requestData = json_encode(
        array(
            "storeId"      => $result['store_id'],
            "orderId"      => $result['order_id_unique'],
            "amount"       => $result['amount'],
            "currency"     => $result['currency'],
            "partsCount"   => $result['partsCount'],
            "merchantType" => $result['merchantType'],
            "products"     => $result['orders_sales'],
            "responseUrl"  => $result['responseUrl'],
            "redirectUrl"  => $result['redirectUrl'],
            "signature"    => $this->getSignature($result)
        )
    );

    var_dump($requestData);
    return $requestData;
  }

  private function getPayPartsToken(){
    $result = array('status' => false);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://payparts2.privatbank.ua/ipp/v2/payment/create');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->preparedData());
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Accept-Encoding: UTF-8',
        'Content-Type: application/json; charset=UTF-8'
    ));

    $response = json_decode(curl_exec($ch));
    curl_close($ch);

    if ($response->token){
      $result['status'] = true;
      $result['token'] = $response->token;
    } else{
      $result['message'] = ($response->errorMessage) ? $response->errorMessage : $response->message;
    }
    return $result;
  }

  /**
   * Return redirect form fields
   * @return array
   */
  public function getRedirectFormFields() {
    $result = array();
    $session = Mage::getSingleton('checkout/session');
    $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());


    if (!$order->getId()) {
      return $result;
    }

    $order_id = $order->getRealOrderId();

    $result['myPayPartsMerchantID'] = $this->getConfigData('login');
    $result['myPayPartsMerchantExpTime'] = $this->getConfigData('validitytime');
    $result['myPayPartsMerchantShopName'] = $this->getConfigData('shopname');
    $result['myPayPartsMerchantSum'] = round($order->getGrandTotal(), 2);
    $result['myPayPartsTotalQtyOrdered'] = (int)$order->getTotalQtyOrdered();
    $result['myPayPartsTaxAmount'] = round($order->getTaxAmount(), 2);
    $result['myPayPartsUnitSum'] = round((int)($result['myPayPartsMerchantSum'] - round($order->getTaxAmount(), 2)) / (int)$order->getTotalQtyOrdered(), 2);

    $result['myPayPartsMerchantCurrency'] = $order->getOrderCurrencyCode(); //must be UAH

    if ($result['myPayPartsMerchantCurrency'] != 'UAH') {
      Mage::throwException(Mage::helper('payparts')->__('PayParts ( payparts2.privatbank.ua ) works only with UAH but not with (%s)', $result['myPayPartsMerchantCurrency']));
    }


    $result['myPayPartsMerchantOrderId'] = $order_id;
    $result['myPayPartsMerchantOrderDesc'] =  Mage::app()->getStore()->getGroup()->getName() . ' №' . $order_id;
    $result['myPayPartsMerchantResultUrl'] = Mage::getUrl('payparts/payment/notify');
    $result['myPayPartsMerchantSuccessUrl'] = Mage::getUrl('payparts/payment/return', array('transaction_id' => $order_id));
    $result['myPayPartsMerchantFailUrl'] = Mage::getUrl('payparts/payment/fail', array('transaction_id' => $order_id));


    ksort($result);
    $req_str = '';
    foreach ($result AS $pkey => $pval)
      $req_str.=($pkey . '=' . $pval);


    // Add a secret key to the request string if needed.
    if ($this->getConfigData('checkhash')) {
      $req_str .=  Mage::helper('core')->decrypt($this->getConfigData('secretkey'));
    }

    $result['myPayPartsMerchantHash'] = md5($req_str);

    var_dump($result);
//    die;
    return $result;
  }

  /**
   * Check incoming request CRC
   *
   * @param array $request
   * @return bool
   */
  public function validateRequest($request) {

    $order_id = $request['OrderId'];
    $order = Mage::getModel('sales/order')->loadByIncrementId((int) $order_id);

    if ($order) {

// сбор хеша по пост данным для проверки $_REQUEST["MerchantHash"]
      $dataM = array(
          'MerchantId' => $request["MerchantId"], // номер мерчанта в системе Манекси
          'PaymentId' => $request["PaymentId"], // номер платежа в системе Манекси
          'OrderId' => $request["OrderId"], // номер заказа, который был передан Торговцем
          'Amount' => $request["Amount"], // сумма заказа
          'Currency' => $request["Currency"], // валюта заказа
          'Success' => $request["Success"], // успешность проведения платежа
          'Type' => $request["Type"], // идентификатор способа платежа
          'TypeName' => $request["TypeName"]//описание выбранного способа оплаты
      );

      if(!empty($request['TransId']))
      {
        $dataM['TransId'] = $request['TransId'];
      }

      ksort($dataM); //сортировка данных массива по ключу
      $req_str3 = ''; // первоначальное значение строки данных для подписи
      foreach ($dataM AS $pkey => $pval)
        $req_str3.=($pkey . '=' . $pval);
      if ($this->getConfigData('checkhash')) {
        $req_str3 .= Mage::helper('core')->decrypt($this->getConfigData('secretkey'));
      }

      $ServerHashM = md5($req_str3);

// сборка хеша по пост данным для проверки полей от подмены
      $datas = array();
      $datas["MerchantId"] = $request["MerchantId"]; // номер мерчанта в системе Манекси
      $datas["OrderId"] = $request["OrderId"]; // номер заказа, который был передан Торговцем
      $datas["Amount"] = $request["Amount"] * 100; // сумма заказа
      $datas["Currency"] = $request["Currency"]; // валюта заказа
      $datas["Success"] = $request["Success"]; // успешность проведения платежа
      ksort($datas); //сортировка данных массива по ключу
      $req_str1 = ''; // первоначальное значение строки данных для подписи
      foreach ($datas AS $pkey => $pval)
        $req_str1.=($pkey . '=' . $pval);
      if ($this->getConfigData('checkhash')) {
        $req_str1 .= Mage::helper('core')->decrypt($this->getConfigData('secretkey'));
      }


      $ServerHash2 = md5($req_str1);

//соборка провеочного хеша для проверки полей от подмены и упешного статуса (Success = 1)
      $datasC = array();
      $datasC["MerchantId"] = $this->getConfigData('login'); // номер мерчанта в системе Манекси
      $datasC["OrderId"] = $order_id; // номер заказа, который был передан Торговцем
      $datasC["Amount"] = $order->getGrandTotal() * 100; // сумма заказа
      $datasC["Currency"] = $order->getOrderCurrencyCode(); // валюта заказа
      $datasC["Success"] = "1"; // успешность проведения платежа
      ksort($datasC); //сортировка данных массива по ключу
      $req_str2 = ''; // первоначальное значение строки данных для подписи
      foreach ($datasC AS $pkey => $pval)
        $req_str2.=($pkey . '=' . $pval);
      if ($this->getConfigData('checkhash')) {
        $req_str2 .= Mage::helper('core')->decrypt($this->getConfigData('secretkey'));
      }

      $CheckHash = md5($req_str2);
      if (strpos($CheckHash, $ServerHash2) !== false) {
        if (strpos($request["MerchantHash"], $ServerHashM) !== false) {
          return true;
        }
      }
    }
    return false;
  }

}
