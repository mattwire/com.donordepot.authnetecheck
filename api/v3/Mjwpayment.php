<?php
/**
 * https://civicrm.org/licensing
 */

/**********************
 * MJW_Payment_Api: 20190707
 *********************/

/**
 * @todo mjwpayment.get_contribution is a replacement for Contribution.get
 *   mjwpayment.get_payment is a replacement for Payment.get
 *   which support querying by contribution/payment trxn_id per https://github.com/civicrm/civicrm-core/pull/14748
 *   - These API functions should be REMOVED once core has the above PR merged and we increment the min version for the extension.
 *   - The change is small, but to re-implement them here we have to copy quite a lot over.
 */
/**
 * Adjust Metadata for Get action.
 *
 * The metadata is used for setting defaults, documentation & validation.
 *
 * @param array $params
 *   Array of parameters determined by getfields.
 */
function _civicrm_api3_mjwpayment_get_contribution_spec(&$params) {
  $params['contribution_test'] = [
    'api.default' => 0,
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'title' => 'Get Test Contributions?',
    'api.aliases' => ['is_test'],
  ];

  $params['financial_type_id']['api.aliases'] = ['contribution_type_id'];
  $params['payment_instrument_id']['api.aliases'] = ['contribution_payment_instrument', 'payment_instrument'];
  $params['contact_id'] = CRM_Utils_Array::value('contribution_contact_id', $params);
  $params['contact_id']['api.aliases'] = ['contribution_contact_id'];
  unset($params['contribution_contact_id']);
}

/**
 * Retrieve a set of contributions.
 *
 * @param array $params
 *  Input parameters.
 *
 * @return array
 *   Array of contributions, if error an array with an error id and error message
 */
function civicrm_api3_mjwpayment_get_contribution($params) {
  $contributionResult = civicrm_api3('Contribution', 'get', $params);
  $foundContributions = CRM_Utils_Array::value('values', $contributionResult, []);
  $contributions = [];

  // If we have a trxn_id check payments for that transaction ID and also return any contributions associated with those payments
  // An additional array property "payment_trxn_id" will be available containing all found trxn_ids (eg. if you did ['LIKE' => 'test124%'])
  if (!empty($params['trxn_id'])) {
    $payments = civicrm_api3('Payment', 'get', $params);
    if (!empty($payments['count'])) {
      foreach ($payments['values'] as $paymentID => $paymentValues) {
        if (empty($contributions[$paymentValues['contribution_id']])) {
          // Get the details of each additional contribution found via a payment
          $contributions[$paymentValues['contribution_id']] = CRM_Contribute_BAO_Contribution::getValuesWithMappings(['id' => $paymentValues['contribution_id']]);
        }
        $contributions[$paymentValues['contribution_id']]['payment_trxn_id'][] = $paymentValues['trxn_id'];
      }
    }
  }

  foreach ($contributions as $id => $contribution) {
    $softContribution = CRM_Contribute_BAO_ContributionSoft::getSoftContribution($id, TRUE);
    $contributions[$id] = array_merge($contributions[$id], $softContribution);
    // format soft credit for backward compatibility
    _civicrm_api3_mjwpayment_format_soft_credit($contributions[$id]);
    _civicrm_api3_mjwpayment_contribution_add_supported_fields($contributions[$id]);
  }
  foreach($foundContributions as $id => $detail) {
    if (isset($contributions[$id])) {
      $foundContributions[$id] = $contributions[$id];
    }
  }
  return civicrm_api3_create_success($contributions, $params, 'Contribution', 'get');
}

/**
 * This function is used to format the soft credit for backward compatibility.
 *
 * As of v4.4 we support multiple soft credit, so now contribution returns array with 'soft_credit' as key
 * but we still return first soft credit as a part of contribution array
 *
 * @param $contribution
 */
function _civicrm_api3_mjwpayment_format_soft_credit(&$contribution) {
  if (!empty($contribution['soft_credit'])) {
    $contribution['soft_credit_to'] = $contribution['soft_credit'][1]['contact_id'];
    $contribution['soft_credit_id'] = $contribution['soft_credit'][1]['soft_credit_id'];
  }
}

/**
 * Support for supported output variables.
 *
 * @param $contribution
 */
function _civicrm_api3_mjwpayment_contribution_add_supported_fields(&$contribution) {
  // These are output fields that are supported in our test contract.
  // Arguably we should also do the same with 'campaign_id' &
  // 'source' - which are also fields being rendered with unique names.
  // That seems more consistent with other api where we output the actual field names.
  $outputAliases = [
    'contribution_check_number' => 'check_number',
    'contribution_address_id' => 'address_id',
    'payment_instrument_id' => 'instrument_id',
    'contribution_cancel_date' => 'cancel_date',
  ];
  foreach ($outputAliases as $returnName => $copyTo) {
    if (array_key_exists($returnName, $contribution)) {
      $contribution[$copyTo] = $contribution[$returnName];
    }
  }

}


/**
 * Adjust Metadata for Get action.
 *
 * The metadata is used for setting defaults, documentation & validation.
 *
 * @param array $params
 *   Array of parameters determined by getfields.
 */
function _civicrm_api3_mjwpayment_get_payment_spec(&$params) {
  $params = [
    'contribution_id' => [
      'title' => 'Contribution ID',
      'type' => CRM_Utils_Type::T_INT,
    ],
    'entity_table' => [
      'title' => 'Entity Table',
      'api.default' => 'civicrm_contribution',
    ],
    'entity_id' => [
      'title' => 'Entity ID',
      'type' => CRM_Utils_Type::T_INT,
      'api.aliases' => ['contribution_id'],
    ],
    'trxn_id' => [
      'title' => 'Transaction ID',
      'type' => CRM_Utils_Type::T_STRING,
    ],
  ];
}

/**
 * Retrieve a set of financial transactions which are payments.
 *
 * @param array $params
 *  Input parameters.
 *
 * @return array
 *   Array of financial transactions which are payments, if error an array with an error id and error message
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_mjwpayment_get_payment($params) {
  $financialTrxn = [];
  $limit = '';
  if (isset($params['options']) && CRM_Utils_Array::value('limit', $params['options'])) {
    $limit = CRM_Utils_Array::value('limit', $params['options']);
  }
  $params['options']['limit'] = 0;

  $ftParams['is_payment'] = 1;
  if ($limit) {
    $ftParams['options']['limit'] = $limit;
  }

  if (!empty($params['trxn_id'])) {
    $ftParams['trxn_id'] = $params['trxn_id'];
    $financialTrxn = civicrm_api3('FinancialTrxn', 'get', $ftParams);
    if (!empty($financialTrxn['count'])) {
      $financialTrxnIDs = CRM_Utils_Array::collect('id', CRM_Utils_Array::value('values', $financialTrxn));
      $params['financial_trxn_id'] = ['IN' => $financialTrxnIDs];
      $eft = civicrm_api3('EntityFinancialTrxn', 'get', $params);
      foreach ($eft['values'] as $eftID => $eftValues) {
        $financialTrxn['values'][$eftValues['financial_trxn_id']]['contribution_id'] = $eftValues['entity_id'];
      }
    }
  }
  else {
    $eft = civicrm_api3('EntityFinancialTrxn', 'get', $params);
    if (!empty($eft['values'])) {
      $eftIds = [];
      foreach ($eft['values'] as $efts) {
        if (empty($efts['financial_trxn_id'])) {
          continue;
        }
        $eftIds[] = $efts['financial_trxn_id'];
        $map[$efts['financial_trxn_id']] = $efts['entity_id'];
      }
      if (!empty($eftIds)) {
        $ftParams['id'] = ['IN' => $eftIds];
        $financialTrxn = civicrm_api3('FinancialTrxn', 'get', $ftParams);
        foreach ($financialTrxn['values'] as &$values) {
          $values['contribution_id'] = $map[$values['id']];
        }
      }
    }
  }
  return civicrm_api3_create_success(CRM_Utils_Array::value('values', $financialTrxn, []), $params, 'Payment', 'get');
}
