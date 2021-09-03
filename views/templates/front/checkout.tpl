{*
 * Copyright (C) 2019 Petr Hucik <petr@getdatakick.com>
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@getdatakick.com so we can send you a copy immediately.
 *
 * @author    Petr Hucik <petr@getdatakick.com>
 * @copyright 2017-2019 Petr Hucik
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *}
{extends file='checkout/checkout.tpl'}
{block name='content'}
    {capture name=path}{l s='Checkout' mod='avardapayments'}{/capture}
    {* render errors and warnings *}
    {if isset($avardaError)}
        <div class="alert alert-warning">
            {$avardaError|escape:'html':'UTF-8'}
        </div>
    {/if}

    {* render cart information *}
    {if $showCart && isset($cart)}
        <div class="row">
            {* cart products detailed *}
            <div class="cart-grid-body col-xs-12 col-lg-8">
                <div class="card cart-container">
                    <div class="card-block">
                        <h1 class="h1">{l s='Shopping Cart' mod='avardapayments'}</h1>
                    </div>
                    <hr class="separator">
                    {include file='checkout/_partials/cart-detailed.tpl' cart=$cart}
                </div>
            </div>

            {* cart summary *}
            <div class="cart-grid-right col-xs-12 col-lg-4">
                {block name='cart_summary'}
                    <div class="card cart-summary">
                        {block name='hook_shopping_cart'}
                            {hook h='displayShoppingCart'}
                        {/block}

                        {block name='cart_totals'}
                            {include file='checkout/_partials/cart-detailed-totals.tpl' cart=$cart}
                        {/block}
                    </div>
                {/block}
            </div>
        </div>
<!--
        <div id="checkout-options">
            <div class="card p-2">
                <input class="changeLanguageButton" type="button" onclick='avardaCheckout.changeLanguage("{$formLanguage}")'
                    value="Change language">
            </div>
        </div>
        -->
    {/if}
    {if isset($avardaPurchaseToken)}
        <div class="card" id="avarda-checkout">
        </div>
        {*strip*}
        <script>
            function avardaValidate() {
                $.post("{$avardaCheckoutUrl}", {
                ajax: true,
                action: 'compareuser'
            }).done(function(response) {
            $.post("{$avardaCheckoutUrl}", {
            ajax: true,
            action: 'validate'
            }).done(function(response) {
            if (response) {
                if (response.indexOf('http') === 0) {
                    window.location.href = response;
                } else if (response === 'validateFailed') {
                    avardaValidate();
                }
            }
            });
            });
            }

            function avardaBootsrap() {
                let initUrl = ''
                if("{$apiEnv}" === 'prod') {
                initUrl = "https://avdonl0p0checkout0fe.blob.core.windows.net/frontend/static/js/main.js"
            } else {
                initUrl = "https://avdonl0s0checkout0fe.blob.core.windows.net/frontend/static/js/main.js"
            }
            /*
            Literal tells smarty that the lines shouldn't be parsed.
            */
            {literal}
                (function(e,t,n,a,s,c,o,i,r){e[a]=e[a]||function(){(e[a].q=e[a].q||[]).push(arguments)};

                e[a].i = s;
                i = t.createElement(n);
                i.async = 1;
                i.src = o + "?v=" + c + "&ts=" + 1 * new Date;

                r = t.getElementsByTagName(n)[0];
            {/literal}
            r.parentNode.insertBefore(i, r)
            })(window, document, "script", "avardaCheckoutInit", "avardaCheckout", "1.0.0", initUrl);



            var sessionTimedOutCallback = function(avardaCheckoutInstance) {
                //This is required
                //console.log("Session Timed Out - Handle here!")
            };

            window.avardaCheckoutInit({
                "purchaseJwt": "{$avardaPurchaseToken}",
                "rootElementId": "avarda-checkout",
                "redirectUrl": "{$avardaCheckoutUrl}",
                "styles": {},
                "disableFocus": true,
                "completedPurchaseCallback": avardaValidate,
                "sessionTimedOutCallback": sessionTimedOutCallback,
                "CompletedNotificationUrl": "{$paymentCallbackUrl}"
            });

            prestashop.on('updateCart', function() {
            $.post("{$avardaCheckoutUrl}", {
            ajax: true,
            action: 'updateCart'
            }).done(function(response) {
            window.avardaCheckout.refreshForm();
            window.location.reload(true);
            });
            });

            }

            if (document.readyState === 'complete') {
                if('{$apiErrorMsg}' === '') avardaBootsrap();
            } else {
                document.addEventListener('readystatechange', function() {
                    if (document.readyState === 'complete') {
                        if('{$apiErrorMsg}' === '') avardaBootsrap();
                    }
                });
            }
        </script>
        {*/strip*}
    {/if}
{/block}