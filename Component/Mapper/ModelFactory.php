<?php

    /**
     * This program is free software; you can redistribute it and/or modify it under the terms of
     * the GNU General Public License as published by the Free Software Foundation; either
     * version 3 of the License, or (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
     * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
     * See the GNU General Public License for more details.
     *
     * You should have received a copy of the GNU General Public License along with this program;
     * if not, see <http://www.gnu.org/licenses/>.
     *
     * Checkout
     *
     * @category   RatePAY
     * @copyright  Copyright (c) 2013 RatePAY GmbH (http://www.ratepay.com)
     */
    class Shopware_Plugins_Frontend_RpayRatePay_Component_Mapper_ModelFactory
    {
        private $_transactionId;

        private $_config;

        private $_countryCode;

        private $_sandboxMode;

        public function __construct($config = null)
        {
            $this->_config = $config;
        }

        /**
         * Returns country code by customer billing address
         *
         * @return string
         */
        private function _getCountryCodesByBillingAddress()
        {
            // Checkout address ids are set from shopware version >=5.2.0
            if (isset(Shopware()->Session()->checkoutBillingAddressId) && Shopware()->Session()->checkoutBillingAddressId > 0) {
                $addressModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Address');
                $checkoutAddressBilling = $addressModel->findOneBy(array('id' => Shopware()->Session()->checkoutBillingAddressId));
                return $checkoutAddressBilling->getCountry()->getIso();
            } else {
                $shopUser = Shopware()->Models()->find('Shopware\Models\Customer\Customer', Shopware()->Session()->sUserId);
                $country = Shopware()->Models()->find('Shopware\Models\Country\Country', $shopUser->getBilling()->getCountryId());
                return $country->getIso();
            }
        }

        /**
         * Gets the TransactionId for Requests
         *
         * @return string
         */
        public function getTransactionId()
        {
            return $this->_transactionId;
        }

        /**
         * Sets the TransactionId for Requests
         *
         * @param string $transactionId
         */
        public function setTransactionId($transactionId)
        {
            $this->_transactionId = $transactionId;
        }

        /**
         * Expects an instance of a paymentmodel and fill it with shopdata
         *
         * @param ObjectToBeFilled $modelName
         *
         * @return filledObjectGivenToTheFunction
         * @throws Exception The submitted Class is not supported!
         */
        public function getModel($modelName, $orderId = null)
        {
            switch ($modelName) {
                case is_a($modelName, 'Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentConfirm'):
                    $this->fillPaymentConfirm($modelName);
                    break;
                case is_a($modelName, 'Shopware_Plugins_Frontend_RpayRatePay_Component_Model_ConfirmationDelivery'):
                    $this->fillConfirmationDelivery($modelName, $orderId);
                    break;
                case is_a($modelName, 'Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentChange'):
                    $this->fillPaymentChange($modelName, $orderId);
                    break;
                default:
                    throw new Exception('The submitted Class is not supported!');
                    break;
            }
            return $modelName;
        }

        /**
         * make operation
         *
         * @param string $operationType
         * @param array $operationData
         *
         * @return bool|array
         */
        public function doOperation($operationType, array $operationData) {
            Shopware()->Pluginlogger()->error('doOperation ' . $operationType);
            switch ($operationType) {
                case 'ProfileRequest':
                    return $this->makeProfileRequest($operationData);
                    break;
                case 'PaymentInit':
                    return $this->makePaymentInit();
                    break;
                case 'PaymentRequest':
                    return $this->makePaymentRequest();
                    break;
            }
        }

        private function getHead() {
            $systemId = $this->getSystemId();
            $mbHead = new \RatePAY\ModelBuilder();
            $mbHead->setArray([
                'SystemId' => $systemId,
                'Credential' => [
                    'ProfileId' => $this->getProfileId(),
                    'Securitycode' => $this->getSecurityCode()
                ]
            ]);

            return $mbHead;
        }

        /**
         * make payment request
         *
         * @return mixed
         */
        private function makePaymentRequest()
        {
            $mbHead = $this->getHead();
            $mbHead->setTransactionId($this->_transactionId);
            $mbHead->setCustomerDevice(
                $mbHead->CustomerDevice()->setDeviceToken(Shopware()->Session()->RatePAY['dfpToken'])
            );

            $method = Shopware_Plugins_Frontend_RpayRatePay_Component_Service_Util::getPaymentMethod(
                Shopware()->Session()->sOrderVariables['sUserData']['additional']['payment']['name']
            );

            $shopUser = Shopware()->Models()->find('Shopware\Models\Customer\Customer', Shopware()->Session()->sUserId);

            // Checkout address ids are set in RP session from shopware version >=5.2.0
            if (isset(Shopware()->Session()->RatePAY['checkoutBillingAddressId']) && Shopware()->Session()->RatePAY['checkoutBillingAddressId'] > 0) {
                $addressModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Address');
                $checkoutAddressBilling = $addressModel->findOneBy(array('id' => Shopware()->Session()->RatePAY['checkoutBillingAddressId']));
                $checkoutAddressShipping = $addressModel->findOneBy(array('id' => Shopware()->Session()->RatePAY['checkoutShippingAddressId'] ? Shopware()->Session()->RatePAY['checkoutShippingAddressId'] : Shopware()->Session()->RatePAY['checkoutBillingAddressId']));

                $countryCodeBilling = $checkoutAddressBilling->getCountry()->getIso();
                $countryCodeShipping = $checkoutAddressShipping->getCountry()->getIso();

                $company = $checkoutAddressBilling->getCompany();
                if (empty($company)) {
                    $dateOfBirth = $shopUser->getBirthday()->format("Y-m-d"); // From Shopware 5.2 date of birth has moved to customer object
                }
                $merchantCustomerId = $shopUser->getNumber(); // From Shopware 5.2 billing number has moved to customer object
            } else {
                $checkoutAddressBilling = $shopUser->getBilling();
                $checkoutAddressShipping = $shopUser->getShipping() !== null ? $shopUser->getShipping() : $shopUser->getBilling();

                $countryBilling = Shopware()->Models()->find('Shopware\Models\Country\Country', $checkoutAddressBilling->getCountryId());
                $countryCodeBilling = $countryBilling->getIso();
                $countryShipping = Shopware()->Models()->find('Shopware\Models\Country\Country', $checkoutAddressShipping->getCountryId());
                $countryCodeShipping = $countryShipping->getIso();

                $company = $checkoutAddressBilling->getCompany();
                if (empty($company)) {
                    $dateOfBirth = $shopUser->getBilling()->getBirthday()->format("Y-m-d");
                }
                $merchantCustomerId = $shopUser->getBilling()->getNumber();
            }

            $mbHead->setArray([
                'External' => [
                    'MerchantConsumerId' => $merchantCustomerId,
                    'OrderId' => $this->_getOrderIdFromTransactionId()
                ]
            ]);

            $gender = 'u';
            if ($checkoutAddressBilling->getSalutation() === 'mr') {
                $gender = 'm';
                $salutation = 'Herr';
            } elseif ($checkoutAddressBilling->getSalutation() === 'ms') {
                $gender = 'f';
                $salutation = 'Frau';
            } else {
                $salutation = $checkoutAddressBilling->getSalutation();
            }
            Shopware()->Pluginlogger()->error('Method ' . $method);
            if ($method === 'INSTALLMENT') {
                $installmentDetails = $this->getPaymentDetails($method);
            }

            $shoppingBasket = array();
            $shopItems = Shopware()->Session()->sOrderVariables['sBasket']['content'];
            foreach ($shopItems AS $shopItem) {
                $item = array(
                    'Description' => $shopItem['articlename'],
                    'ArticleNumber' => $shopItem['ordernumber'],
                    'Quantity' => $shopItem['quantity'],
                    'UnitPriceGross' => $shopItem['priceNumeric'],
                    'TaxRate' => $shopItem['tax_rate'],
                    //'Discount' => 10
                );
                $shoppingBasket['Items'] = array(array('Item' => $item));
            }

            if (Shopware()->Session()->sOrderVariables['sBasket']['sShippingcosts'] > 0) {
                $shoppingBasket['Shipping'] = array(
                    'Description' => "Shipping costs",
                    'UnitPriceGross' => Shopware()->Session()->sOrderVariables['sBasket']['sShippingcosts'],
                    'TaxRate' => Shopware()->Session()->sOrderVariables['sBasket']['sShippingcostsTaxRate'],
                );
            }

            $mbContent = new RatePAY\ModelBuilder('Content');
            $contentArr = [
                'Customer' => [
                    'Gender' => $gender,
                    'Salutation' => $salutation,
                    'FirstName' => $checkoutAddressBilling->getFirstName(),
                    'LastName' => $checkoutAddressBilling->getLastName(),
                    'DateOfBirth' => $dateOfBirth,
                    'Nationality' => $this->_countryCode,
                    'IpAddress' => $this->_getCustomerIP(),
                    'Addresses' => [
                        [
                            'Address' => $this->_getCheckoutAddress($checkoutAddressBilling, 'BILLING', $countryCodeBilling)
                        ], [
                            'Address' => $this->_getCheckoutAddress($checkoutAddressShipping, 'DELIVERY', $countryCodeShipping)
                            ]

                    ],
                    'Contacts' => [
                        'Email' => $shopUser->getEmail(),
                        'Phone' => [
                            'DirectDial' => $checkoutAddressBilling->getPhone()
                        ],
                    ],
                ],
                'ShoppingBasket' => $shoppingBasket,
                'Payment' => [
                    'Method' => strtolower($method),
                    'Amount' => $this->getAmount(),
                ]
            ];

            if (!empty($company)) {
                $contentArr['Customer']['CompanyName'] = $checkoutAddressBilling->getCompany();
                $contentArr['Customer']['VatId'] = $checkoutAddressBilling->getVatId();
            }
            $elv = false;
            if (!empty($installmentDetails)) {
                if (Shopware()->Session()->RatePAY['ratenrechner']['payment_firstday'] == 2) {
                    $contentArr['Payment']['DebitPayType']= 'BANK-TRANSFER';
                } else {
                    $contentArr['Payment']['DebitPayType'] = 'DIRECT-DEBIT';
                    $elv = true;
                }
                $contentArr['Payment']['Amount'] = Shopware()->Session()->RatePAY['ratenrechner']['total_amount'];
                $contentArr['Payment']['InstallmentDetails'] = $installmentDetails;
            }

            if ($method === 'ELV' || ($method == 'INSTALLMENT' && $elv == true)) {
                $contentArr['Customer']['BankAccount']['Owner'] = Shopware()->Session()->RatePAY['bankdata']['bankholder'];

                $bankCode = Shopware()->Session()->RatePAY['bankdata']['bankcode'];
                if (!empty($bankCode)) {
                    $contentArr['Customer']['BankAccount']['BankAccountNumber'] = Shopware()->Session()->RatePAY['bankdata']['account'];
                    $contentArr['Customer']['BankAccount']['BankCode'] = Shopware()->Session()->RatePAY['bankdata']['bankcode'];
                } else {
                    $contentArr['Customer']['BankAccount']['Iban'] = Shopware()->Session()->RatePAY['bankdata']['account'];
                }
            }

            $mbContent->setArray($contentArr);

            $rb = new RatePAY\RequestBuilder(true); // Sandbox mode = true
            $paymentRequest = $rb->callPaymentRequest($mbHead, $mbContent);

            return $paymentRequest;
        }

        /**
         * get payment details
         *
         * @param $method
         * @return array
         */
        private function getPaymentDetails($method) {
            $paymentDetails = array();

                Shopware()->Pluginlogger()->error('INSTALLMENT');
                $paymentDetails['InstallmentNumber'] = Shopware()->Session()->RatePAY['ratenrechner']['number_of_rates'];
                $paymentDetails['InstallmentAmount'] = Shopware()->Session()->RatePAY['ratenrechner']['rate'];
                $paymentDetails['LastInstallmentAmount'] = Shopware()->Session()->RatePAY['ratenrechner']['last_rate'];
                $paymentDetails['InterestRate'] = Shopware()->Session()->RatePAY['ratenrechner']['interest_rate'];
                $paymentDetails['PaymentFirstday'] = Shopware()->Session()->RatePAY['ratenrechner']['payment_firstday'];


            return $paymentDetails;
        }

        /**
         * @param $operationData
         * @return bool|array
         */
        private function makeProfileRequest($operationData)
        {
            $systemId = $this->getSystemId();

            $mbHead = new \RatePAY\ModelBuilder();
            $mbHead->setArray([
                'SystemId' => $systemId,
                'Credential' => [
                    'ProfileId' => $operationData['profileId'],
                    'Securitycode' => $operationData['securityCode']
                ]
            ]);

            $rb = new \RatePAY\RequestBuilder(true); // Sandbox mode = true

            $profileRequest = $rb->callProfileRequest($mbHead);

            if ($profileRequest->isSuccessful()) {
                return $profileRequest->getResult();
            }
            return false;
        }

        /**
         * set the sandbox mode
         *
         * @param $value
         */
        public function setSandboxMode($value)
        {
            $this->_sandboxMode = $value;
        }

        /**
         * is sandbox mode
         *
         * @return bool
         */
        public function isSandboxMode()
        {
            if ($this->_sandboxMode == 1) {
                return true;
            }
            return false;
        }

        /**
         * get system id
         *
         * @return mixed
         */
        private function getSystemId()
        {
            $systemId = Shopware()->Shop()->getHost() ? : $_SERVER['SERVER_ADDR'];

            return $systemId;
        }

        /**
         * make payment init
         *
         * @param $operationData
         * @return bool
         */
        private function makePaymentInit()
        {
            $rb = new \RatePAY\RequestBuilder($this->isSandboxMode()); // Sandbox mode = true

            $profileRequest = $rb->callPaymentInit($this->getHead());

            if ($profileRequest->isSuccessful()) {
                return $profileRequest->getTransactionId();
            }
            return false;
        }

        /**
         * Fills an object of the class Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentConfirm
         *
         * @param Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentConfirm $paymentConfirmModel
         */
        private function fillPaymentConfirm(
            Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentConfirm &$paymentConfirmModel
        ) {
            $head = new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_SubModel_Head();
            $head->setOperation('PAYMENT_CONFIRM');
            $head->setProfileId($this->getProfileId());
            $head->setSecurityCode($this->getSecurityCode());
            $head->setSystemId(Shopware()->Shop()->getHost() ? : $_SERVER['SERVER_ADDR']);
            $head->setTransactionId($this->getTransactionId());
            $head->setSystemVersion($this->_getVersion());
            $head->setOrderId($this->_getOrderIdFromTransactionId());
            $paymentConfirmModel->setHead($head);
        }

        /**
         * Fills an object of the class Shopware_Plugins_Frontend_RpayRatePay_Component_Model_ConfirmationDelivery
         *
         * @param Shopware_Plugins_Frontend_RpayRatePay_Component_Model_ConfirmationDelivery $confirmationDeliveryModel
         */
        private function fillConfirmationDelivery(
            Shopware_Plugins_Frontend_RpayRatePay_Component_Model_ConfirmationDelivery &$confirmationDeliveryModel, $orderId
        ) {
            $order = Shopware()->Models()->find('Shopware\Models\Order\Order', $orderId);
            $countryCode = $order->getBilling()->getCountry()->getIso();

            $head = new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_SubModel_Head();
            $head->setOperation('CONFIRMATION_DELIVER');
            $head->setProfileId($this->getProfileId($countryCode));
            $head->setSecurityCode($this->getSecurityCode($countryCode));
            $head->setSystemId(
                Shopware()->Db()->fetchOne(
                    "SELECT `host` FROM `s_core_shops` WHERE `default`=1"
                ) ? : $_SERVER['SERVER_ADDR']
            );
            $head->setSystemVersion($this->_getVersion());
            $confirmationDeliveryModel->setHead($head);
        }

        /**
         * Fills an object of the class Shopware_Plugins_Frontend_RpayRatePay_Component_Model_ConfirmationDelivery
         *
         * @param Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentChange $paymentChangeModel
         */
        private function fillPaymentChange(
            Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentChange &$paymentChangeModel, $orderId
        ) {

            $order = Shopware()->Models()->find('Shopware\Models\Order\Order', $orderId);
            $countryCode = $order->getBilling()->getCountry()->getIso();

            $head = new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_SubModel_Head();
            $head->setOperation('PAYMENT_CHANGE');
            $head->setTransactionId($this->_transactionId);
            $head->setProfileId($this->getProfileId($countryCode));
            $head->setSecurityCode($this->getSecurityCode($countryCode));
            $head->setSystemId(
                Shopware()->Db()->fetchOne(
                    "SELECT `host` FROM `s_core_shops` WHERE `default`=1"
                ) ? : $_SERVER['SERVER_ADDR']
            );
            $head->setSystemVersion($this->_getVersion());

            $order = Shopware()->Db()->fetchRow(
                "SELECT `name`,`currency` FROM `s_order` "
                . "INNER JOIN `s_core_paymentmeans` ON `s_core_paymentmeans`.`id` = `s_order`.`paymentID` "
                . "WHERE `s_order`.`transactionID`=?;",
                array($this->_transactionId)
            );

            $paymentChangeModel->setHead($head);
        }

        /**
         * Return the full amount to pay.
         *
         * @return float
         */
        public function getAmount()
        {
            $user = Shopware()->Session()->sOrderVariables['sUserData'];
            $basket = Shopware()->Session()->sOrderVariables['sBasket'];
            if (!empty($user['additional']['charge_vat'])) {
                return empty($basket['AmountWithTaxNumeric']) ? $basket['AmountNumeric'] : $basket['AmountWithTaxNumeric'];
            } else {
                return $basket['AmountNetNumeric'];
            }
        }

        /**
         * Returns the Shippingcosts as Item
         *
         * @param string $amount
         * @param string $tax
         *
         * @return \Shopware_Plugins_Frontend_RpayRatePay_Component_Model_SubModel_item
         */
        private function getShippingAsItem($amount, $tax)
        {
            $item = new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_SubModel_item();
            $item->setArticleName('shipping');
            $item->setArticleNumber('shipping');
            $item->setQuantity(1);
            $item->setTaxRate($tax);
            $item->setUnitPriceGross($amount);

            return $item;
        }

        /**
         * Returns the OrderID for the TransactionId set to this Factory
         *
         * @return string $returnValue
         */
        private function _getOrderIdFromTransactionId()
        {
            $returnValue = null;
            if (!empty($this->_transactionId)) {
                $returnValue = Shopware()->Db()->fetchOne(
                    "SELECT `ordernumber` FROM `s_order` "
                    . "INNER JOIN `s_core_paymentmeans` ON `s_core_paymentmeans`.`id` = `s_order`.`paymentID` "
                    . "WHERE `s_order`.`transactionID`=?;",
                    array($this->_transactionId)
                );
            }

            return $returnValue;
        }

        /**
         * Returns the Version for this Payment-Plugin
         *
         * @return string
         */
        private function _getVersion()
        {
            $boostrap = new Shopware_Plugins_Frontend_RpayRatePay_Bootstrap();

            return Shopware()->Config()->get('version') . '_' . $boostrap->getVersion();
        }

        /**
         * Returns the IP Address for the current customer
         *
         * @return string
         */
        private function _getCustomerIP()
        {
            $customerIp = null;
            if (!is_null(Shopware()->Front())) {
                $customerIp = Shopware()->Front()->Request()->getClientIp();
            } else {
                $customerIp = Shopware()->Db()->fetchOne(
                    "SELECT `remote_addr` FROM `s_order` WHERE `transactionID`=" . $this->_transactionId
                );
            }

            return $customerIp;
        }

        /**
         * Transfer checkout address to address model
         *
         * @param $checkoutAddress
         * @param $type
         * @param $countryCode
         * @return array
         */
        function _getCheckoutAddress($checkoutAddress, $type, $countryCode) {
             $address = array(
                'Type' => strtolower($type),
                'Street' => $checkoutAddress->getStreet(),
                'ZipCode' => $checkoutAddress->getZipCode(),
                'City' => $checkoutAddress->getCity(),
                'CountryCode' => $countryCode,
            );

             if ($type == 'DELIVERY') {
                 $address['FirstName'] = $checkoutAddress->getFirstName();
                 $address['LastName'] = $checkoutAddress->getLastName();
             }

             if (!empty($checkoutAddress->getCompany())) {
                 $address['Company'] = $checkoutAddress->getCompany();
             }
            return $address;
        }

        public function getProfileId($countryCode = false)
        {
            if (!$countryCode) {
                $countryCode = $this->_getCountryCodesByBillingAddress();
            }

            if(null !== $this->_config) {
                $profileId = $this->_config->get('RatePayProfileID' . $countryCode);
            } else{
                $profileId = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config()->get('RatePayProfileID' . $countryCode);
            }

            return $profileId;
        }

        public function getSecurityCode($countryCode = false)
        {
            if (!$countryCode) {
                $countryCode = $this->_getCountryCodesByBillingAddress();
            }

            if(null !== $this->_config) {
                $securityCode = $this->_config->get('RatePaySecurityCode' . $countryCode);
            } else {
                $securityCode = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config()->get('RatePaySecurityCode' . $countryCode);
            }

            return $securityCode;
        }

    }
