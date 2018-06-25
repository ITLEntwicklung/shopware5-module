<?php
/**
 * Created by PhpStorm.
 * User: eiriarte-mendez
 * Date: 12.06.18
 * Time: 11:01
 */

class Shopware_Plugins_Frontend_RpayRatePay_Bootstrapping_Database_CreateOrderShippingTable
{
    protected $query = "CREATE TABLE IF NOT EXISTS `rpay_ratepay_order_shipping` (" .
        "`s_order_id` int(11) NOT NULL," .
        "`delivered` int NOT NULL DEFAULT 0, " .
        "`cancelled` int NOT NULL DEFAULT 0, " .
        "`returned` int NOT NULL DEFAULT 0, " .
        "PRIMARY KEY (`s_order_id`)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";

    /**
     * @param Enlight_Components_Db_Adapter_Pdo_Mysql $database
     * @throws Zend_Db_Adapter_Exception
     */
    public function __invoke($database)
    {
        $database->query($this->query);
    }
}