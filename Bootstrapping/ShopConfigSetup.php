<?php

namespace RpayRatePay\Bootstrapping;

use RpayRatePay\Component\Service\ConfigLoader;
use RpayRatePay\Component\Service\RatepayConfigWriter;

class ShopConfigSetup extends Bootstrapper
{
    public static $AVAILABLE_COUNTRIES = [
        'DE',
        'AT',
        'CH',
        'NL',
        'BE'
    ];

    public function install()
    {
        // do nothing
    }

    /**
     * @return mixed|void
     * @throws \Exception
     */
    public function update()
    {
        $configLoader = new ConfigLoader(Shopware()->Db());
        $configWriter = new RatepayConfigWriter(Shopware()->Db());

        $configWriter->truncateConfigTables();

        $repo = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
        $shops = $repo->findBy(['active' => true]);

        /** @var \Shopware\Models\Shop\Shop $shop */
        foreach ($shops as $shop) {
            $this->updateRatepayConfig($configLoader, $configWriter, $shop->getId(), false);
            $this->updateRatepayConfig($configLoader, $configWriter, $shop->getId(), true);
        }
    }

    /**
     * @return mixed|void
     */
    public function uninstall()
    {
    }

    /**
     * @param ConfigLoader $configLoader
     * @param RatepayConfigWriter $configWriter
     * @param $shopId
     * @param $backend
     */
    private function updateRatepayConfig($configLoader, $configWriter, $shopId, $backend)
    {
        foreach (self::$AVAILABLE_COUNTRIES as $iso) {
            $profileId = $configLoader->getProfileId($iso, $shopId, false, $backend);
            $securityCode = $configLoader->getSecurityCode($iso, $shopId, $backend);

            if (empty($profileId)) {
                continue;
            }

            $configWriter->writeRatepayConfig($profileId, $securityCode, $shopId, $iso, $backend);

            if ($iso == 'DE') {
                $profileIdZeroPercent = $configLoader->getProfileId($iso, $shopId, true, $backend);
                $configWriter->writeRatepayConfig($profileIdZeroPercent, $securityCode, $shopId, $iso, $backend);
            }
        }
    }
}
