<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_0_1(AvardaPayments $module)
{
    return $module->createOrderState();
}
