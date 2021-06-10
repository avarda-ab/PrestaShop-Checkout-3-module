/**
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
 */
CREATE TABLE IF NOT EXISTS `PREFIX_avarda_session` (
  `id_session`       INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_customer`      INT(11) UNSIGNED NOT NULL,
  `id_cart`          INT(11) UNSIGNED NOT NULL,
  `id_order`         INT(11) UNSIGNED NULL,
  `purchase_id`      VARCHAR(64) NOT NULL,
  `purchase_token`   VARCHAR(300) NOT NULL,
  `purchase_expire_timestamp` DATETIME,
  `cart_signature`   VARCHAR(64) NOT NULL,
  `status`           VARCHAR(20) NOT NULL,
  `mode`             VARCHAR (10) NOT NULL,
  `info`             TEXT,
  `error_message`    TEXT,
  `date_add`         DATETIME NOT NULL,
  `date_upd`         DATETIME NOT NULL,
  `create_customer`  TINYINT (1),
  `newsletter`       TINYINT (1),
  `passwd`           VARCHAR(255),
  `id_carrier`       INT(11) UNSIGNED,
  `order_message`    TEXT,
  `is_recycled`      TINYINT (1),
  `is_gift`          TINYINT (1),
  `gift_message`     TEXT,
  PRIMARY KEY (`id_session`),
  KEY `cart` (`id_cart`, `cart_signature`, `date_add`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_avarda_transaction` (
  `id_transaction`   INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_order`         INT(11) UNSIGNED NOT NULL,
  `type`             VARCHAR(20) NOT NULL,
  `amount`           DECIMAL(20,6) NOT NULL,
  `success`          TINYINT(1) NOT NULL,
  `error_message`    TEXT,
  `date_add`         DATETIME NOT NULL,
  PRIMARY KEY (`id_transaction`),
  KEY `order` (`id_order`, `date_add`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE;
