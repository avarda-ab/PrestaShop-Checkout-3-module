<?php

require_once 'checkout.php';

class AvardaPaymentsOpcheckoutModuleFrontController extends AvardaPaymentsCheckoutModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $settings = $this->module->getSettings();
        $cart = $this->context->cart;
        $addressId = $this->retrieveAddressId($cart);
        $carrierAddress = new Address($addressId);
        //Checking if we have a shipping option already set before the form
        //$selectedShipping = $cart->getDeliveryOption(null, false, false);
        $selectedShippingOptionID = '';
        $shippingOptions = [];
        if (static::validCart($cart)) {
            $shippingOptions = $this->getShippingOptions($cart, $carrierAddress);
            $this->setTemplate('module:avardapayments/views/templates/front/opcheckout.tpl');
        } else {
            $this->setError($this->module->l('Empty cart'));
        }
        //Get gift options here from admin
        $gift = (int) Configuration::get('PS_GIFT_WRAPPING');
        $wrappingFeesTaxInc = 0;
        $giftMessage = '';
        $giftLabel = '';
        //Default cost for wrapping is free
        if ($gift) {
            $wrappingFeesTaxInc = $cart->getGiftWrappingPrice(true, $carrierAddress);
            if ($wrappingFeesTaxInc <= 0) {
                //Default cost for wrapping is free
                $wrappingFeesTaxInc = $this->getTranslator()->trans('(Ilmainen)', [], 'Shop.Theme.Checkout');
            }
            if ($cart->getGiftWrappingPrice(true, $carrierAddress) > 0) {
                $wrappingFeesTaxInc = $cart->getGiftWrappingPrice(true, $carrierAddress);
            }
            $giftLabel = $this->getTranslator()->trans(
                'I would like my order to be gift wrapped %cost%',
                ['%cost%' => $wrappingFeesTaxInc],
                'Shop.Theme.Checkout');
            $giftMessage = $cart->gift_message;
            $isGift = $cart->gift;

            $giftArray = [
                'message' => $giftMessage,
                'isGift' => $isGift,
                'wrappingFeesTaxInc' => $wrappingFeesTaxInc,
                'enabled' => $gift,
                'label' => $giftLabel,
            ];
        } else {
            $giftArray = [
                'enabled' => false,
            ];
        }

        $recyclable = (int) Configuration::get('PS_RECYCLABLE_PACK');
        $carrierTpl = 'module:avardapayments/views/templates/front/carriers.tpl';

        $this->context->smarty->assign([
            'showCart' => $settings->showCart(),
            'shippingOptions' => $shippingOptions,
            'carrierTpl' => $carrierTpl,
            'avardaCheckoutUrl' => $this->context->link->getModuleLink($this->module->name, 'opcheckout'),
            'addressId' => $addressId,
            'recyclable' => $recyclable,
            'giftArray' => $giftArray,
        ]);
    }

    protected function retrieveAddressId($cart)
    {
        $addressId = -1;

        if ($cart->id_address_delivery) {
            $addressId = $cart->id_address_delivery;
        }

        return $addressId;
    }
}
