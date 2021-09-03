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
                        <h1>{l s='Checkout' mod='avardapayments'}</h1>
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
    {/if}

    {if $avardaPurchaseToken == ''}
        {*/
        $purchaseId was not set, which means we don't have a purchaseId -> customer info is missing
        Add fields to ask for email and zipcode, then pass them to controller
    /*}
        <div class="container card p-2">
            <h2>{l s='Email and Zip Code' mod='avardapayments'}</h2>
            <div class="d-flex">
                <label>{l s='Email' d='Shop.Forms.Labels'}</label>
                <br>
                <label class="error" id="error-email">{l s='Invalid email' mod='avardapayments'}</label>
                <input class="w-100 p-1" type="email" name="email" id="opc-email"
                    placeholder="{l s='Email' d='Shop.Forms.Labels'}" required>
            </div>
            <div class="d-flex pt-1">
                <label>{l s='Zip/Postal Code' d='Shop.Forms.Labels'}</label>
                <br>
                <label class="error" id="error-zip">{l s='Invalid ZIP Code' mod='avardapayments'}</label>
                <input class="w-100 p-1" type="number" name="zip" id="opc-zip"
                    placeholder="{l s='Zip/Postal Code' d='Shop.Forms.Labels'}" required>
            </div>
            <button class="btn btn-primary mt-1" onclick="submitInfo()">
                {l s='Continue' d='Shop.Theme.Actions'}
            </button>
        </div>

        {*strip*}
        <script>
            function submitInfo() {
                let error = false;
                let regex = /[\w-]+@([\w-]+\.)+[\w-]+/;
                if (!regex.test(document.getElementById("opc-email").value)) {
                    error = true;
                    document.getElementById("error-email").style.display = "flex";
                } else {
                    document.getElementById("error-email").style.display = "none";
                }

                if (document.getElementById("opc-zip").value.length < 5) {
                    error = true;
                    document.getElementById("error-zip").style.display = "flex";
                } else {
                    document.getElementById("error-zip").style.display = "none";
                }

                if (!error) {
                    $.post("{$avardaCheckoutUrl}", {
                    ajax: true,
                    action: 'adduserdata',
                    zip: document.getElementById("opc-zip").value,
                    email: document.getElementById("opc-email").value
                }).done(function(response) {
                console.log(response);
                if (response === 'error') {
                    console.log('error in customer email');
                    document.getElementById("error-email").style.display = "flex";
                } else {
                    window.location.reload(true);
                }
            });
            }
            }
        </script>
        {*/strip*}
    {else}
        <div id="checkout-options">
            <div class="card p-2">
                {* Render carriers here *}
                <label class="error" id="error-carrier">{l s='Select Carrier' mod='avardapayments'}</label>
                {include file=$carrierTpl delivery_options=$shippingOptions id_address=$addressId delivery_message='' recyclablePackAllowed=$recyclable gift=$giftArray}
            </div>
            {if !$customer.is_logged or $customer.is_guest or !$customer.newsletter}
                <div class="card p-2">
                    {if !$customer.is_logged or $customer.is_guest}
                        <div class="container-checkbox">
                            <input type="checkbox" id="createUser" name="createuser" value="true" onchange="togglePassword()"
                                class="cb-createuser">
                            <label for="createUser" class="user-label">{l s='Create User' mod='avardapayments'}</label>
                            <div id="pswd-container" style="display:none;" class="row py-1">
                                <div class="col-12">
                                    <label for="pass" class="user-label">{l s='Password' mod='avardapayments'}</label>
                                    <br>
                                    <label
                                        class="label-help">{l s='Your password must be at least 8 characters long.' mod='avardapayments'}</label>
                                    <br>
                                    <label class="error" id="error-pswd">{l s='Invalid password' mod='avardapayments'}</label>
                                </div>
                                <div class="col-12 col-md-6">
                                    <input type="password" id="pass" name="password" minlength="8" class="p-1">
                                </div>
                            </div>
                        </div>
                    {/if}
                    {if !$customer.newsletter}
                        <div class="container-checkbox">
                            <input type="checkbox" id="optForNews" name="optfornews" value="true" class="cb-optfornews">
                            <label for="optForNews" class="news-label">{l s='Order Newsletter' mod='avardapayments'}</label>
                        </div>
                    {/if}
                    <!--
                    <input class="changeLanguageButton" type="button" onclick='avardaCheckout.changeLanguage("{$formLanguage}")'
                        value="Change language">
                    -->
                </div>
            {/if}
        </div>
        {strip}
            <script>
                function togglePassword() {
                    $('#pswd-container').toggle();
                    $('#pass').required = !$('#pass').required;
                }

                function toggleGiftMessage() {
                    let gift = false;
                    $('#gift').toggleClass('collapse');
                    if (document.getElementById('input_gift').checked) {
                        gift = true;
                    }
                    $.get("{$avardaCheckoutUrl}", {
                    ajax: true,
                        action: 'updatewrapping',
                        gift: gift
                }).done(function(response) {
                    window.location.reload(true);
                });
                }
            </script>
        {/strip}

        <div class="card" id="avarda-checkout">
        </div>

        {*strip*}
        <script>
            function avardaValidate() {
                console.log('avardaValidate()');
                try {
                    $.post("{$avardaCheckoutUrl}", {
                    ajax: true,
                    action: 'validate'
                }).done(function(response) {
                if (response) {
                    if (response.indexOf('http') === 0) {
                        window.location.href = response;
                    } else if (response === 'retry') {
                        avardaValidate();
                    } else {
                        console.error("Invalid response: ", response);
                    }
                } else {
                    window.location.reload(true);
                }
            }).fail(function(response) {
                console.log('avardaValidate() - failed');
            }).always(function(response) {
                console.log('avardaValidate() - always');
            });
            }
            catch (err) {
                console.log('ERROR');
                console.log(err);
            }
            }

            function postProcessing() {
                $.post("{$avardaCheckoutUrl}", {
                ajax: true,
                action: 'createuser',
            }).done(function(response) {
                console.log('done() - response: ' + response);
                if (response.indexOf('http') === 0) {
                    window.location.href = response;
                } else {
                    avardaValidate();
                }
            });
            }

            function updateCarrier() {
                let deliveryOptions = document.getElementsByName("delivery_option[{$addressId}]");
                deliveryOptions.forEach(function(item, index) {
                    if (item.checked) {
                        carrier_id = item.value;
                    }
                });
                $.get("{$avardaCheckoutUrl}", {
                ajax: true,
                    action: 'updatecarrier',
                    idCarrier: carrier_id,
            }).done(function(response) {
                window.location.hash = '#';
                window.location.hash = '#avarda-checkout';
                window.location.reload(true);
            });
            }

            function preProcessing(payload, avardaCheckoutInstance) {
                let canSubmit = false;
                let pswd = '';
                let createuser = false;
                let optfornews = false;
                let createUserCheckbox = document.getElementById('createUser');
                if (createUserCheckbox) {
                    createuser = createUserCheckbox.checked;
                }
                let optfornewsCheckbox = document.getElementById('optForNews');
                if (optfornewsCheckbox) {
                    optfornews = optfornewsCheckbox.checked;
                }
                /* check if create user is checked */
                if (createuser) {
                    pswd = document.getElementById('pass').value;
                    /* if it is, check if password is valid */
                    if (pswd.length < 8 || pswd.length > 72) {
                        document.getElementById('error-pswd').style.display = 'flex';
                    } else {
                        document.getElementById('error-pswd').style.display = 'none';
                        canSubmit = true;
                    }
                } else {
                    canSubmit = true;
                }
                /* send settings, if everything is ok */
                if (canSubmit) {
                    let carrier_id = -1;
                    let deliveryOptions = document.getElementsByName("delivery_option[{$addressId}]");
                    deliveryOptions.forEach(function(item, index) {
                        if (item.checked) {
                            carrier_id = item.value;
                        }
                    });
                    // check gift and recycle settings
                    let isGift = false;
                    let giftMsg = '';
                    {if $giftArray.enabled}
                        isGift = document.getElementById('input_gift').checked;
                        giftMsg = document.getElementById('gift_message').value;
                    {/if}
                    let recycle = false;
                    {if $recyclable}
                        recycle = document.getElementById('input_recyclable').checked
                    {/if}
                    $.post("{$avardaCheckoutUrl}", {
                    ajax: true,
                        action: 'addusersettings',
                        createUser: createuser,
                        optForNews: optfornews,
                        pswd: pswd,
                        idCarrier: carrier_id,
                        orderMsg: document.getElementById('delivery_message').value,
                        isRecycled: recycle,
                        isGift: isGift,
                        giftMsg: giftMsg
                }).done(function(response) {
                if (response == 'noCarrier') {
                    document.getElementById("error-carrier").style.display = "flex";
                    avardaCheckout.beforeSubmitAbort();
                } else if (response == 'invalidPassword') {
                    document.getElementById("error-pswd").style.display = "flex";
                    avardaCheckout.beforeSubmitAbort();
                } else {
                    avardaCheckout.beforeSubmitContinue();
                }
            });
            }
            else {
                avardaCheckout.beforeSubmitAbort();
            }
            }

            async function avardaBootsrap() {
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



            console.log(initUrl)
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
                "completedPurchaseCallback": postProcessing,
                "sessionTimedOutCallback": sessionTimedOutCallback,
                "beforeSubmitCallback": await preProcessing,
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
                if("{$apiErrorMsg}" === '') avardaBootsrap();
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