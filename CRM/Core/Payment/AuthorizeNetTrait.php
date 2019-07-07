<?php
/**
 * https://civicrm.org/licensing
 */

/**
 * Shared payment functions that should one day be migrated to CiviCRM core
 */

trait CRM_Core_Payment_AuthorizeNetTrait {
  /**********************
   * MJW_Core_Payment_Trait: 20190707
   *********************/

  /**
   * @var array params passed for payment
   */
  protected $_params = [];

  /**
   * @var string The unique invoice/order reference from the payment processor
   */
  private $paymentProcessorInvoiceID;

  /**
   * @var string The unique subscription reference from the payment processor
   */
  private $paymentProcessorSubscriptionID;

  /**
   * Get the billing email address
   *
   * @param array $params
   * @param int $contactId
   *
   * @return string|NULL
   */
  protected function getBillingEmail($params, $contactId) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();

    $emailAddress = CRM_Utils_Array::value("email-{$billingLocationId}", $params,
      CRM_Utils_Array::value('email-Primary', $params,
        CRM_Utils_Array::value('email', $params, NULL)));

    if (empty($emailAddress) && !empty($contactId)) {
      // Try and retrieve an email address from Contact ID
      try {
        $emailAddress = civicrm_api3('Email', 'getvalue', [
          'contact_id' => $contactId,
          'return' => ['email'],
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $emailAddress;
  }

  /**
   * Get the billing email address
   *
   * @param array $params
   * @param int $contactId
   *
   * @return string|NULL
   */
  protected function getBillingPhone($params, $contactId) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();

    $phoneNumber = CRM_Utils_Array::value("phone-{$billingLocationId}", $params,
      CRM_Utils_Array::value('phone-Primary', $params,
        CRM_Utils_Array::value('phone', $params, NULL)));

    if (empty($phoneNumber) && !empty($contactId)) {
      // Try and retrieve a phone number from Contact ID
      try {
        $phoneNumber = civicrm_api3('Phone', 'getvalue', [
          'contact_id' => $contactId,
          'return' => ['phone'],
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $phoneNumber;
  }

  /**
   * Get the contact id
   *
   * @param array $params
   *
   * @return int ContactID
   */
  protected function getContactId($params) {
    // $params['contact_id'] is preferred.
    // contactID is set by: membership payment workflow
    // cms_contactID is set by: membership payment workflow when "on behalf of" / related contact is used.
    $contactId = CRM_Utils_Array::value('contactID', $params,
      CRM_Utils_Array::value('contact_id', $params,
        CRM_Utils_Array::value('cms_contactID', $params,
          CRM_Utils_Array::value('cid', $params, NULL
          ))));
    if (!empty($contactId)) {
      return $contactId;
    }
    // FIXME: Ref: https://lab.civicrm.org/extensions/stripe/issues/16
    // The problem is that when registering for a paid event, civicrm does not pass in the
    // contact id to the payment processor (civicrm version 5.3). So, I had to patch your
    // getContactId to check the session for a contact id. It's a hack and probably should be fixed in core.
    // The code below is exactly what CiviEvent does, but does not pass it through to the next function.
    $session = CRM_Core_Session::singleton();
    return $session->get('transaction.userID', NULL);
  }

  /**
   * Get the contribution ID
   *
   * @param $params
   *
   * @return mixed
   */
  protected function getContributionId($params) {
    /*
     * contributionID is set in the contribution workflow
     * We do NOT have a contribution ID for event and membership payments as they are created after payment!
     * See: https://github.com/civicrm/civicrm-core/pull/13763 (for events)
     */
    return CRM_Utils_Array::value('contributionID', $params);
  }

  /**
   * Get the recurring contribution ID from parameters passed in to cancelSubscription
   * Historical the data passed to cancelSubscription is pretty poor and doesn't include much!
   *
   * @param array $params
   *
   * @return int|null
   */
  protected function getRecurringContributionId($params) {
    // Not yet passed, but could be added via core PR
    $contributionRecurId = CRM_Utils_Array::value('contribution_recur_id', $params,
      CRM_Utils_Array::value('contributionRecurID', $params)); // backend live contribution
    if (!empty($contributionRecurId)) {
      return $contributionRecurId;
    }

    // Not yet passed, but could be added via core PR
    $contributionId = CRM_Utils_Array::value('contribution_id', $params);
    try {
      return civicrm_api3('Contribution', 'getvalue', ['id' => $contributionId, 'return' => 'contribution_recur_id']);
    }
    catch (Exception $e) {
      $subscriptionId = CRM_Utils_Array::value('subscriptionId', $params);
      if (!empty($subscriptionId)) {
        try {
          return civicrm_api3('ContributionRecur', 'getvalue', ['processor_id' => $subscriptionId, 'return' => 'id']);
        }
        catch (Exception $e) {
          return NULL;
        }
      }
      return NULL;
    }
  }

  /**
   *
   * @param array $params ['name' => payment instrument name]
   *
   * @return int|null
   * @throws \CiviCRM_API3_Exception
   */
  public static function createPaymentInstrument($params) {
    $mandatoryParams = ['name'];
    foreach ($mandatoryParams as $value) {
      if (empty($params[$value])) {
        Civi::log()->error('createPaymentInstrument: Missing mandatory parameter: ' . $value);
        return NULL;
      }
    }

    // Create a Payment Instrument
    // See if we already have this type
    $paymentInstrument = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => "payment_instrument",
      'name' => $params['name'],
    ]);
    if (empty($paymentInstrument['count'])) {
      // Otherwise create it
      try {
        $financialAccount = civicrm_api3('FinancialAccount', 'getsingle', [
          'financial_account_type_id' => "Asset",
          'name' => "Payment Processor Account",
        ]);
      }
      catch (Exception $e) {
        $financialAccount = civicrm_api3('FinancialAccount', 'getsingle', [
          'financial_account_type_id' => "Asset",
          'name' => "Payment Processor Account",
          'options' => ['limit' => 1, 'sort' => "id ASC"],
        ]);
      }

      $paymentParams = [
        'option_group_id' => "payment_instrument",
        'name' => $params['name'],
        'description' => $params['name'],
        'financial_account_id' => $financialAccount['id'],
      ];
      $paymentInstrument = civicrm_api3('OptionValue', 'create', $paymentParams);
      $paymentInstrumentId = $paymentInstrument['values'][$paymentInstrument['id']]['value'];
    }
    else {
      $paymentInstrumentId = $paymentInstrument['id'];
    }
    return $paymentInstrumentId;
  }

  /**
   * Get the error URL to "bounce" the user back to.
   * @param $params
   *
   * @return string|null
   */
  public static function getErrorUrl($params) {
    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      return NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      return CRM_Utils_System::url($url_path, $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }
  }

  /**
   * Are we using a test processor?
   *
   * @return bool
   */
  public function getIsTestMode() {
    return isset($this->_paymentProcessor['is_test']) && $this->_paymentProcessor['is_test'] ? TRUE : FALSE;
  }

  /**
   * Format the fields for the payment processor.
   * @fixme Copied from CiviCRM Core 5.13. We should remove this when all forms submit using this function (eg updateSubscriptionBillingInfo)
   *
   * In order to pass fields to the payment processor in a consistent way we add some renamed
   * parameters.
   *
   * @param array $fields
   *
   * @return array
   */
  private function formatParamsForPaymentProcessor($fields) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();
    // also add location name to the array
    $this->_params["address_name-{$billingLocationId}"] = CRM_Utils_Array::value('billing_first_name', $this->_params) . ' ' . CRM_Utils_Array::value('billing_middle_name', $this->_params) . ' ' . CRM_Utils_Array::value('billing_last_name', $this->_params);
    $this->_params["address_name-{$billingLocationId}"] = trim($this->_params["address_name-{$billingLocationId}"]);
    // Add additional parameters that the payment processors are used to receiving.
    if (!empty($this->_params["billing_state_province_id-{$billingLocationId}"])) {
      $this->_params['state_province'] = $this->_params["state_province-{$billingLocationId}"] = $this->_params["billing_state_province-{$billingLocationId}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$billingLocationId}"]);
    }
    if (!empty($this->_params["billing_country_id-{$billingLocationId}"])) {
      $this->_params['country'] = $this->_params["country-{$billingLocationId}"] = $this->_params["billing_country-{$billingLocationId}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$billingLocationId}"]);
    }

    list($hasAddressField, $addressParams) = CRM_Contribute_BAO_Contribution::getPaymentProcessorReadyAddressParams($this->_params, $billingLocationId);
    if ($hasAddressField) {
      $this->_params = array_merge($this->_params, $addressParams);
    }

    $nameFields = ['first_name', 'middle_name', 'last_name'];
    foreach ($nameFields as $name) {
      $fields[$name] = 1;
      if (array_key_exists("billing_$name", $this->_params)) {
        $this->_params[$name] = $this->_params["billing_{$name}"];
        $this->_params['preserveDBName'] = TRUE;
      }
    }
    return $fields;
  }

  /**
   * Called at the beginning of each payment related function (doPayment, updateSubscription etc)
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
      $this->setParam($field, $value);
    }
    return $newParams;
  }

  /**
   * Get the value of a field if set.
   *
   * @param string $field
   *   The field.
   *
   * @return mixed
   *   value of the field, or empty string if the field is
   *   not set
   */
  private function getParam($field) {
    return CRM_Utils_Array::value($field, $this->_params, '');
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
  private function setParam($field, $value) {
    if (!is_scalar($value)) {
      return FALSE;
    }
    else {
      $this->_params[$field] = $value;
    }
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
  private function handleError($errorCode = NULL, $errorMessage = NULL, $bounceURL = NULL) {
    $errorCode = empty($errorCode) ? '' : $errorCode . ': ';
    $errorMessage = empty($errorMessage) ? 'Unknown System Error.' : $errorMessage;
    $message = $errorCode . $errorMessage;

    Civi::log()->debug($this->getPaymentTypeLabel() . ' Payment Error: ' . $message);

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($message, $bounceURL, $this->getPaymentTypeLabel());
    }
    return $errorMessage;
  }

  /**
   * Get the label for the payment processor
   *
   * @return string
   */
  protected function getPaymentProcessorLabel() {
    return $this->_paymentProcessor['name'];
  }

  /**
   * Set the payment processor Invoice ID
   *
   * @param string $invoiceID
   */
  protected function setPaymentProcessorInvoiceID($invoiceID) {
    $this->paymentProcessorInvoiceID = $invoiceID;
  }

  /**
   * Get the payment processor Invoice ID
   *
   * @return string
   */
  protected function getPaymentProcessorInvoiceID() {
    return $this->paymentProcessorInvoiceID;
  }

  /**
   * Set the payment processor Subscription ID
   *
   * @param string $subscriptionID
   */
  protected function setPaymentProcessorSubscriptionID($subscriptionID) {
    $this->paymentProcessorSubscriptionID = $subscriptionID;
  }

  /**
   * Get the payment processor Subscription ID
   *
   * @return string
   */
  protected function getPaymentProcessorSubscriptionID() {
    return $this->paymentProcessorSubscriptionID;
  }

  protected function beginDoPayment($params) {
    // Set default contribution status
    $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $params = $this->setParams($params);
    return $params;
  }

  /**
   * Call this at the end of a call to doPayment to ensure everything is updated/returned correctly.
   *
   * @param array $params
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  protected function endDoPayment($params) {
    $contributionParams['trxn_id'] = $this->getPaymentProcessorInvoiceID();

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

}
