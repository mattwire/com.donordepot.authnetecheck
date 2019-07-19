# CiviCRM Authorize.Net Payment processor

CiviCRM Extension that provides support for Authorize.Net payments using Credit Card and echeck (EFT).

![Screenshot](/images/authnet_preview.png)

## Features

* Provides a New Payment Processor for eCheck.Net/Credit Card based on Authorize.Net API (AIM Method)
* Supports Recurring Contributions using Authorize.Net Automated Recurring Billing (ARB)
* Supports Webhooks: https://developer.authorize.net/api/reference/features/webhooks.html

## Requirements

 * CiviCRM 5.13+

## Installation
1. Copy this folder, with all of its contents to your civicrm extensions directory.

2. Go to the Extension Manager: http://example.com/civicrm/admin/extensions?reset=1

3. Install the "Authorize.Net" Extension

4. Add a New Payment Processor by going to: http://example.com/civicrm/admin/paymentProcessor?reset=1

## Webhooks

Webhooks are configured automatically when a payment processor is created.

## Development

* Webhooks based on stymiee/authnetjson library - http://www.johnconde.net/blog/handling-authorize-net-webhooks-with-php/
