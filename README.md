# Avarda-Payments
Avardas' payment module for Prestashop.

### Language settings

This module reads its language settings from Prestashop:

International -> Localization -> Languages -> `Language code` field

It is required to use the IETF language tags (e.g. en-US, fi-FI) for this module to have correct language shown in checkout.


# Developing

Clone this repo into your modules

```
git clone git@github.com:avarda-ab/PrestaShop-Checkout-3-module.git avardapayments
```

Always start a new branch for your work

Remember to lint your code

```
composer install --dev
php vendor/bin/php-cs-fixer fix
```