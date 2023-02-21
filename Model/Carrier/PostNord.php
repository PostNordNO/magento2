<?php

namespace PostNordNO\Magento2\Model\Carrier;

use Exception;
use GuzzleHttp\Client;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;

/**
 * Custom shipping model
 */
class PostNord extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'postnord';
    protected $_customer_id;
    protected $_client_id;
    protected $_client_secret;
    protected $_test_mode = false;
    protected $_test_client_id;
    protected $_test_client_secret;

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var array
     */
    protected $_checkoutRequestData;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    private $rateMethodFactory;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);

        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Custom Shipping Rates Collector
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (! $this->getConfigFlag('active')) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        $this->_customer_id = $this->getConfigData('customer_id');
        $this->_client_id = $this->getConfigData('client_id');
        $this->_client_secret = $this->getConfigData('client_secret');

        if ($this->getConfigFlag('test_mode')) {
            $this->_test_mode = true;
            $this->_test_client_id = $this->getConfigData('test_client_id');
            $this->_test_client_secret = $this->getConfigData('test_client_secret');

            if ($this->_test_client_id == '' || $this->_test_client_secret == '') {
                return false;
            }
        }

        if ($this->_customer_id == '' || $this->_client_id == '' || $this->_client_secret == '' ) {
            return false;
        }

        $recipient_street_address = $request->getDestStreet();
        $recipient_address_line = explode(PHP_EOL, $recipient_street_address);
        $recipient_address1 = !empty($recipient_address_line[0]) ? $recipient_address_line[0] : 'Gate 1';
        $recipient_address2 = !empty($recipient_address_line[1]) ? $recipient_address_line[1] : 'Gate 2';
        $recipient_zip = $request->getDestPostcode();
        $recipient_city = !empty($request->getDestCity()) ? $request->getDestCity() : 'Stedsnavn';
        $recipient_country_code = $request->getDestCountryId();
        $package_weight = $request->getPackageWeight();
        $sender_address1 = $this->getStoreStreetLine1();
        $sender_address2 = $this->getStoreStreetLine2();
        $sender_zip = $this->getStorePostCode();
        $sender_city = $this->getStoreCity();
        $sender_country_code = $this->getStoreCountryCode();

        $this->_checkoutRequestData = [
            'recipient_address1' => $recipient_address1,
            'recipient_address2' => $recipient_address2,
            'recipient_zip' => $recipient_zip,
            'recipient_city' => $recipient_city,
            'recipient_country_code' => $recipient_country_code,
            'package_weight' => $package_weight == 0 ? 1 : $package_weight,
            'sender_address1' => $sender_address1,
            'sender_address2' => $sender_address2,
            'sender_zip' => $sender_zip,
            'sender_city' => $sender_city,
            'sender_country_code' => $sender_country_code
        ];

        $allowedMethods = $this->getAllowedMethods();

        foreach ($allowedMethods as $key) {
            $method = $this->rateMethodFactory->create();

            $method->setCarrier($this->_code);
            $method->setCarrierTitle($key["title"]);

            $method->setMethod($key["code"]);
            $method->setMethodTitle($key["method"]);

            $shippingCost = (float)$key["cost"];

            $method->setPrice($shippingCost);
            $method->setCost($shippingCost);

            $result->append($method);
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        if ($this->_test_mode) {
            return $this->getProductsFromAPI($this->_customer_id, $this->_test_client_id, $this->_test_client_secret, $this->_checkoutRequestData);
        }

        return $this->getProductsFromAPI($this->_customer_id, $this->_client_id, $this->_client_secret, $this->_checkoutRequestData);
    }

    /**
     * @return string
     */
    public function getToken($client_id, $client_secret)
    {
        try {
            $response = (new Client())->request('POST', 'https://auth.postnord.no/auth/realms/3Scale-prod/protocol/openid-connect/token', [
                'form_params' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'grant_type' => 'client_credentials'
                ], [
                    'Content-Type' => 'application/json',
                ]
            ]);

            return json_decode($response->getBody())->access_token;
        } catch (Exception $e) {
            return '400';
        }
    }

    /**
     * @return array
    */
    public function getProductsFromAPI($customer_id, $client_id, $client_secret, $checkoutRequestData)
    {
        $products = [];

        $token = $this->getToken($client_id, $client_secret);

        if ($token === '400') {
            return $products;
        }

        $recipient_address1 = $checkoutRequestData['recipient_address1'];
        $recipient_address2 = $checkoutRequestData['recipient_address2'];
        $recipient_zip = $checkoutRequestData['recipient_zip'];
        $recipient_city = $checkoutRequestData['recipient_city'];
        $recipient_country_code = $checkoutRequestData['recipient_country_code'];
        $package_weight = $checkoutRequestData['package_weight'];
        $sender_address1 = $checkoutRequestData['sender_address1'];
        $sender_address2 = $checkoutRequestData['sender_address2'];
        $sender_zip = $checkoutRequestData['sender_zip'];
        $sender_city = $checkoutRequestData['sender_city'];
        $sender_country_code = $checkoutRequestData['sender_country_code'];

        $client = new Client();

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ];

        $requestJson = '{
            "name": null,
            "shipmentId": null,
            "originalShipmentId": null,
            "isReturnShipment": false,
            "returnCode": null,
            "cod": {
                "bankAccountNo": null,
                "customerOrderReference": null,
                "paid": {
                    "currency": "NOK",
                    "value": 0,
                    "vat": 0
                },
                "rest": {
                    "currency": "NOK",
                    "value": 0,
                    "vat": 0
                },
                "total": {
                    "currency": "NOK",
                    "value": 0,
                    "vat": 0
                }
            },
            "packages": [
                {
                    "heightInCmt":0,
                    "lengthInCmt": 0,
                    "grossWeightInKgm": '.$package_weight.',
                    "widthInCmt": 0,
                    "volumeInDmq": 0,
                    "loadingMetres": 0,
                    "articleNumbers": [],
                    "id": null
                }
            ],
            "parties": {
                "freightPayer": {
                    "customerId": "'.$customer_id.'",
                    "reference": null,
                    "contact": {
                        "id": null,
                        "eMailAddress": null,
                        "contactName": null,
                        "countryCallingCode": "0047",
                        "mobileNumber": null,
                        "phoneNo": null
                    },
                    "type": "BUSINESS",
                    "name": "Example Business",
                    "physicalAddress": {
                        "street": "'.$sender_address1.'",
                        "street2": "'.$sender_address2.'",
                        "postalCode": "'.$sender_zip.'",
                        "city": "'.$sender_city.'",
                        "countryCode": "'.$sender_country_code.'"
                    },
                    "information": null
                },
                "consignee": {
                    "customerId": null,
                    "reference": null,
                    "contact": {
                        "id": null,
                        "eMailAddress": null,
                        "contactName": null,
                        "countryCallingCode": "0047",
                        "mobileNumber": null,
                        "phoneNo": null
                    },
                    "type": "CONSUMER",
                    "name": "CoreTrek AS",
                    "physicalAddress": {
                        "street": "'.$recipient_address1.'",
                        "street2": "'.$recipient_address2.'",
                        "postalCode": "'.$recipient_zip.'",
                        "city": "'.$recipient_city.'",
                        "countryCode": "'.$recipient_country_code.'"
                    },
                    "information": null
                },
                "consignor": {
                    "customerId": "'.$customer_id.'",
                    "reference": null,
                    "contact": {
                        "id": null,
                        "eMailAddress": null,
                        "contactName": null,
                        "countryCallingCode": "0047",
                        "mobileNumber": null,
                        "phoneNo": null
                    },
                    "type": "BUSINESS",
                    "name": "Example Name",
                    "physicalAddress": {
                        "street": "'.$sender_address1.'",
                        "street2": "'.$sender_address2.'",
                        "postalCode": "'.$sender_zip.'",
                        "city": "'.$sender_city.'",
                        "countryCode": "'.$sender_country_code.'"
                    },
                    "information": null
                },
                "returnParty": {
                    "customerId": null,
                    "reference": null,
                    "contact": null,
                    "type": null,
                    "name": null,
                    "physicalAddress": null,
                    "information": null
                },
                "originalShipper": {
                    "customerId": null,
                    "reference": null,
                    "contact": {
                        "id": null,
                        "eMailAddress": null,
                        "contactName": null,
                        "countryCallingCode": null,
                        "mobileNumber": null,
                        "phoneNo": null
                    },
                    "type": "BUSINESS",
                    "name": null,
                    "physicalAddress": null,
                    "information": null
                },
                "pickupPoint": {
                    "customerId": null,
                    "reference": null,
                    "contact": {
                        "id": null,
                        "eMailAddress": null,
                        "contactName": null,
                        "countryCallingCode": null,
                        "mobileNumber": null,
                        "phoneNo": null
                    },
                    "type": "BUSINESS",
                    "name": null,
                    "physicalAddress": null,
                    "information": null
                }
            },
            "product": {
                "name": null,
                "code": null,
                "variants": [],
                "options": [],
                "costComponents": []
            }
        }';

        $url = $this->_test_mode
            ? 'https://atapi2.postnord.no/rest/transport-booking/v2/booking/products'
            : 'https://api2.postnord.no/rest/transport-booking/v2/booking/products';

        try {
            $response = $client->request('POST', $url, [
                'body' => $requestJson,
                'headers' => $headers
            ]);

            $responseData = json_decode($response->getBody());

            foreach ($responseData as $product) {
                $price = 0;

                foreach ($product->price as $key => $price) {
                    if ($key == 'netAmount') {
                        $price = $price->value;
                    }
                }

                if ($product->code == 19) { // PostNord MyPack Collect - add Pickup Point
                    foreach ($product->pickupPoints as $pickupPoint) {
                        $products[$product->code.'_'.$pickupPoint->customerId] = [
                            'title' => 'PostNord',
                            'cost' => $price,
                            'code' => $pickupPoint->customerId,
                            'method' => $product->name .': ' .$pickupPoint->name . ' ('. $pickupPoint->customerId . ')'
                        ];
                    }
                } else {
                    $products[$product->code] = [
                        'title' => 'PostNord',
                        'cost' => $price,
                        'code' => $product->code,
                        'method' => $product->name
                    ];
                }
            }
        } catch (Exception $e) {
            return $products;
        }

        return $products;
    }

    private function getStoreStreetLine1()
    {
        return $this->scopeConfig->getValue('general/store_information/street_line1', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    private function getStoreStreetLine2()
    {
        return $this->scopeConfig->getValue('general/store_information/street_line2', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    private function getStorePostCode()
    {
        return $this->scopeConfig->getValue('general/store_information/postcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    private function getStoreCity()
    {
        return $this->scopeConfig->getValue('general/store_information/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    private function getStoreCountryCode()
    {
        return $this->scopeConfig->getValue('general/store_information/country_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}
