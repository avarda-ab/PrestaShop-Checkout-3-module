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

{* TODO: move styles to own css file *}
<style type="text/css">
    ul#tabAvarda li a.btn {
        margin: 5px;
    }
</style>
<div class="panel card">
    <div class="panel-heading card-header">
        <div class="card-header-title">
            <i class="icon-money"></i>
            {l s='Avarda' mod='avardapayments'}
        </div>
    </div>

    <div class="card-body">
        <ul class="nav nav-tabs btn-block" id="tabAvarda">
            <li class="active">
                <a href="#avarda-overview" data-toggle="tab"
                    class="btn btn-lg btn-primary">{l s='Overview' mod='avardapayments'}</a>
            </li>
            <li>
                <a href="#avarda-transactions" data-toggle="tab"
                    class="btn btn-lg  btn-secondary">{l s='Transactions' mod='avardapayments'}</a>
            </li>
        </ul>

        <div class="tab-content panel">
            <div class="tab-pane active" id="avarda-overview">
                <div class="">
                    <dl class="list-detail">
                        <dt>{l s='Status' mod='avardapayments'}</dt>
                        <dd>
                            {if $avardaStatus === 1}
                                <span class="badge badge-success">{l s='Transaction authorized' mod='avardapayments'}</span>
                            {elseif $avardaStatus === 2}
                                <span class="badge badge-success">{l s='Purchase order created' mod='avardapayments'}</span>
                            {elseif $avardaStatus === 3}
                                <span class="badge badge-danger">{l s='Canceled' mod='avardapayments'}</span>
                            {else}
                                <span class="badge badge-danger">{l s='Unknown state' mod='avardapayments'}</span>
                            {/if}
                        </dd>
                        {if $avardaStatus === 1}
                            <dt>{l s='Amount authorized' mod='avardapayments'}</dt>
                            <dd><span
                                    class="badge badge-info">{displayPrice currency=$avardaOrder->id_currency price=$avardaRemaining}</span>
                            </dd>
                            {if $avardaOrder->total_paid_tax_incl != $avardaRemaining}
                                <dt>{l s='Amount to capture' mod='avardapayments'}</dt>
                                <dd><span
                                        class="badge badge-warning">{displayPrice currency=$avardaOrder->id_currency price=$avardaOrder->total_paid_tax_incl}</span>
                                </dd>
                            {/if}
                            <div class="alert alert-info">
                                {l s='Amount will be automatically captured when order transition to [1]%s[/1] state. You can also capture it manually' mod='avardapayments' sprintf=[$avardaDeliveryStatus] tags=['<strong>']}
                            </div>
                            {if $avardaOrder->total_paid_tax_incl > ($avardaRemaining + 0.1)}
                                <div class="alert alert-danger">
                                    {l s='Authorized amount is lower than amount to capture' mod='avardapayments'}
                                </div>
                            {/if}
                        {elseif $avardaStatus === 3}
                            <dt>{l s='Amount canceled' mod='avardapayments'}</dt>
                            <dd><span
                                    class="badge badge-danger">{displayPrice currency=$avardaOrder->id_currency price=$avardaCanceled}</span>
                            </dd>
                        {elseif $avardaStatus === 2}
                            <dt>
                                {if $avardaReturned > 0}
                                    {l s='Amount captured' mod='avardapayments'}
                                {else}
                                    {l s='Amount' mod='avardapayments'}
                                {/if}
                            </dt>
                            <dd><span
                                    class="badge {if $avardaReturned > 0}badge-info{else}badge-success{/if}">{displayPrice currency=$avardaOrder->id_currency price=$avardaCaptured}</span>
                            </dd>
                            {if $avardaReturned > 0}
                                <dt>{l s='Amount returned' mod='avardapayments'}</dt>
                                <dd><span
                                        class="badge badge-danger">{displayPrice currency=$avardaOrder->id_currency price=$avardaReturned}</span>
                                </dd>
                                <dt>{l s='Total amount ' mod='avardapayments'}</dt>
                                <dd><span
                                        class="badge badge-success">{displayPrice currency=$avardaOrder->id_currency price=round($avardaCaptured - $avardaReturned, 4)}</span>
                                </dd>
                            {/if}
                            {if $avardaRemaining > 0}
                                <dt>{l s='Remaining authorized amount' mod='avardapayments'}</dt>
                                <dd><span
                                        class="badge badge-warning">{displayPrice currency=$avardaOrder->id_currency price=$avardaRemaining}</span>
                                </dd>
                            {/if}
                        {/if}
                    </dl>
                    {if $avardaStatus === 1}
                        <div class="form form-inline">
                            <h4>{l s='Process payment' mod='avardapayments'}</h4>
                            <div class="btn-block">
                                <button class="btn btn-primary"
                                    data-avarda-action="capture">{l s='Capture now' mod='avardapayments'}</button>
                                <button class="btn btn-danger"
                                    data-avarda-action="cancel">{l s='Cancel payment' mod='avardapayments'}</button>
                                <input type="text" id="avarda-refund" name="avarda-refund"
                                    placeholder="{l s='Amount to refund' mod='avardapayments'}"
                                    value="{max(0, $avardaRemaining - $avardaOrder->total_paid_tax_incl)}"
                                    class="form-control fixed-width-lg">
                                <button class="btn btn-default"
                                    data-avarda-action="refund">{l s='Refund' mod='avardapayments'}</button>

                            </div>
                        </div>
                    {elseif $avardaStatus === 2 and $avardaRemaining > 0}
                        <div class="form form-infline">
                            <h4>{l s='Return item' mod='avardapayments'}</h4>
                            <input type="text" id="avarda-return" name="avarda-return"
                                placeholder="{l s='Amount to return' mod='avardapayments'}"
                                value="{max(0, $avardaRemaining)}" class="form-control fixed-width-lg">
                            <input type="text" id="avarda-return-reason" name="avarda-return" value=""
                                placeholder="{l s='Reason' mod='avardapayments'}" class="form-control fixed-width-xl">
                            <button class="btn btn-default"
                                data-avarda-action="return">{l s='Return' mod='avardapayments'}</button>
                        </div>
                    {/if}
                </div>
            </div>


            <div class="tab-pane" id="avarda-transactions">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{l s='Date' mod='avardapayments'}</th>
                            <th>{l s='Type' mod='avardapayments'}</th>
                            <th>{l s='Amount' mod='avardapayments'}</th>
                            <th>{l s='Status' mod='avardapayments'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $avardaTransactions as $transaction}
                            <tr class="{if !$transaction->success}danger{/if}">
                                <td>{dateFormat date=$transaction->date_add full=1}</td>
                                <td>{$transaction->type}</td>
                                <td>{displayPrice currency=$avardaOrder->id_currency price=$transaction->amount}</td>
                                <td>
                                    {if $transaction->success}
                                        {l s='Success' mod='avardapayments'}
                                    {else}
                                        {l s='Error' mod='avardapayments'}
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {

        function getUrl() {
            var url = "{$avardaApiUrl}";
            var rand = new Date().getTime();
            if (url.indexOf('?') >= 0) {
                return url + '&rand=' + rand;
            }
            return url + '?rand=' + rand;
        }

        function getPayload(data) {
            var payload = {
                orderId: {$avardaOrder->id}
            };
            for (var i in data) {
                if (data.hasOwnProperty(i)) {
                    payload[i] = data[i];
                }
            }
            return payload;
        }

        function error(error) {
            alert(error);
            refresh();
        }

        function api(cmd, payload, success) {
            var data = getPayload(payload);
            console.log("Api request: ", cmd, data);
            $.ajax({
                url: getUrl(),
                type: 'POST',
                dataType: 'json',
                headers: {
                    'cache-control': 'no-cache'
                },
                data: {
                    action: 'command',
                    cmd: cmd,
                    payload: JSON.stringify(data).replace(/\\n/g, "\\\\n"),
                    ajax: true,
                },
                success: function(data) {
                    console.log("Api response: ", data);
                    if (data && data.success) {
                        if (data.result) {
                            success();
                        } else {
                            error('Failed to perform operation');
                        }
                    } else {
                        error(data.error || 'unknown error');
                    }
                },
                error: function() {
                    error('unknown error');
                }
            });
        }

        function refresh() {
            window.location.reload(true);
        }

        function cancelPayment() {
            api('cancel', {}, refresh);
        }

        function capturePayment() {
            api('capture', {}, refresh);
        }

        function refundPayment() {
            var amount = parseFloat($('#avarda-refund').val());
            if (amount) {
                api('refund', {
                    amount: amount
                }, refresh);
            } else {
                alert('Invalid amount');
            }
        }

        function returnPayment() {
            var amount = parseFloat($('#avarda-return').val());
            var reason = $('#avarda-return-reason').val();
            if (amount && reason) {
                api('return', {
                    amount: amount,
                    reason: reason,
                }, refresh);
            } else {
                if (!amount) {
                    alert('Invalid amount');
                } else if (!reason) {
                    alert('Invalid return reason');
                }
            }
        }

        $(".btn[data-avarda-action]").click(function(e) {
            e.preventDefault();
            var action = $(this).data('avardaAction');
            console.log('Action: ', action);
            switch (action) {
                case 'cancel':
                    return cancelPayment();
                case 'capture':
                    return capturePayment();
                case 'refund':
                    return refundPayment();
                case 'return':
                    return returnPayment();
            }
        });


        {if $avardaCanReturn}
            $(function() {
                $("div.standard_refund_fields .form-group").append(
                    "<p class='checkbox'>" +
                    "    <label for='avardaReturnCheckbox'>" +
                    "      <input type='hidden' id='avardaReturn' name='avardaReturn'>" +
                    "      <input type='checkbox' id='avardaReturnCheckbox' name='avardaReturnCheckbox'>" +
                    "      {l s='Send to avarda' mod='avardapayments'}" +
                    "  </label>" +
                    "</p>"
                );
                $('#avardaReturnCheckbox').click(function() {
                    $('#avardaReturn').val($(this).prop("checked") ? 1 : 0);
                });
            });
        {/if}
    })();
</script>