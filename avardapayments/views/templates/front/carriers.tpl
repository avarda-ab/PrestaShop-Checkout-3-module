{*
{block name='step_content'}
  <div id="hook-display-before-carrier">
    {$hookDisplayBeforeCarrier nofilter}
  </div>
*}
<h1 class="title">{l s='Shipping' mod='avardapayments'}</h1>
<div class="delivery-options-list">
    {if $delivery_options|count}
        <div class="form-fields">
            <div class="delivery-options">
              {foreach from=$delivery_options item=carrier key=carrier_id}
                  <div class="row delivery-option container-radio">
                    <div class="col-sm-1">
                      <span class="custom-radio float-xs-left">
                        <input type="radio" name="delivery_option[{$id_address}]" id="delivery_option_{$carrier.id}" value="{$carrier.id}"{if $carrier.preselected} checked{/if} onclick="updateCarrier()">
                        <span></span>
                      </span>
                    </div>
                    <label for="delivery_option_{$carrier.id}" class="delivery-option-2">
                      <div class="row">
                          <div class="row carrier{if $carrier.logo} carrier-hasLogo{/if} align-items-center">
                            {if $carrier.logo}
                              <img src="{$carrier.logo}" alt="{$carrier.name}" loading="lazy" class="carrier-logo" />
                            {/if}
                            <div class="col-xs-12 carriere-name-container{if $carrier.logo} col-md-10{/if}">
                              <span class="h6 carrier-name">{$carrier.name}</span>
                              {if $carrier.price == 0}
                                {assign 'price' {l s='Free' mod='avardapayments'}}
                              {else}
                                {assign 'price' $carrier.price}
                              {/if}
                              <span class="carrier-price">{$price}</span>
                            </div>
                        </div>
                        <div class="w-100">
                          <span class="carrier-delay">{$carrier.delay}</span>
                        </div>
                      </div>
                    </label>
                    {if isset($carrier.extraContent)}
                        <div class="row carrier-extra-content"{if !$carrier.preselected} style="display:none;"{/if}>
                          {$carrier.extraContent nofilter}
                        </div>
                      {/if}
                  </div>
                  <div class="clearfix"></div>
              {/foreach}
            </div>
          <div class="order-options">
            <div id="delivery" class="container-textarea">
              <label for="delivery_message">{l s='If you would like to add a comment about your order, please write it in the field below.' d='Shop.Theme.Checkout'}</label>
              <textarea rows="2" cols="120" id="delivery_message" name="delivery_message">{$delivery_message}</textarea>
            </div>

            {if $recyclablePackAllowed}
              <span class="custom-checkbox">
                <input type="checkbox" id="input_recyclable" name="recyclable" value="1" {if $recyclable} checked {/if}>
                <span><i class="material-icons rtl-no-flip checkbox-checked">&#xE5CA;</i></span>
                <label for="input_recyclable">{l s='I would like to receive my order in recycled packaging.' d='Shop.Theme.Checkout'}</label>
              </span>
            {/if}
            
            {if $giftArray.enabled}
              <span class="custom-checkbox">
                <input class="js-gift-checkbox" id="input_gift" name="gift" type="checkbox" value="1" onchange="toggleGiftMessage()" {if $giftArray.isGift}checked="checked"{/if}>
                <span><i class="material-icons rtl-no-flip checkbox-checked">&#xE5CA;</i></span>
                <label for="input_gift">{$giftArray.label}</label >
              </span>
              <div id="gift" class="collapse{if $giftArray.isGift} in{/if}">
                <label for="gift_message">{l s='If you\'d like, you can add a note to the gift:' d='Shop.Theme.Checkout'}</label>
                <textarea rows="2" cols="120" id="gift_message" name="gift_message">{$giftArray.message}</textarea>
              </div>
            {/if}

          </div>
        </div>
    {else}
      <p class="alert alert-danger">{l s='Unfortunately, there are no carriers available for your delivery address.' d='Shop.Theme.Checkout'}</p>
    {/if}
  </div>
  {*  
  <div id="hook-display-after-carrier">
    {$hookDisplayAfterCarrier nofilter}
  </div>
  *}
  <div id="extra_carrier"></div>