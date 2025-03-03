<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_3_0(AvardaPayments $module)
{
    $query = '
        ALTER TABLE `' . _DB_PREFIX_ . 'avarda_session`
        MODIFY COLUMN `purchase_token` VARCHAR(1000) NOT NULL;
    ';

    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}
