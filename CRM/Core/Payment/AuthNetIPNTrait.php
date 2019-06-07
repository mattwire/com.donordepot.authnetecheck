<?php
/**
 * https://civicrm.org/licensing
 */

/**
 * Shared payment IPN functions that should one day be migrated to CiviCRM core
 */

trait CRM_Core_Payment_AuthNetIPNTrait {
  /**********************
   * MJW_Core_Payment_IPNTrait: 20190707
   * @requires MJW_Payment_Api: 20190707
   *********************/

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * Do we send an email receipt for each contribution?
   *
   * @var int
   */
  protected $is_email_receipt = NULL;

  /**
   * The recurring contribution ID associated with the transaction
   * @var int
   */
  protected $contribution_recur_id = NULL;

  /**
   *  The IPN event type
   * @var string
   */
  protected $event_type = NULL;

  /**
   * Set the value of is_email_receipt to use when a new contribution is received for a recurring contribution
   * If not set, we respect the value set on the ContributionRecur entity.
   *
   * @param int $sendReceipt The value of is_email_receipt
   */
  public function setSendEmailReceipt($sendReceipt) {
    switch ($sendReceipt) {
      case 0:
        $this->is_email_receipt = 0;
        break;

      case 1:
        $this->is_email_receipt = 1;
        break;

      default:
        $this->is_email_receipt = 0;
    }
  }

  /**
   * Get the value of is_email_receipt to use when a new contribution is received for a recurring contribution
   * If not set, we respect the value set on the ContributionRecur entity.
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  public function getSendEmailReceipt() {
    if (isset($this->is_email_receipt)) {
      return (int) $this->is_email_receipt;
    }
    if (!empty($this->contribution_recur_id)) {
      $this->is_email_receipt = civicrm_api3('ContributionRecur', 'getvalue', [
        'return' => "is_email_receipt",
        'id' => $this->contribution_recur_id,
      ]);
    }
    return (int) $this->is_email_receipt;
  }

  /**
   * Get the payment processor
   *   The $_GET['processor_id'] value is set by CRM_Core_Payment::handlePaymentMethod.
   */
  protected function getPaymentProcessor() {
    $paymentProcessorId = (int) CRM_Utils_Array::value('processor_id', $_GET);
    if (empty($paymentProcessorId)) {
      $this->exception('Failed to get payment processor id');
    }

    try {
      $this->_paymentProcessor = \Civi\Payment\System::singleton()->getById($paymentProcessorId)->getPaymentProcessor();
    }
    catch(Exception $e) {
      $this->exception('Failed to get payment processor');
    }
  }

  /**
   * @deprecated Use recordCancelled()
   * Mark a contribution as cancelled and update related entities
   *
   * @param array $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function canceltransaction($params) {
    return $this->incompletetransaction($params, 'cancel');
  }

  /**
   * @deprecated - Use recordFailed()
   * Mark a contribution as failed and update related entities
   *
   * @param array $params [ 'id' -> contribution_id, 'payment_processor_id' -> payment_processor_id]
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function failtransaction($params) {
    return $this->incompletetransaction($params, 'fail');
  }

  /**
   * @deprecated - Use recordXX methods
   * Handler for failtransaction and canceltransaction - do not call directly
   *
   * @param array $params
   * @param string $mode
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function incompletetransaction($params, $mode) {
    $requiredParams = ['id', 'payment_processor_id'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $this->exception('canceltransaction: Missing mandatory parameter: ' . $required);
      }
    }

    if (isset($params['payment_processor_id'])) {
      $input['payment_processor_id'] = $params['payment_processor_id'];
    }
    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->id = $params['id'];
    if (!$contribution->find(TRUE)) {
      throw new CiviCRM_API3_Exception('A valid contribution ID is required', 'invalid_data');
    }

    if (!$contribution->loadRelatedObjects($input, $ids, TRUE)) {
      throw new CiviCRM_API3_Exception('failed to load related objects');
    }

    $input['trxn_id'] = !empty($params['trxn_id']) ? $params['trxn_id'] : $contribution->trxn_id;
    if (!empty($params['fee_amount'])) {
      $input['fee_amount'] = $params['fee_amount'];
    }

    $objects['contribution'] = &$contribution;
    $objects = array_merge($objects, $contribution->_relatedObjects);

    $transaction = new CRM_Core_Transaction();
    switch ($mode) {
      case 'cancel':
        return $this->cancelled($objects, $transaction);

      case 'fail':
        return $this->failed($objects, $transaction);

      default:
        throw new CiviCRM_API3_Exception('Unknown incomplete transaction type: ' . $mode);
    }
  }

  protected function recordPending($params) {
    // Nothing to do
    // @todo Maybe in the future record things like the pending reason if a payment is temporarily held?
  }

  /**
   * Record a completed (successful) contribution
   * @param array $params
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordCompleted($params) {
    $description = 'recordCompleted';
    $this->checkRequiredParams($description, ['contribution_id'], $params);
    $contributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    if (!empty($params['contribution_recur_id'])) {
      $this->recordRecur($params, $description, $contributionStatusID);
    }
    else {
      $params['id'] = $params['contribution_id'];
      $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
      if (civicrm_api3('Contribution', 'getvalue', [
          'return' => "contribution_status_id",
          'id' => $params['id']
        ]) == $pendingStatusId) {
        civicrm_api3('Contribution', 'completetransaction', $params);
      }
    }
  }

  /**
   * Record a failed contribution
   * @param array $params
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordFailed($params) {
    $description = 'recordFailed';
    $this->checkRequiredParams($description, ['contribution_id'], $params);
    $contributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');

    if (!empty($params['contribution_recur_id'])) {
      $this->recordRecur($params, $description, $contributionStatusID);
    }
    else {
      $this->recordSingle($params, $description, $contributionStatusID);
    }
  }

  /**
   * Record a cancelled contribution
   * @param array $params
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordCancelled($params) {
    $description = 'recordCancelled';
    $this->checkRequiredParams($description, ['contribution_id'], $params);
    $contributionStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled');

    if (!empty($params['contribution_recur_id'])) {
      $this->recordRecur($params, $description, $contributionStatusID);
    }
    else {
      $this->recordSingle($params, $description, $contributionStatusID);
    }
  }

  /**
   * Record a refunded contribution
   * @param array $params
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function recordRefund($params) {
    $this->checkRequiredParams('recordRefund', ['contribution_id', 'total_amount'], $params);

    if ($params['total_amount'] > 0) {
      $params['total_amount'] = -$params['total_amount'];
    }
    if (empty($params['trxn_date'])) {
      $params['trxn_date'] = date('YmdHis');
    }

    civicrm_api3('Payment', 'create', $params);
  }

  /**
   * Check that required params are present
   *
   * @param string $description
   *   For error logs
   * @param array $requiredParams
   *   Array of params that are required
   * @param array $params
   *   Array of params to check
   */
  protected function checkRequiredParams($description, $requiredParams, $params) {
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $this->exception("{$description}: Missing mandatory parameter: {$required}");
      }
    }
  }

  /**
   * Record a contribution against a recur (subscription)
   * @param array $params
   * @param string $description
   * @param int $contributionStatusID
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function recordRecur($params, $description, $contributionStatusID) {
    // Process as a payment in a recurring series
    // We should have been passed a contribution_id, this either needs updating via completetransaction or repeating via repeattransaction
    // If we've already processed it then we'll have a payment with the unique transaction ID
    $this->checkRequiredParams($description, ['contribution_id', 'contribution_recur_id', 'payment_processor_transaction_id'], $params);
    $matchingContributions = civicrm_api3('Mjwpayment', 'get_contribution', ['trxn_id' => $params['payment_processor_transaction_id']]);
    if ($matchingContributions['count'] == 0) {
      // This is a new transaction Id in a recurring series, trigger repeattransaction
      // @fixme: We may need to consider handling partial payments on the same "invoice/order" (contribution)
      //   but for now we assume that a new "completed" transaction means a new payment
      $repeatParams = [
        'contribution_status_id' => $contributionStatusID,
        'original_contribution_id' => $params['contribution_id'],
        'contribution_recur_id' => $params['contribution_recur_id'],
        'trxn_id' => $params['payment_processor_transaction_id'],
        'is_email_receipt' => $this->getSendEmailReceipt(),
      ];
      civicrm_api3('Contribution', 'repeattransaction', $repeatParams);
    }
  }

  /**
   * Record a change to a single contribution (eg. Failed/Cancelled).
   *
   * @param array $params
   * @param string $description
   * @param int $contributionStatusID
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function recordSingle($params, $description, $contributionStatusID) {
    $params['id'] = $params['contribution_id'];
    $params['contribution_status_id'] = $contributionStatusID;
    civicrm_api3('Contribution', 'create', $params);
  }

  protected function recordSubscriptionCancelled($params = []) {
    civicrm_api3('ContributionRecur', 'cancel', ['id' => $this->contribution_recur_id]);
  }

  /**
   * Log and throw an IPN exception
   *
   * @param string $message
   */
  protected function exception($message) {
    $errorMessage = $this->getPaymentProcessorLabel() . ' Exception: Event: ' . $this->event_type . ' Error: ' . $message;
    Civi::log()->debug($errorMessage);
    http_response_code(400);
    exit(1);
  }
}
