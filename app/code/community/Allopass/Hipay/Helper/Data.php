<?php
class Allopass_Hipay_Helper_Data extends Mage_Core_Helper_Abstract
{
	
	/**
	 * 
	 * @param Allopass_Hipay_Model_PaymentProfile|int $profile
	 * @param float $amount
	 */
	public function splitPayment($profile,$amount)
	{
		$paymentsSplit = array();
		
		if(is_int($profile))
			$profile = Mage::getModel('hipay/paymentProfile')->load($profile);
		
		if($profile)
		{
			$maxCycles = (int)$profile->getPeriodMaxCycles();
			$periodFrequency = (int)$profile->getPeriodFrequency();
			$periodUnit = $profile->getPeriodUnit();
			
			$todayDate = new Zend_Date();
			
			if($maxCycles < 1)
				Mage::throwException("Period max cycles is equals zero or negative for Payment Profile ID: ".$profile->getId());
			
			
			$part = (int)($amount / $maxCycles);
			$reste = $amount%$maxCycles;
			$fmod = fmod($amount, $maxCycles);
			Mage::log("PART = ".$part." RESTE = ".$reste,null,'hipay_split_debug.log');
			
			for ($i=0;$i<$maxCycles;$i++)
			{
				$todayClone = clone $todayDate;
				switch ($periodUnit)
				{
					case Allopass_Hipay_Model_PaymentProfile::PERIOD_UNIT_MONTH:
					{
						$dateToPay = $todayClone->addMonth($periodFrequency+$i)->getDate()->toString('yyyy-MM-dd');
						break;
					}
					case Allopass_Hipay_Model_PaymentProfile::PERIOD_UNIT_DAY:
						{
							$dateToPay = $todayClone->addDay($periodFrequency+$i)->getDate()->toString('yyyy-MM-dd');
					
							break;
						}
					case Allopass_Hipay_Model_PaymentProfile::PERIOD_UNIT_SEMI_MONTH://TODO test this case !!!
						{
							$dateToPay = $todayClone->addDay(15 + $periodFrequency+$i)->getDate()->toString('yyyy-MM-dd');
							break;
						}
					case Allopass_Hipay_Model_PaymentProfile::PERIOD_UNIT_WEEK:
						{
							$dateToPay = $todayClone->addWeek($periodFrequency+$i)->getDate()->toString('yyyy-MM-dd');
							break;
						}
					case Allopass_Hipay_Model_PaymentProfile::PERIOD_UNIT_YEAR:
						{
							$dateToPay = $todayClone->addYear($periodFrequency+$i)->getDate()->toString('yyyy-MM-dd');
							break;
						}
				}
			
				$amountToPay = $i==($maxCycles-1) ? ($part + $fmod) : $part;
				$paymentsSplit[] = array('dateToPay'=>$dateToPay,'amountToPay'=>$amountToPay);
			}
			
			return $paymentsSplit;
				
		}
		
		Mage::throwException("Payment Profile not found");
		
	}
	
	/**
	 * 
	 * @param Mage_Sales_Model_Order $order
	 * @param Allopass_Hipay_Model_PaymentProfile|int $profile $profile
	 */
	public function insertSplitPayment($order,$profile,$customerId,$cardToken)
	{
		
		
		
		if(is_int($profile))
			$profile = Mage::getModel('hipay/paymentProfile')->load($profile);
		
		if(!$this->splitPaymentsExists($order->getId()))
		{
			
			$paymentsSplit = $this->splitPayment($profile, $order->getBaseGrandTotal());
			
			//remove first element because is already paid
			array_shift($paymentsSplit);
			
			foreach ($paymentsSplit as $split)
			{
				$splitPayment = Mage::getModel('hipay/splitPayment');
				$data = array('order_id'=>$order->getId(),
							  'real_order_id'=>(int)$order->getRealOrderId(),
							  'customer_id'=>$customerId,
							  'card_token'=>$cardToken,
							  'total_amount'=>$order->getBaseGrandTotal(),
							  'amount_to_pay'=>$split['amountToPay'],
							  'date_to_pay'=>$split['dateToPay'],
							  'status'=>Allopass_Hipay_Model_SplitPayment::SPLIT_PAYMENT_STATUS_PENDING,
				);
				
				Mage::log($data,null,'hipay_split_debug.log');
				
				$splitPayment->setData($data);
				
				Mage::log($splitPayment->debug(),null,'hipay_split_debug.log');
				
				/*$splitPayment = Mage::getModel('hipay/splitPayment');
				$splitPayment->setOrderId($order->getId());
				$splitPayment->setRealOrderId((int)$order->getRealOrderId());
				$splitPayment->setCustomerId((int)$customerId);
				$splitPayment->setCardToken($cardToken);
				$splitPayment->setTotalAmount($order->getBaseGrandTotal());
				$splitPayment->setAmountToPay($split['amountToPay']);
				$splitPayment->setDateToPay($split['dateToPay']);
				$splitPayment->setStatus(Allopass_Hipay_Model_SplitPayment::SPLIT_PAYMENT_STATUS_PENDING);*/
				
				try {
					$splitPayment->save();
				} catch (Exception $e) {
					
					Mage::throwException("Error on save split payments!");
				}
			}

		}
	}

	
	/**
	 * 
	 * @param int $orderId
	 * @return boolean
	 */
	public function splitPaymentsExists($orderId)
	{
		$collection = Mage::getModel('hipay/splitPayment')->getCollection()->addFieldToFilter('order_id',$orderId);
		if($collection->count())
			return true;
		
		return false;
	}
	
	public function getHipayMethods()
	{
		$methods = array();
		
		foreach (Mage::getStoreConfig('payment') as $code => $data) {
				if(strpos($code, 'hipay') !== false)
				{
					$methods[$code] = $data['model'];
				}
		}
		
		return $methods;
		
	}
	
	public function checkSignature($signature,$fromNotification = false)
	{
		$passphrase = Mage::getStoreConfig('hipay/hipay_api/secret_passphrase');
		if(empty($passphrase) || empty($signature))
			return true;
		
		if($fromNotification)
		{
			$rawPostData = file_get_contents("php://input");
			if($signature == sha1($rawPostData . $passphrase));
				return true;
			
			return false;
		}
		
		
		$parameters = $this->_getRequest()->getParams();
		$string2compute = "";
		unset($parameters['hash']);
		ksort($parameters);
		foreach ($parameters as $name => $value) {
			if (strlen($value) > 0) {
				$string2compute .= $name . $value . $passphrase;
			}
		}
		
		if(sha1($string2compute) == $signature)
			return true;
		
		return false;
	}
	
	public function checkIfCcExpDateIsValid($customer)
	{
		if(is_int($customer))
			$customer = Mage::getModel('customer/customer')->load($customer);
	
		$expDate = $customer->getHipayCcExpDate();
		$alias = $customer->getHipayAliasOneclick();
		if(!empty($expDate) && !empty($alias))
		{
			list($expMonth,$expYear) = explode("-", $expDate);
			$today = new Zend_Date(Mage::app()->getLocale()->storeTimeStamp());
				
			$currentYear = (int)$today->getYear()->toString("YY");
			$currentMonth = (int)$today->getMonth()->toString("MM");
				
			if($currentYear > (int)$expYear)
				return false;
				
			if($currentYear == (int)$expYear && $currentMonth > (int)$expMonth)
				return false;
				
			return true;
	
		}
	
		return false;
	}
	
	/**
	 *
	 * @param Mage_Customer_Model_Customer $customer
	 * @param Allopass_Hipay_Model_Api_Response_Gateway $response
	 * @param boolean $isRecurring
	 */
	public function responseToCustomer($customer,$response,$isRecurring = false)
	{
	
		$paymentMethod = $response->getPaymentMethod();
		$paymentProduct = $response->getPaymentProduct();
		$token = isset($paymentMethod['token']) ? $paymentMethod['token'] : $response->getData('cardtoken');
		
		if($isRecurring)
			$customer->setHipayAliasRecurring($token);
		else
			$customer->setHipayAliasOneclick($token );
		
		if(isset($paymentMethod['card_expiry_month']) && $paymentMethod['card_expiry_year'])
			$customer->setHipayCcExpDate($paymentMethod['card_expiry_month'] . "-" . $paymentMethod['card_expiry_year'] );
		else
			$customer->setHipayCcExpDate(substr($response->getData('cardexpiry'), 4,2) . "-" . substr($response->getData('cardexpiry'), 0,4) );
		
		$customer->setHipayCcNumberEnc(isset($paymentMethod['pan']) ? $paymentMethod['pan'] : $response->getData('cardpan'));
		//$customer->setHipayCcType(isset($paymentMethod['brand']) ? strtolower($paymentMethod['brand']) : strtolower($response->getData('cardbrand')));
		$customer->setHipayCcType($paymentProduct);	
			
		$customer->getResource()->saveAttribute($customer, 'hipay_alias_oneclick');
		$customer->getResource()->saveAttribute($customer, 'hipay_cc_exp_date');
		$customer->getResource()->saveAttribute($customer, 'hipay_cc_number_enc');
		$customer->getResource()->saveAttribute($customer, 'hipay_cc_type');
	
		return $this;
	}
	
	public function reAddToCart($incrementId) {
	
		$cart = Mage::getSingleton('checkout/cart');
		$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
	
		if ($order->getId()) {
			$items = $order->getItemsCollection();
			foreach ($items as $item) {
				try {
					$cart->addOrderItem($item);
				} catch (Mage_Core_Exception $e) {
					if (Mage::getSingleton('checkout/session')->getUseNotice(true)) {
						Mage::getSingleton('checkout/session')->addNotice($e->getMessage());
					} else {
						Mage::getSingleton('checkout/session')->addError($e->getMessage());
					}
				} catch (Exception $e) {
					Mage::getSingleton('checkout/session')->addException($e, Mage::helper('checkout')->__('Cannot add the item to shopping cart.')
					);
				}
			}
		}
	
		$cart->save();
	}

	
	/**
	 * Return message for gateway transaction request
	 *
	 * @param  Mage_Payment_Model_Info $payment
	 * @param  string $requestType
	 * @param  string $lastTransactionId
	 * @param float $amount
	 * @param string $exception
	 * @return bool|string
	 */
	public function getTransactionMessage($payment, $requestType, $lastTransactionId, $amount = false,
			$exception = false,$additionalMessage = false
	) {
		return $this->getExtendedTransactionMessage(
				$payment, $requestType, $lastTransactionId, $amount, $exception,$additionalMessage
		);
	}
	
	/**
	 * Return message for gateway transaction request
	 *
	 * @param  Mage_Payment_Model_Info $payment
	 * @param  string $requestType
	 * @param  string $lastTransactionId
	 * @param float $amount
	 * @param string $exception
	 * @param string $additionalMessage Custom message, which will be added to the end of generated message
	 * @return bool|string
	 */
	public function getExtendedTransactionMessage($payment, $requestType, $lastTransactionId, $amount = false,
			$exception = false, $additionalMessage = false
	) {
		$operation = 'Operation: ' . $requestType;// $this->_getOperation($requestType);
	
		if (!$operation) {
			return false;
		}
	
		if ($amount) {
			$amount = $this->__('amount: %s', $this->_formatPrice($payment, $amount));
		}
	
		if ($exception) {
			$result = $this->__('failed');
		} else {
			$result = $this->__('successful');
		}
	
		$card = $this->__('Credit Card: xxxx-%s', $payment->getCcLast4());
	
		$pattern = '%s - %s. %s %s.';
		$texts = array($operation,$result,$card, $amount);
	
		if (!is_null($lastTransactionId)) {
			$pattern .= ' %s.';
			$texts[] = $this->__('Hipay Transaction ID %s', $lastTransactionId);
		}
	
		if ($additionalMessage) {
			$pattern .= ' %s.';
			$texts[] = $additionalMessage;
		}
		$pattern .= ' %s';
		$texts[] = $exception;
	
		return call_user_func_array(array($this, '__'), array_merge(array($pattern), $texts));
	}
	
	/**
	 * Format price with currency sign
	 * @param  Mage_Payment_Model_Info $payment
	 * @param float $amount
	 * @return string
	 */
	protected function _formatPrice($payment, $amount)
	{
		return $payment->getOrder()->getBaseCurrency()->formatTxt($amount);
	}
}