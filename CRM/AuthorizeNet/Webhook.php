<?php

use CRM_AuthNetEcheck_ExtensionUtil as E;
use \JohnConde\Authnet\AuthnetWebhook as AuthnetWebhook;
use \JohnConde\Authnet\AuthnetApiFactory as AuthnetApiFactory;

class CRM_AuthorizeNet_Webhook {

  use CRM_AuthorizeNet_WebhookTrait;

  /**
   * Get the constant for test/live mode when using JohnConde\Authnet library
   *
   * @return int
   */
  protected function getIsTestMode() {
    return isset($this->_paymentProcessor['is_test']) && $this->_paymentProcessor['is_test'] ? AuthnetApiFactory::USE_DEVELOPMENT_SERVER : AuthnetApiFactory::USE_PRODUCTION_SERVER;
  }

  /**
   * CRM_AuthorizeNet_Webhook constructor.
   *
   * @param array $paymentProcessor
   */
  function __construct($paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  public function getRequest() {
    return AuthnetApiFactory::getWebhooksHandler(
      CRM_Core_Payment_AuthorizeNetCommon::getApiLoginId($this->_paymentProcessor),
      CRM_Core_Payment_AuthorizeNetCommon::getTransactionKey($this->_paymentProcessor),
      $this->getIsTestMode());
  }
  public function getWebhooks() {
    $request = $this->getRequest();
    return $request->getWebhooks();
  }

  public function createWebhook() {
    $request = $this->getRequest();
    $request->createWebhooks(self::getDefaultEnabledEvents(), self::getWebhookPath(TRUE, $this->_paymentProcessor['id']), 'active');
  }

  /**
   * Checks whether the payment processors have a correctly configured
   * webhook (we may want to check the test processors too, at some point, but
   * for now, avoid having false alerts that will annoy people).
   *
   * @see hook_civicrm_check()
   */
  public static function check() {
    $checkMessage = [
      'name' => 'authnet_webhook',
      'label' => 'AuthorizeNet',
    ];
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => ['IN' => ['Payment_AuthNetCreditcard', 'Payment_AuthNeteCheck']],
      'is_active' => 1,
    ]);

    foreach ($result['values'] as $paymentProcessor) {
      $webhook_path = self::getWebhookPath(TRUE, $paymentProcessor['id']);

      try {
        $webhookHandler = new CRM_AuthorizeNet_Webhook($paymentProcessor);
        $webhooks = $webhookHandler->getWebhooks();
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          "{$checkMessage['name']}_webhook",
          E::ts('The %1 (%2) Payment Processor has an error: %3', [
            1 => $paymentProcessor['name'],
            2 => $paymentProcessor['id'],
            3 => $error,
          ]),
          "{$checkMessage['label']} - " . E::ts('API Key'),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );

        continue;
      }

      $found_wh = FALSE;
      foreach ($webhooks->data as $wh) {
        if ($wh->url == $webhook_path) {
          $found_wh = TRUE;
          // Check and update webhook
          $webhookHandler->checkAndUpdateWebhook($wh);
        }
      }

      if (!$found_wh) {
        try {
          $webhookHandler->createWebhook($paymentProcessor['id']);
        }
        catch (Exception $e) {
          $messages[] = new CRM_Utils_Check_Message(
            "{$checkMessage['name']}_webhook",
            E::ts('Could not create webhook. You can review from your account dashboard.<br/>The webhook URL is: %3', [
              1 => $paymentProcessor['name'],
              2 => $paymentProcessor['id'],
              3 => urldecode($webhook_path),
            ]) . ".<br/>Error from {$checkMessage['label']}: <em>" . $e->getMessage() . '</em>',
            "{$checkMessage['label']} " . E::ts('Webhook: %1 (%2)', [
                1 => $paymentProcessor['name'],
                2 => $paymentProcessor['id'],
              ]
            ),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
        }
      }
    }
    return $messages;
  }

  /**
   * List of webhooks we currently handle
   * @return array
   */
  public static function getDefaultEnabledEvents() {
    return [
      'net.authorize.payment.authorization.created',
      'net.authorize.payment.capture.created',
      'net.authorize.payment.authcapture.created',
      'net.authorize.payment.priorAuthCapture.created',
      'net.authorize.payment.refund.created',
      'net.authorize.payment.void.created'
    ];
  }

}
