<?php

require_once _PS_MODULE_DIR_ . 'avardapayments/classes/settings.php';

use PrestaShop\PrestaShop\Core\Foundation\Templating\RenderableProxy;

class OrderController extends OrderControllerCore
{

    public function initContent() {

        // if store is in catalog mode, return to homepage
        if (Configuration::isCatalogMode()) {
            Tools::redirect('index.php');
        }

        // if cart has no products or does not meet minimal purchase requirement, return to cart
        $presentedCart = $this->cart_presenter->present($this->context->cart);
        if (count($presentedCart['products']) <= 0 || $presentedCart['minimalPurchaseRequired']) {
            // if there is no product in current cart, redirect to cart page
            $cartLink = $this->context->link->getPageLink('cart');
            Tools::redirect($cartLink);
        }

        $product = $this->context->cart->checkQuantities(true);
        if (is_array($product)) {
            // if there is an issue with product quantities, redirect to cart page
            $cartLink = $this->context->link->getPageLink('cart', null, null, array('action' => 'show'));
            Tools::redirect($cartLink);
        }

        $settings = new \AvardaPayments\Settings();
        //Logger::addLog('OrderController.php - Setting useOnePage: ' . $settings->getUseOnePage(), 1, true, null, true, true);
        if(!$settings->getUseOnePage()) {
            // use default checkout process
            $this->initContentDefault();
        } else {
            // redirect into custom checkout
            $url = $this->context->link->getModuleLink(
                'avardapayments',
                'opcheckout',
                array(),
                Tools::usingSecureMode()
            );
            Tools::redirect($url);
        }

    }

    /**
    * Original OrderController.php initContent()
    * Some checks (isCatalogMode(), product count and quantities) have been moved into new initContent()
    */
    public function initContentDefault() {

        $this->restorePersistedData($this->checkoutProcess);
        $this->checkoutProcess->handleRequest(
            Tools::getAllValues()
        );

        $this->checkoutProcess
            ->setNextStepReachable()
            ->markCurrentStep()
            ->invalidateAllStepsAfterCurrent();

        $this->saveDataToPersist($this->checkoutProcess);

        if (!$this->checkoutProcess->hasErrors()) {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !$this->ajax) {
                return $this->redirectWithNotifications(
                    $this->checkoutProcess->getCheckoutSession()->getCheckoutURL()
                );
            }
        }

        $presentedCart = $this->cart_presenter->present($this->context->cart);
        $this->context->smarty->assign([
            'checkout_process' => new RenderableProxy($this->checkoutProcess),
            'cart' => $presentedCart,
        ]);

        $this->context->smarty->assign([
            'display_transaction_updated_info' => Tools::getIsset('updatedTransaction'),
        ]);

        parent::initContent();
        $this->setTemplate('checkout/checkout');
    }

}