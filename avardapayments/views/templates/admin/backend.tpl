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
 {* opc setting *}
{assign 'settings' $avarda.settings}
{assign 'postUrl' $avarda.apiUrl}
{* translations *}
{capture name='enabled'}{l s='enabled' mod='avardapayments'}{/capture}
{capture name='enable'}{l s='Enable' mod='avardapayments'}{/capture}
{capture name='disabled'}{l s='disabled' mod='avardapayments'}{/capture}
{capture name='disable'}{l s='Disable' mod='avardapayments'}{/capture}
{if $settings.useOnePage}
  {assign 'status' $smarty.capture.enabled}
  {assign 'action' $smarty.capture.disable}
  {assign 'onePageCheckout' 0}
{else}
  {assign 'status' $smarty.capture.disabled}
  {assign 'action' $smarty.capture.enable}
  {assign 'onePageCheckout' 1}
{/if}
<div class="MuiPaper-root MuiPaper-elevation1 jss44 MuiPaper-rounded opc-paper">
    <h2 class="jss45">{l s='One Page Checkout' mod='avardapayments'}</h2>
    <p>{l s='One Page checkout is' mod='avardapayments'} <span id="opc-status">{$status}</span></p>
    <button class="MuiButtonBase-root MuiButton-root MuiButton-text MuiButton-textPrimary" tabindex="0" type="button" id="opc-button">
      <div class="MuiButton-label">
        <span id="opc-action">{$action}</span>
      </div>
      <span class="MuiTouchRipple-root"></span>
    </button>
</div>
<div class="MuiPaper-root MuiPaper-elevation1 jss44 MuiPaper-rounded opc-paper">
  <h2 class="jss45">{l s='Module information' mod='avardapayments'}</h2>
  <div>
    <label class="module-label">{l s="Module name" mod='avardapayments'}</label>
    <div>
      <input id="module-name" class="module-name-input" type="text" value="{$settings.moduleInfo['moduleName']}">
    </div>
  </div>
  <div>
    <label class="module-label-description">{l s="Module description" mod='avardapayments'}</label>
  </div>
  <div>
    <textarea id="module-description" class="module-description" type="text" value="{$settings.moduleInfo['moduleDescription']}">{$settings.moduleInfo['moduleDescription']}</textarea>
  </div>
  <div class="jss35">
    {l s="Shown for customers when one page checkout is disabled" mod='avardapayments'}
  </div>
    <div class="jss35">
    {l s="NOTE: There is no multilingual support for these inputs.
    
    If you have multilingual website and you are using one page checkout, leave these fields empty. The module will then use default values which are translatable in PrestaShop->International->Translations (Modify translations: Installed modules translations: Avarda)." mod='avardapayments'}
  </div>
  <button class="module-save-button" tabindex="0" type="button" id="module-save-button">{l s="Save" mod='avardapayments'}</button>
</div>
<div id="avarda-app"></div>

<script>
  let data = {
    settings: {$settings|@json_encode}
  };
  let useOP = {$onePageCheckout};

  (function(){
    var started = false;
    var attempt = 0;
    function startAvardaApp() {
      if (started) {
        return;
      }
      if (window.startAvarda) {
        started = true;
        startAvarda({$avarda|json_encode});
      } else {
        attempt++;
        console.log('['+attempt+'] Avarda not loaded yet, waiting...');
        setTimeout(startAvardaApp, 100);
      }
    }
    startAvardaApp();
  })();
  $(document).ready(function() {
    $('#opc-button').on('click', function() {
      data['settings']['useOnePage'] = useOP;
      $.post("{$postUrl}", {
          ajax: true,
          action: 'command',
          dataType: 'json',
          payload: JSON.stringify(data).replace(/\\n/g, "\\\\n"),
          cmd: 'saveSettings'
      }).done(function (response) {
        result = JSON.parse(response);
        console.log(result['success']);
        if(result['success']) {
          if(useOP) {
            useOP = 0;
            $('#opc-status').text('{l s='enabled' mod='avardapayments'}');
            $('#opc-action').text('{l s='Disable' mod='avardapayments'}');
          } else {
            useOP = 1;
            $('#opc-status').text('{l s='disabled' mod='avardapayments'}');
            $('#opc-action').text('{l s='Enable' mod='avardapayments'}');
          }
        } else {
          console.log('error saving settings: ' + response);
        }
      });
    });
  });

  $(document).ready(function() {
    $('#module-save-button').on('click', function() {
      $.post("{$postUrl}", {
          ajax: true,
          action: 'command',
          dataType: 'json',
          payload: getModuleInfoJSON(data),
          cmd: 'saveSettings'
      }).done(function (response) {
        result = JSON.parse(response);
        if(result['success']) {
          alert("Settings saved");
          window.location.reload(true);
        } else {
          alert("Error saving settings:" + response);
          window.location.reload(true);
        }
      });
    });
  });

function getModuleInfoJSON(data) {

  $moduleName = document.getElementById("module-name").value;
  $moduleDescription = document.getElementById("module-description").value;

  if ($moduleName === null || $moduleName === '') {
    $moduleName = 'Avarda';
  }

  if ($moduleDescription === null || $moduleDescription === '') {
    $moduleDescription = 'Payment option';
  }

  let moduleInfo = {
        "moduleName": $moduleName,
        "moduleDescription": $moduleDescription
  };

  data.settings["moduleInfo"] = moduleInfo;

  var json = JSON.stringify(data);
  return json;
}

</script>

<style>
.module-save-button {
  background-color: #3f51b5;
  border: none;
  border-radius: 8px;
  color: white;
  padding: 10px 24px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
  font-size: 12px;
  margin: 2px 1px;
  cursor: pointer;
}

.module-save-button:hover {
  background-color: rgba(63, 81, 181, 0.08);
}

.opc-paper {
  margin: 30px 0 0 0;
  padding: 0px 24px 30px 24px;
}

.opc-paper h2 {
    color: #666;
    margin: 0;
    display: flex;
    font-size: 1.5rem;
    min-height: 64px;
    align-items: center;
    font-weight: 500;
}

.opc-paper p {
  padding: 6px 8px;
}

.module-label {
  color: rgba(0, 0, 0, 0.54);
  padding: 0;
  font-size: 0.72rem;
  font-family: "Roboto", "Helvetica", "Arial", sans-serif;
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.000938em;
}

.module-label-description {
  color: rgba(0, 0, 0, 0.54);
  padding: 0;
  padding-top: 2;
  font-size: 0.72rem;
  font-family: "Roboto", "Helvetica", "Arial", sans-serif;
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.000938em;
}

.module-name-input {
    font-size: 16px;
    color: currentColor;
    width: 50%;
    border-color: rgba(0, 0, 0, 0.54);
    border-width: 0 0 2px;
    height: 1.1875em;
    margin: 0;
    margin-bottom: 16px;
    display: block;
    padding: 6px 0 0px;
    min-width: 0;
    background: none;
    box-sizing: content-box;
}

.module-name-input:hover {
    font-size: 16px;
    width: 50%;
    border-color: #000;
    border-width: 0 0 2px !important;
    height: 1.1875em;
    margin: 0;
    margin-bottom: 16px;
    display: block;
    padding: 6px 0 0px;
    min-width: 0;
    background: none;
    box-sizing: content-box;
}

.module-description {
  width: 100%;
  height: 200px;
  padding: 12px 20px;
  box-sizing: border-box;
  border: 2px solid #ccc;
  border-radius: 4px;
  background-color: #f8f8f8;
  font-size: 16px;
  font-family: "Roboto", "Helvetica", "Arial", sans-serif;
}

/* mui button */

.MuiButton-root {
  color: rgba(0, 0, 0, 0.87);
  padding: 6px 16px;
  font-size: 0.875rem;
  min-width: 64px;
  box-sizing: border-box;
  transition: background-color 250ms cubic-bezier(0.4, 0, 0.2, 1) 0ms,box-shadow 250ms cubic-bezier(0.4, 0, 0.2, 1) 0ms,border 250ms cubic-bezier(0.4, 0, 0.2, 1) 0ms;
  font-family: "Roboto", "Helvetica", "Arial", sans-serif;
  font-weight: 500;
  line-height: 1.75;
  border-radius: 4px;
  letter-spacing: 0.02857em;
  text-transform: uppercase;
}
.MuiButton-root:hover {
  text-decoration: none;
  background-color: rgba(0, 0, 0, 0.08);
}
.MuiButton-root.Mui-disabled {
  color: rgba(0, 0, 0, 0.26);
}
@media (hover: none) {
  .MuiButton-root:hover {
    background-color: transparent;
  }
}
.MuiButton-root:hover.Mui-disabled {
  background-color: transparent;
}
.MuiButton-label {
  width: 100%;
  display: inherit;
  align-items: inherit;
  justify-content: inherit;
}
.MuiButton-text {
  padding: 6px 8px;
}
.MuiButton-textPrimary {
  color: #3f51b5;
}
.MuiButton-textPrimary:hover {
  background-color: rgba(63, 81, 181, 0.08);
}
@media (hover: none) {
  .MuiButton-textPrimary:hover {
    background-color: transparent;
  }
}
.MuiButton-textSecondary {
  color: #f50057;
}
.MuiButton-textSecondary:hover {
  background-color: rgba(245, 0, 87, 0.08);
}
@media (hover: none) {
  .MuiButton-textSecondary:hover {
    background-color: transparent;
  }
}
.MuiButton-outlined {
  border: 1px solid rgba(0, 0, 0, 0.23);
  padding: 5px 16px;
}
.MuiButton-outlined.Mui-disabled {
  border: 1px solid rgba(0, 0, 0, 0.26);
}
.MuiButton-outlinedPrimary {
  color: #3f51b5;
  border: 1px solid rgba(63, 81, 181, 0.5);
}
.MuiButton-outlinedPrimary:hover {
  border: 1px solid #3f51b5;
  background-color: rgba(63, 81, 181, 0.08);
}
@media (hover: none) {
  .MuiButton-outlinedPrimary:hover {
    background-color: transparent;
  }
}
.MuiButton-outlinedSecondary {
  color: #f50057;
  border: 1px solid rgba(245, 0, 87, 0.5);
}
.MuiButton-outlinedSecondary:hover {
  border: 1px solid #f50057;
  background-color: rgba(245, 0, 87, 0.08);
}
.MuiButton-outlinedSecondary.Mui-disabled {
  border: 1px solid rgba(0, 0, 0, 0.26);
}
@media (hover: none) {
  .MuiButton-outlinedSecondary:hover {
    background-color: transparent;
  }
}
.MuiButton-contained {
  color: rgba(0, 0, 0, 0.87);
  box-shadow: 0px 1px 5px 0px rgba(0,0,0,0.2),0px 2px 2px 0px rgba(0,0,0,0.14),0px 3px 1px -2px rgba(0,0,0,0.12);
  background-color: #e0e0e0;
}
.MuiButton-contained.Mui-focusVisible {
  box-shadow: 0px 3px 5px -1px rgba(0,0,0,0.2),0px 6px 10px 0px rgba(0,0,0,0.14),0px 1px 18px 0px rgba(0,0,0,0.12);
}
.MuiButton-contained:active {
  box-shadow: 0px 5px 5px -3px rgba(0,0,0,0.2),0px 8px 10px 1px rgba(0,0,0,0.14),0px 3px 14px 2px rgba(0,0,0,0.12);
}
.MuiButton-contained.Mui-disabled {
  color: rgba(0, 0, 0, 0.26);
  box-shadow: none;
  background-color: rgba(0, 0, 0, 0.12);
}
.MuiButton-contained:hover {
  background-color: #d5d5d5;
}
@media (hover: none) {
  .MuiButton-contained:hover {
    background-color: #e0e0e0;
  }
}
.MuiButton-contained:hover.Mui-disabled {
  background-color: rgba(0, 0, 0, 0.12);
}
.MuiButton-containedPrimary {
  color: #fff;
  background-color: #3f51b5;
}
.MuiButton-containedPrimary:hover {
  background-color: #303f9f;
}
@media (hover: none) {
  .MuiButton-containedPrimary:hover {
    background-color: #3f51b5;
  }
}
.MuiButton-containedSecondary {
  color: #fff;
  background-color: #f50057;
}
.MuiButton-containedSecondary:hover {
  background-color: #c51162;
}
@media (hover: none) {
  .MuiButton-containedSecondary:hover {
    background-color: #f50057;
  }
}
.MuiButton-colorInherit {
  color: inherit;
  border-color: currentColor;
}
.MuiButton-sizeSmall {
  padding: 4px 8px;
  font-size: 0.8125rem;
}
.MuiButton-sizeLarge {
  padding: 8px 24px;
  font-size: 0.9375rem;
}
.MuiButton-fullWidth {
  width: 100%;
}
</style>