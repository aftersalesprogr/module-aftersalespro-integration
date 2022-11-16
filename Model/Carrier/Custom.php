<?php

namespace AfterSalesProGr\Shipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;

class Custom extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'AfterSalesProGrShipping';
    protected $_isFixed = true;
    protected $_rateResultFactory;
    protected $_rateMethodFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->isActive()) {
            return false;
        }

        $shipment_weight = $request->getPackageWeight();
        $zipcode = $request->getDestPostcode();
        $ratesResponse = $this->getRates($zipcode, $shipment_weight);

        $shipment_price = $request->getPackageValue();
        $result = $this->_rateResultFactory->create();

        $isFreeShipping = $this->getConfigData('freeShippingUpperLimit') > 0 && $shipment_price >= $this->getConfigData('freeShippingUpperLimit');

        if (
            $this->getConfigData('fallbackActive') &&
            ($ratesResponse['code'] !== 200 || !count($ratesResponse['body']['quotes']))
        ) {
            $methodPrice = 0;
            if (!$isFreeShipping) {
                $base_price = $this->getConfigData('fallbackBasePrice');
                $base_kg = $this->getConfigData('fallbackBasePriceKg');
                $kg_price = $this->getConfigData('fallbackPricePerKg');
                $methodPrice = $base_price;
                $methodPrice += $shipment_weight > $base_kg ? $kg_price * ($shipment_weight - $base_kg) : 0;
            }

            $result->append($this->_appendMethod([
                'method_code' => 'courier',
                'title' => $this->getConfigData('fallbackTitle'),
                'method' => $this->getConfigData('fallbackName'),
                'price' => $methodPrice,
            ]));

            return $result;
        }

        foreach ($ratesResponse['body']['quotes'] as $rateKey=>$rateData) {
            $result->append($this->_appendMethod([
                'method_code' => $rateKey,
                'title' => $rateData['carrierName'],
                'method' => '',
                'price' => $isFreeShipping ? 0 : $rateData['costInCents']/100,
            ]));
        }

        return $result;
    }

    private function _appendMethod($data)
    {
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->getCarrierCode());
        $method->setCarrierTitle($data['title']);

        $method->setMethod($data['method_code']);
        $method->setMethodTitle($data['method']);

        $method->setPrice($data['price']);
        $method->setCost($data['price']);

        return $method;
    }

    private function getRates($zipcode, $weightInGram)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', 'https://northapi.com/api/3.0/shipmentQuote', [
                'body' => '{"zipcode":"'.$zipcode.'","weightInGram":'.$weightInGram.',"isCod":false}',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->getConfigData('aftersalesprogrApiToken'),
                    'Cache-Control' => 'no-cache',
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json',
                ],
            ]);

            return [
                'code' => (int) $response->getStatusCode(),
                'body' => json_decode((string) $response->getBody()->getContents(), true),
            ];
        } catch (\Exception $e) {

            return [
                'code' => $e->getCode(),
                'body' => [
                    'quotes' => [],
                ],
            ];
        }
    }
}
