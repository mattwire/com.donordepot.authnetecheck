<?php

trait CRM_AuthorizeNet_WebhookTrait {
  /**********************
   * MJW_Webhook_Trait: 20190602
   *********************/

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * Get the path of the webhook depending on the UF (eg Drupal, Joomla, Wordpress)
   *
   * @param bool $includeBaseUrl
   * @param string $pp_id
   *
   * @return string
   */
  public static function getWebhookPath($includeBaseUrl = TRUE, $paymentProcessorId = 'NN') {
    // Assuming frontend URL because that's how the function behaved before.
    // @fixme this doesn't return the right webhook path on Wordpress (often includes an extra path between .com and ? eg. abc.com/xxx/?page=CiviCRM
    // We can't use CRM_Utils_System::url('civicrm/payment/ipn/' . $paymentProcessorId, NULL, $includeBaseUrl, NULL, FALSE, TRUE);
    //  because it returns the query string urlencoded and the base URL non urlencoded so we can't use to match existing webhook URLs

    $UFWebhookPaths = [
      "Drupal"    => "civicrm/payment/ipn/{$paymentProcessorId}",
      "Joomla"    => "?option=com_civicrm&task=civicrm/payment/ipn/{$paymentProcessorId}",
      "WordPress" => "?page=CiviCRM&q=civicrm/payment/ipn/{$paymentProcessorId}"
    ];

    $basePage = '';
    $config = CRM_Core_Config::singleton();
    if (!empty($config->wpBasePage) && $config->userFramework == 'WordPress') {
      // Add in the wordpress base page to the URL.
      $basePage = (substr($config->wpBasePage, -1) == '/') ? $config->wpBasePage : "$config->wpBasePage/";
    }
    // Use Drupal path as default if the UF isn't in the map above
    $UFWebhookPath = (array_key_exists(CIVICRM_UF, $UFWebhookPaths)) ? $UFWebhookPaths[CIVICRM_UF] : $UFWebhookPaths['Drupal'];
    if ($includeBaseUrl) {
      return CRM_Utils_System::baseURL() . $basePage . $UFWebhookPath;
    }
    return $UFWebhookPath;
  }

}
