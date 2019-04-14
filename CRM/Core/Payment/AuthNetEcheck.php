<?php

use CRM_AuthNetEcheck_ExtensionUtil as E;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment as AnetEnvironment;

class CRM_Core_Payment_AuthNetEcheck extends CRM_Core_Payment {

  use CRM_Core_Payment_AuthNetEcheckTrait;

  protected $_params = [];

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = E::ts('Authorize.net eCheck.net');
    $this->_setParam('apiLoginID', $paymentProcessor['user_name']);
    $this->_setParam('transactionKey', $paymentProcessor['password']);
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    $error = [];
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = E::ts('API Login ID is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = E::ts('Transaction Key is not set for this payment processor');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Should the first payment date be configurable when setting up back office recurring payments.
   * In the case of Authorize.net this is an option
   * @return bool
   */
  protected function supportsFutureRecurStartDate() {
    return TRUE;
  }

  /**
   * Can recurring contributions be set against pledges.
   *
   * In practice all processors that use the baseIPN function to finish transactions or
   * call the completetransaction api support this by looking up previous contributions in the
   * series and, if there is a prior contribution against a pledge, and the pledge is not complete,
   * adding the new payment to the pledge.
   *
   * However, only enabling for processors it has been tested against.
   *
   * @return bool
   */
  protected function supportsRecurContributionsForPledges() {
    return TRUE;
  }

  /**
   * We can use the processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeName() {
    return 'direct_debit';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeLabel() {
    return 'Authorize.net eCheck.Net';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return [
      'account_holder',
      'bank_account_number',
      'bank_identification_number',
      'bank_name',
    ];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    return [
      'account_holder' => [
        'htmlType' => 'text',
        'name' => 'account_holder',
        'title' => E::ts('Name on Account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 34,
          'autocomplete' => 'on',
        ],
        'is_required' => TRUE,
      ],
      // US account number (max 17 digits)
      'bank_account_number' => [
        'htmlType' => 'text',
        'name' => 'bank_account_number',
        'title' => E::ts('Account Number'),
        'description' => E::ts('Usually between 8 and 12 digits - identifies your individual account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 17,
          'autocomplete' => 'off',
        ],
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
        'is_required' => TRUE,
      ],
      //e.g. SWIFT-BIC can have maxlength of 11 digits eg. 211287748
      'bank_identification_number' => [
        'htmlType' => 'text',
        'name' => 'bank_identification_number',
        'title' => E::ts('Routing Number'),
        'description' => E::ts('A 9-digit code (ABA number) that is used to identify where your bank account was opened (eg. 211287748)'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 9,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
      ],
      'bank_name' => [
        'htmlType' => 'text',
        'name' => 'bank_name',
        'title' => E::ts('Bank Name'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 64,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
      ],
    ];
  }

  /**
   * Set params
   *
   * @param array $params
   *
   * @return array
   */
  private function setParams($params) {
    $params['error_url'] = self::getErrorUrl($params);
    $params = $this->formatParamsForPaymentProcessor($params);
    $newParams = $params;
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $newParams);
    foreach ($newParams as $field => $value) {
      $this->_setParam($field, $value);
    }
    return $newParams;
  }

  /**
   * Submit a payment using Advanced Integration Method.
   *
   * Sets appropriate parameters and calls Smart Debit API to create a payment
   *
   * Payment processors should set payment_status_id.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    // Set default contribution status
    $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    $params = $this->setParams($params);

    if (!empty($params['is_recur']) && !empty($params['contributionRecurID'])) {
      $this->doRecurPayment();
      return $params;
    }

    // Authorize.Net will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->checkDupe($this->getInvoiceNumber(), CRM_Utils_Array::value('contributionID', $params))) {
      self::handleError(
        9004,
        E::ts('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from Authorize.net.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.'),
        $params['error_url']
      );
    }

    $merchantAuthentication = $this->getMerchantAuthentication();

    $bankAccount = $this->getBankAccount();
    $paymentBank = new AnetAPI\PaymentType();
    $paymentBank->setBankAccount($bankAccount);
    $order = $this->getOrder();

    $customerData = $this->getCustomerDataType();
    $customerAddress = $this->getCustomerAddress();

    //create a bank debit transaction
    $transactionRequestType = new AnetAPI\TransactionRequestType();
    $transactionRequestType->setTransactionType("authCaptureTransaction");
    $transactionRequestType->setAmount($this->_getParam('amount'));
    $transactionRequestType->setCurrencyCode($this->getCurrency($params));
    $transactionRequestType->setPayment($paymentBank);
    $transactionRequestType->setOrder($order);
    $transactionRequestType->setBillTo($customerAddress);
    $transactionRequestType->setCustomer($customerData);
    $request = new AnetAPI\CreateTransactionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setTransactionRequest($transactionRequestType);
    $controller = new AnetController\CreateTransactionController($request);
    /** @var \net\authorize\api\contract\v1\CreateTransactionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      self::handleError(NULL, 'No response returned', $params['error_url']);
    }

    $tresponse = $response->getTransactionResponse();

    if ($response->getMessages()->getResultCode() == "Ok") {
      if (!$tresponse) {
        self::handleError(NULL, 'No transaction response returned', $params['error_url']);
      }

      $contributionParams['trxn_id'] = $tresponse->getTransId();

      switch ($tresponse->getResponseCode()) {
        case CRM_AuthNet_Helper::RESPONSECODE_APPROVED:
          $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
          break;

        case CRM_AuthNet_Helper::RESPONSECODE_DECLINED:
        case CRM_AuthNet_Helper::RESPONSECODE_ERROR:
        if ($tresponse->getErrors()) {
          self::handleError($tresponse->getErrors()[0]->getErrorCode(), $tresponse->getErrors()[0]->getErrorText(), $params['error_url']);
        }
        else {
          self::handleError(NULL, 'Transaction Failed', $params['error_url']);
        }
        $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
          break;

        case CRM_AuthNet_Helper::RESPONSECODE_REVIEW:
          // Keep it in pending state
          break;

      }
    }
    else {
      // resultCode !== 'Ok'
      $errorCode = NULL;
      if ($tresponse && $tresponse->getErrors()) {
        foreach ($tresponse->getErrors() as $tError) {
          $errorCode = $tError->getErrorCode();
          switch ($errorCode) {
            case '39':
              $errorMessages[] = $errorCode . ': ' . $tError->getErrorText() . ' (' . $this->getCurrency($params) . ')';
              break;

            default:
              $errorMessages[] = $errorCode . ': ' . $tError->getErrorText();
          }
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      elseif ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      self::handleError($errorCode, $errorMessage, $params['error_url']);
    }

    if ($this->getContributionId($params)) {
      $contributionParams['id'] = $this->getContributionId($params);
      civicrm_api3('Contribution', 'create', $contributionParams);
      unset($contributionParams['id']);
    }
    $params = array_merge($params, $contributionParams);

    // We need to set this to ensure that contributions are set to the correct status
    if (!empty($params['contribution_status_id'])) {
      $params['payment_status_id'] = $params['contribution_status_id'];
    }
    return $params;
  }

  /**
   * Get the merchant authentication for AuthNet
   *
   * @return \net\authorize\api\contract\v1\MerchantAuthenticationType
   */
  private function getMerchantAuthentication() {
    // Create a merchantAuthenticationType object with authentication details
    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
    $merchantAuthentication->setName($this->_getParam('apiLoginID'));
    $merchantAuthentication->setTransactionKey($this->_getParam('transactionKey'));
    return $merchantAuthentication;
  }

  /**
   * Get the customer address for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerAddressType
   */
  private function getCustomerAddress() {
    // Set the customer's Bill To address
    $customerAddress = new AnetAPI\CustomerAddressType();
    !empty($this->_getParam('billing_first_name')) ? $customerAddress->setFirstName($this->_getParam('billing_first_name')) : NULL;
    !empty($this->_getParam('billing_last_name')) ? $customerAddress->setLastName($this->_getParam('billing_last_name')) : NULL;
    $customerAddress->setAddress($this->_getParam('street_address'));
    $customerAddress->setCity($this->_getParam('city'));
    $customerAddress->setState($this->_getParam('state_province'));
    $customerAddress->setZip($this->_getParam('postal_code'));
    $customerAddress->setCountry($this->_getParam('country'));
    return $customerAddress;
  }

  /**
   * Get the customer data for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerDataType
   */
  private function getCustomerDataType() {
    // Set the customer's identifying information
    $customerData = new AnetAPI\CustomerDataType();
    $customerData->setType('individual');
    $customerData->setId($this->getContactId($this->_params));
    $customerData->setEmail($this->getBillingEmail($this->_params, $this->getContactId($this->_params)));
    return $customerData;
  }

  /**
   * Get the customer data for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerType
   */
  private function getCustomerType() {
    // Set the customer's identifying information
    $customerData = new AnetAPI\CustomerType();
    $customerData->setType('individual');
    $customerData->setId($this->getContactId($this->_params));
    $customerData->setEmail($this->getBillingEmail($this->_params, $this->getContactId($this->_params)));
    return $customerData;
  }

  /**
   * Get the bank account for AuthNet
   *
   * @return \net\authorize\api\contract\v1\BankAccountType
   */
  private function getBankAccount() {
    // Create the payment data for a Bank Account
    $bankAccount = new AnetAPI\BankAccountType();
    $bankAccount->setAccountType(strtoupper($this->_getParam('bank_account_type')));
    // see eCheck documentation for proper echeck type to use for each situation
    $bankAccount->setEcheckType('WEB');
    $bankAccount->setRoutingNumber($this->_getParam('bank_identification_number'));
    $bankAccount->setAccountNumber($this->_getParam('bank_account_number'));
    $bankAccount->setNameOnAccount($this->_getParam('account_holder'));
    $bankAccount->setBankName($this->_getParam('bank_name'));
    $bankAccount->setAccountType('checking');
    return $bankAccount;
  }

  /**
   * Get the order for AuthNet
   *
   * @return \net\authorize\api\contract\v1\OrderType
   */
  private function getOrder() {
    // Order info
    $order = new AnetAPI\OrderType();
    $order->setInvoiceNumber($this->getInvoiceNumber());
    $order->setDescription($this->_getParam('description'));
    return $order;
  }

  /**
   * Get the recurring payment interval for AuthNet
   *
   * @return \net\authorize\api\contract\v1\PaymentScheduleType\IntervalAType
   */
  private function getRecurInterval() {
    $intervalLength = $this->_getParam('frequency_interval');
    $intervalUnit = $this->_getParam('frequency_unit');
    if ($intervalUnit == 'week') {
      $intervalLength *= 7;
      $intervalUnit = 'days';
    }
    elseif ($intervalUnit == 'year') {
      $intervalLength *= 12;
      $intervalUnit = 'months';
    }
    elseif ($intervalUnit == 'day') {
      $intervalUnit = 'days';
    }
    elseif ($intervalUnit == 'month') {
      $intervalUnit = 'months';
    }

    // interval cannot be less than 7 days or more than 1 year
    if ($intervalUnit == 'days') {
      if ($intervalLength < 7) {
        self::handleError(NULL, 'Payment interval must be at least one week', $this->_getParam('error_url'));
      }
      elseif ($intervalLength > 365) {
        self::handleError(NULL, 'Payment interval may not be longer than one year', $this->_getParam('error_url'));
      }
    }
    elseif ($intervalUnit == 'months') {
      if ($intervalLength < 1) {
        self::handleError(NULL, 'Payment interval must be at least one week', $this->_getParam('error_url'));
      }
      elseif ($intervalLength > 12) {
        self::handleError(NULL, 'Payment interval may not be longer than one year', $this->_getParam('error_url'));
      }
    }

    $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
    $interval->setLength($intervalLength);
    $interval->setUnit($intervalUnit);
    return $interval;
  }

  /**
   * Get the payment schedule for AuthNet
   *
   * @param $interval
   *
   * @return \net\authorize\api\contract\v1\PaymentScheduleType
   */
  private function getRecurSchedule($interval = NULL) {
    $firstPaymentDate = $this->_getParam('receive_date');
    if (!empty($firstPaymentDate)) {
      //allow for post dated payment if set in form
      $startDate = date_create($firstPaymentDate);
    }
    else {
      $startDate = date_create();
    }
    /* Format start date in Mountain Time to avoid Authorize.net error E00017
     * we do this only if the day we are setting our start time to is LESS than the current
     * day in mountaintime (ie. the server time of the A-net server). A.net won't accept a date
     * earlier than the current date on it's server so if we are in PST we might need to use mountain
     * time to bring our date forward. But if we are submitting something future dated we want
     * the date we entered to be respected
     */
    $minDate = date_create('now', new DateTimeZone(CRM_AuthNet_Helper::TIMEZONE));
    if (strtotime($startDate->format('Y-m-d')) < strtotime($minDate->format('Y-m-d'))) {
      $startDate->setTimezone(new DateTimeZone(CRM_AuthNet_Helper::TIMEZONE));
    }

    $installments = $this->_getParam('installments');

    // for open ended subscription totalOccurrences has to be 9999
    $installments = empty($installments) ? 9999 : $installments;

    $paymentSchedule = new AnetAPI\PaymentScheduleType();
    if ($interval) {
      $paymentSchedule->setInterval($interval);
    }
    $paymentSchedule->setStartDate($startDate);
    $paymentSchedule->setTotalOccurrences($installments);
    return $paymentSchedule;
  }

  /**
   * Get the trxn_id from the recurring contribution
   *
   * @param array $params
   *
   * @return string
   * @throws \CiviCRM_API3_Exception
   */
  private function getSubscriptionId($params) {
    $recurId = $this->getRecurringContributionId($params);
    return (string) civicrm_api3('ContributionRecur', 'getvalue', ['id' => $recurId, 'return' => 'trxn_id']);
  }

  /**
   * Submit an Automated Recurring Billing subscription.
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public function doRecurPayment() {
    $params = $this->_params;

    $merchantAuthentication = $this->getMerchantAuthentication();
    // Subscription Type Info
    $subscription = new AnetAPI\ARBSubscriptionType();
    $subscription->setName($this->getPaymentDescription($params));
    $interval = $this->getRecurInterval();
    $paymentSchedule = $this->getRecurSchedule($interval);

    $subscription->setPaymentSchedule($paymentSchedule);
    $subscription->setAmount($this->getAmount($this->_params));

    $bankAccount = $this->getBankAccount();
    $payment = new AnetAPI\PaymentType();
    $payment->setBankAccount($bankAccount);
    $subscription->setPayment($payment);

    $order = $this->getOrder();
    $subscription->setOrder($order);

    $customerAddress = $this->getCustomerAddress();
    $customerData = $this->getCustomerType();
    $subscription->setBillTo($customerAddress);
    $subscription->setCustomer($customerData);

    $request = new AnetAPI\ARBCreateSubscriptionRequest();
    $request->setmerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBCreateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBCreateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      self::handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() == "Ok") {
      $recurParams = [
        'id' => $params['contributionRecurID'],
        'trxn_id' => $response->getSubscriptionId(),
        // FIXME processor_id is deprecated as it is not guaranteed to be unique, but currently (CiviCRM 5.9)
        //  it is required by cancelSubscription (where it is called subscription_id)
        'processor_id' => $response->getSubscriptionId(),
        'auto_renew' => 1,
        'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'),
      ];
      if (!empty($params['installments'])) {
        if (empty($params['start_date'])) {
          $params['start_date'] = date('YmdHis');
        }
      }

      // Update the recurring payment
      civicrm_api3('ContributionRecur', 'create', $recurParams);

      return TRUE;
    }
    else {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      self::handleError($errorCode, $errorMessage, $params['error_url']);
    }
    return FALSE;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    $params = $this->setParams($params);

    $merchantAuthentication = $this->getMerchantAuthentication();

    $bankAccount = $this->getBankAccount();
    $payment = new AnetAPI\PaymentType();
    $payment->setBankAccount($bankAccount);
    $subscription = new AnetAPI\ARBSubscriptionType();
    $subscription->setPayment($payment);
    $customerAddress = $this->getCustomerAddress();
    //$customerData = $this->getCustomerType();
    $subscription->setBillTo($customerAddress);
    //$subscription->setCustomer($customerData);

    $request = new AnetAPI\ARBUpdateSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBUpdateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBUpdateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      self::handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      self::handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * Change the subscription amount using the Smart Debit API
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \Exception
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $existingParams = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $this->getRecurringContributionId($params)]);
    $params = array_merge($existingParams, $params);
    $params = $this->setParams($params);

    $merchantAuthentication = $this->getMerchantAuthentication();

    $subscription = new AnetAPI\ARBSubscriptionType();
    $paymentSchedule = $this->getRecurSchedule();
    $subscription->setPaymentSchedule($paymentSchedule);
    $subscription->setAmount($this->getAmount($this->_params));
    $request = new AnetAPI\ARBUpdateSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBUpdateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBUpdateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      self::handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      self::handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  public function cancelSubscription(&$message = '', $params = []) {
    $params = $this->setParams($params);

    $contributionRecurId = $this->getRecurringContributionId($params);
    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $contributionRecurId,
      ]);
    }
    catch (Exception $e) {
      return FALSE;
    }
    if (empty($contributionRecur['trxn_id'])) {
      CRM_Core_Session::setStatus(ts('The recurring contribution cannot be cancelled (No reference (trxn_id) found).'), 'Smart Debit', 'error');
      return FALSE;
    }

    $merchantAuthentication = $this->getMerchantAuthentication();

    $request = new AnetAPI\ARBCancelSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $controller = new AnetController\ARBCancelSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBCancelSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      self::handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      self::handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * Handle an error and notify the user
   *
   * @param string $errorCode
   * @param string $errorMessage
   * @param string $bounceURL
   *
   * @return string $errorMessage
   *     (or statusbounce if URL is specified)
   */
  public function handleError($errorCode = NULL, $errorMessage = NULL, $bounceURL = NULL) {
    $errorCode = empty($errorCode) ? '' : $errorCode . ': ';
    $errorMessage = empty($errorMessage) ? 'Unknown System Error.' : $errorMessage;
    $message = $errorCode . $errorMessage;

    Civi::log()->debug('AuthNetEcheck Payment Error: ' . $message);

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($message, $bounceURL, E::ts('Error: %1', [1 => $this->getPaymentTypeLabel()]));
    }
    return $errorMessage;
  }

  /**
   * Return the invoice number formatted in the "standard" way
   * @fixme This is how it has always been done with authnetecheck and is not necessarily the best way
   *
   * @return string
   */
  public function getInvoiceNumber() {
    return substr($this->_getParam('invoiceID'), 0, 20);
  }

  /**
   * Get the value of a field if set.
   *
   * @param string $field
   *   The field.
   *
   * @param bool $xmlSafe
   * @return mixed
   *   value of the field, or empty string if the field is
   *   not set
   */
  public function _getParam($field, $xmlSafe = FALSE) {
    $value = CRM_Utils_Array::value($field, $this->_params, '');
    if ($xmlSafe) {
      $value = str_replace(['&', '"', "'", '<', '>'], '', $value);
    }
    return $value;
  }

  /**
   * Set a field to the specified value.  Value must be a scalar (int,
   * float, string, or boolean)
   *
   * @param string $field
   * @param mixed $value
   *
   * @return bool
   *   false if value is not a scalar, true if successful
   */
  public function _setParam($field, $value) {
    if (!is_scalar($value)) {
      return FALSE;
    }
    else {
      $this->_params[$field] = $value;
    }
  }
}
