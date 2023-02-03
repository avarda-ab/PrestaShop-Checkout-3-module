<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_0_2(AvardaPayments $module)
{
    return $module->registerHook('orderConfirmation');
}
