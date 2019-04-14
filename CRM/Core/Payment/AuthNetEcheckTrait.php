<?php
/**
 * Shared payment functions that should one day be migrated to CiviCRM core
 */

trait CRM_Core_Payment_AuthNetEcheckTrait {
  /**********************
   * Version 20190414
   *********************/

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
        $emailAddress = civicrm_api3('Email', 'getvalue', array(
          'contact_id' => $contactId,
          'return' => ['email'],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $emailAddress;
  }

  /**
   * Get the contact id
   *
   * @param array $params
   *
   * @return int ContactID
   */
  protected function getContactId($params) {
    // contactID is set by: membership payment workflow
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
    $contributionRecurId = CRM_Utils_Array::value('contribution_recur_id', $params);
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
    $paymentInstrument = civicrm_api3('OptionValue', 'get', array(
      'option_group_id' => "payment_instrument",
      'name' => $params['name'],
    ));
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
    // also add location name to the array
    $this->_params["address_name-{$this->_bltID}"] = CRM_Utils_Array::value('billing_first_name', $this->_params) . ' ' . CRM_Utils_Array::value('billing_middle_name', $this->_params) . ' ' . CRM_Utils_Array::value('billing_last_name', $this->_params);
    $this->_params["address_name-{$this->_bltID}"] = trim($this->_params["address_name-{$this->_bltID}"]);
    // Add additional parameters that the payment processors are used to receiving.
    if (!empty($this->_params["billing_state_province_id-{$this->_bltID}"])) {
      $this->_params['state_province'] = $this->_params["state_province-{$this->_bltID}"] = $this->_params["billing_state_province-{$this->_bltID}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$this->_bltID}"]);
    }
    if (!empty($this->_params["billing_country_id-{$this->_bltID}"])) {
      $this->_params['country'] = $this->_params["country-{$this->_bltID}"] = $this->_params["billing_country-{$this->_bltID}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$this->_bltID}"]);
    }

    list($hasAddressField, $addressParams) = CRM_Contribute_BAO_Contribution::getPaymentProcessorReadyAddressParams($this->_params, $this->_bltID);
    if ($hasAddressField) {
      $this->_params = array_merge($this->_params, $addressParams);
    }

    $nameFields = array('first_name', 'middle_name', 'last_name');
    foreach ($nameFields as $name) {
      $fields[$name] = 1;
      if (array_key_exists("billing_$name", $this->_params)) {
        $this->_params[$name] = $this->_params["billing_{$name}"];
        $this->_params['preserveDBName'] = TRUE;
      }
    }
    return $fields;
  }
}
