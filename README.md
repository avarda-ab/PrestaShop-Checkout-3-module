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

# Releasing a new version

Github action will create a new release package. For the developer the following steps must be taken:

* Make sure your changes are merged to the `main` branch
* Test your changes in the main branch
* Up the version number in `avardapayments.php`
* Update `CHANGELOG.md`
* Make a tag for the release and push it
```
git tag vX.X.X
git push --tags
```
* wait a moment for the new package to be added to the release
* test the package