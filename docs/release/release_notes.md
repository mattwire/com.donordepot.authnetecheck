## Release 2.0.1
* Don't overwrite system messages when performing webhook checks.

## Release 2.0
Implemented Authorize.net Credit Card processor.  This replaces the CiviCRM Core "Authorize.net" processor.

* Implement automatic configuration of webhooks using https://github.com/stymiee/authnetjson.

## Release 1.3
Rewritten by MJW Consulting to work with more recent versions of CiviCRM (5.x).  Funded by https://greenleafadvancement.com

* Implement Authorize.net echeck.net using the [Authorize.Net PHP SDK](https://github.com/AuthorizeNet/sdk-php)
* Implement Cancel/Update Subscription via CiviCRM backend.
* Implement "Submit live payment" via CiviCRM backend.