<?php

namespace RpayRatePay\Bootstrapping\Events;

use RatePAY\Service\Math;
use RpayRatePay\Component\Mapper\PaymentRequestData;
use RpayRatePay\Component\Service\PaymentProcessor;
use RpayRatePay\Component\Service\Logger;
use Shopware\Models\Order\Order;
use RpayRatePay\Component\Service\ConfigLoader;

class BackendOrderControllerSubscriber implements \Enlight\Event\SubscriberInterface
{
    /**
     * @var string
     */
    private $path;

    /** @var ConfigLoader  */
    protected $_configLoader;

    /**
     * Shopware_Plugins_Frontend_RpayRatePay_Bootstrapping_Events_PaymentControllerSubscriber constructor.
     * @param ConfigLoader $configLoader
     * @param $path
     */
    public function __construct(
        ConfigLoader $configLoader,
        $path
    )
    {
        $this->_configLoader = $configLoader;
        $this->path = $path;
    }

    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Dispatcher_ControllerPath_Backend_RpayRatepayBackendOrder' => 'onOrderDetailBackendController',
            'Shopware_Controllers_Backend_SwagBackendOrder::createOrderAction::replace' => 'beforeCreateOrderAction',
        ];
    }

    /**
     * Loads the Backendextentions
     *
     * @return string
     */
    public function onOrderDetailBackendController()
    {
        Shopware()->Template()->addTemplateDir($this->path . 'Views/');

        return $this->path . 'Controller/backend/RpayRatepayBackendOrder.php';
    }

    public function beforeCreateOrderAction(\Enlight_Hook_HookArgs $hookArgs)
    {
        $request = $hookArgs->getSubject()->Request();
        $view = $hookArgs->getSubject()->View();

        try {
            /** @var OrderHydrator $orderHydrator */
            $orderHydrator = Shopware()->Container()->get('swag_backend_order.order.order_hydrator');

            $orderStruct = $orderHydrator->hydrateFromRequest($request);

            //first find out if it's a ratepay order
            $paymentType = Shopware()->Models()->find('Shopware\Models\Payment\Payment', $orderStruct->getPaymentId());
            $customer = Shopware()->Models()->find('Shopware\Models\Customer\Customer', $orderStruct->getCustomerId());
            $validation = new \Shopware_Plugins_Frontend_RpayRatePay_Component_Validation($customer, $paymentType);

            if (!$validation->isRatePAYPayment()) {
                $this->forwardToSWAGBackendOrders($hookArgs);
                return;
            }

            $swagValidations = $this->runSwagValidations($orderStruct);

            if ($swagValidations->getMessages()) {
                $this->fail($view, $swagValidations->getMessages());
                return;
            }

            $paymentRequestData = $this->orderStructToPaymentRequestData($orderStruct, $paymentType, $customer);
            $method = \RpayRatePay\Component\Service\ShopwareUtil::getPaymentMethod($paymentType->getName());

            $netItemPrices = \RpayRatePay\Component\Service\ShopwareUtil::customerCreatesNetOrders($customer);
            $paymentRequester = new \Shopware_Plugins_Frontend_RpayRatePay_Component_Mapper_ModelFactory(null, true, $netItemPrices);

            $answer = $paymentRequester->callPaymentRequest($paymentRequestData);

            if ($answer->isSuccessful()) {
                //let SWAG write order to db
                $this->forwardToSWAGBackendOrders($hookArgs);

                $orderId = $view->getAssign('orderId');

                $this->doPostProcessing($orderId, $answer, $paymentRequestData, $method);
            } else {
                $customerMessage = $answer->getCustomerMessage();
                $this->fail($view, [$customerMessage]);
            }
        } catch (\Exception $e) {
            Logger::singleton()->error($e->getMessage());
            Logger::singleton()->error($e->getTraceAsString());

            $this->fail($view, [$e->getMessage()]);
        }
    }

    private function orderStructToPaymentRequestData(
        \SwagBackendOrder\Components\Order\Struct\OrderStruct $orderStruct,
         \Shopware\Models\Payment\Payment $paymentType,
         \Shopware\Models\Customer\Customer $customer
    ) {
        $method = \RpayRatePay\Component\Service\ShopwareUtil::getPaymentMethod(
            $paymentType->getName()
        );

        $billing = Shopware()->Models()->find('Shopware\Models\Customer\Address', $orderStruct->getBillingAddressId());

        $shipping = Shopware()->Models()->find('Shopware\Models\Customer\Address', $orderStruct->getShippingAddressId());

        $items = [];
        foreach ($orderStruct->getPositions() as $positionStruct) {
            $items[] = $this->positionStructToArray($positionStruct);
        }

        $shippingTax = Math::taxFromPrices(
            $orderStruct->getShippingCostsNet(),
            $orderStruct->getShippingCosts()
        );

        //looks like vat is always a whole number, so I'll round
        $shippingTax = round($shippingTax);

        $shippingToSend = $orderStruct->getNetOrder() ? $orderStruct->getShippingCostsNet() : $orderStruct->getShippingCosts();

        $dfpToken = '';

        $shop = Shopware()->Models()->find('Shopware\Models\Shop\Shop', $orderStruct->getLanguageShopId());
        $localeLang = $shop->getLocale()->getLocale();
        $lang = substr($localeLang, 0, 2);

        $amount = $orderStruct->getTotal();

        return new PaymentRequestData($method, $customer, $billing, $shipping, $items, $shippingToSend, $shippingTax, $dfpToken, $lang, $amount);
    }

    private function positionStructToArray(\SwagBackendOrder\Components\Order\Struct\PositionStruct $item)
    {
        $a = [
            'articlename' => $item->getName(),
            'ordernumber' => $item->getNumber(), //should be article number, see BasketArrayBuilder
            'quantity' => $item->getQuantity(),
            'priceNumeric' => $item->getPrice(),
            'tax_rate' => $item->getTaxRate(),
        ];

        return $a;
    }

    private function forwardToSWAGBackendOrders(\Enlight_Hook_HookArgs $hookArgs)
    {
        $subject = $hookArgs->getSubject();
        $parentReturn = $subject->executeParent(
            $hookArgs->getMethod(),
            $hookArgs->getArgs()
        );
        $hookArgs->setReturn($parentReturn);
    }

    private function fail($view, $messages)
    {
        $view->assign([
            'success' => false,
            'violations' => $messages,
        ]);
    }

    private function runSwagValidations($orderStruct)
    {
        $validator = Shopware()->Container()->get('swag_backend_order.order.order_validator');
        $violations = $validator->validate($orderStruct);
        return $violations;
    }

    private function doPostProcessing($orderId, $answer, $paymentRequestData, $paymentMethod)
    {
        /** @var Order $order */
        $order = Shopware()->Models()->find('Shopware\Models\Order\Order', $orderId);

        $paymentProcessor = new PaymentProcessor(Shopware()->Db(), $this->_configLoader);

        //set the transaction id
        $paymentProcessor->setOrderTransactionId($order, $answer->getTransactionId());

        //init shipping
        if ($paymentRequestData->getShippingCost() > 0) {
            $paymentProcessor->initShipping($order);
        }

        $paymentProcessor->initDiscount($order);

        //set order attributes
        $paymentProcessor->setOrderAttributes(
            $order,
            $answer,
            $this->_configLoader->commitShippingAsCartItem(),
            $this->_configLoader->commitDiscountAsCartItem(),
            true
        );

        //insert ratepay positions
        $paymentProcessor->insertRatepayPositions($order);

        $paymentProcessor->setPaymentStatus($order);

        //insert positions
        if (\Shopware_Plugins_Frontend_RpayRatePay_Bootstrap::getPCConfig() == true) {
            $paymentProcessor->sendPaymentConfirm($answer->getTransactionId(), $order, true);
        }
    }
}
