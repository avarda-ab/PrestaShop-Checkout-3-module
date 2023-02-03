<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_2_0(AvardaPayments $module)
{
    if (!$module->registerHook('actionAdminControllerSetMedia')) {
        return false;
    }

    $query = '
        ALTER TABLE `' . _DB_PREFIX_ . 'avarda_session`
        ADD `global` TINYINT(1) NOT NULL
        AFTER `mode`;
    ';

    if (!Db::getInstance()->execute($query)) {
        return false;
    }
    
    $tabId = Tab::getIdFromClassName('AdminAvardaPaymentsBackend');
    if (!$tabId) {
        return false;
    }

    $tab = new Tab((int)$tabId);
    $tab->id_parent = -1;

    return $tab->save();
}
