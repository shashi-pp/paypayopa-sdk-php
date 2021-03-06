<?php

namespace PayPay\OpenPaymentAPI\Controller;

use Exception;
use GuzzleHttp\Exception\RequestException;
use PayPay\OpenPaymentAPI\Client;

class ClientControllerException extends Exception
{
    private $resultInfo = false;
    private $apiInfo = false;
    public function __construct($apiInfo,$resultInfo, $code = 500, $documentationUrl = false)
    {
        $this->documentationUrl = $documentationUrl;
        $this->apiInfo = $apiInfo;
        if (gettype($resultInfo) === 'array') {
            parent::__construct($resultInfo['message'], $code);
            $this->resultInfo = $resultInfo;
        }
        if (gettype($resultInfo) === 'string') {
            // If string message error
            parent::__construct($resultInfo, $code);
        }
    }
    function getResolutionUrl()
    {
        if (!$this->documentationUrl || !$this->apiInfo) {
            return "https://github.com/paypay/paypayopa-sdk-php/issues/new/choose";
        }
        $resultInfo = $this->resultInfo;
        $documentationUrl = $this->documentationUrl;
        $code = $resultInfo["code"];
        $codeId = $resultInfo["codeId"];
        $apiName = $this->apiInfo["api_name"];
        return "${documentationUrl}?api_name=${apiName}&code=${code}&code_id=${codeId}";
    }
}
class Controller
{
    /**
     * API URL
     *
     * @var string
     */
    protected $api_url;
    /**
     *  Main instance ()
     *
     * @var Client
     */
    protected $MainInst;
    /**
     * Auth info
     *
     * @var array
     */
    protected $auth;
    /**
     * default post options
     *
     * @var array
     */
    protected $basePostOptions;
    /**
     * Initializes Code class to manage creation and deletion of data for QR Code generation
     *
     * @param Client $MainInstance Instance of invoking client class
     * @param Array $auth API credentials
     */
    public function __construct($MainInstance, $auth)
    {
        $this->MainInst = $MainInstance;
        $this->api_url = $this->MainInst->getConfig('API_URL');
        $this->auth = $auth;
        $this->basePostOptions = [
            'CURLOPT_TIMEOUT' => 15,
            'HEADERS' => [
                'Content-Type' => 'application/json'
            ]
        ];
    }
    /**
     * Get Hmac headers
     *
     * @param string $HttpMethod
     * @param string $PaypayEndpoint
     * @param string $ContentType
     * @param array $RequestData
     * @return array  
     */
    protected function HmacCallOpts($HttpMethod, $PaypayEndpoint, $ContentType = 'empty', $RequestData = null)
    {
        $AuthStr = PayPayEncryptHeader(
            $this->auth['API_KEY'],
            $this->auth['API_SECRET'],
            $HttpMethod,
            $PaypayEndpoint,
            $ContentType,
            $RequestData
        );

        $PostOpts = [
            'CURLOPT_TIMEOUT' => 15,
            'HEADERS' => [
                'Content-Type' => $ContentType,
                'Authorization' => $AuthStr
            ]
        ];
        return $PostOpts;
    }
    /**
     * Main client accessor
     *
     * @return Client
     */
    protected function main()
    {
        return $this->MainInst;
    }
    /**
     * Checks type of payload against empty payload object
     *
     * @param mixed $payload Request data payload object
     * @param mixed $type Empty payload object
     * @return void
     */
    protected function payloadTypeCheck($payload, $type)
    {
        if (get_class($payload) !== get_class($type)) {
            throw new ClientControllerException(false,"Payload not of type " . get_class($type), 500);
        }
    }
    /**
     * Generic HTTP calls
     *
     * @param string $apiId Id of API being called
     * @param string $url URL
     * @param array $data payload data array
     * @param array $options call options
     * @return array
     */
    protected function doCall($lookupApi,$apiId, $url, $data, $options)
    {
        if ($lookupApi) {
            $apiInfo = $this->main()->GetApiMapping($apiId);
            $callType = strtolower($apiInfo["method"]);
        } else {
            $apiInfo = false;
            $callType = $apiId;
        }
        $request = $this->main()->http();
        $mid = $this->main()->GetMid();
        if ($mid) {
            $options["HEADERS"]['X-ASSUME-MERCHANT'] = $mid;
        }
        $response = null;
        try {
            if ($callType === 'post') {
                $response = $request->$callType(
                    $url,
                    [
                        'headers' => $options["HEADERS"],
                        'json' => $data,
                        'timeout' => $options['TIMEOUT']
                    ]
                );
            }
            if ($callType === 'get' || $callType === 'delete') {
                $response = $request->$callType(
                    $url,
                    [
                        'headers' => $options["HEADERS"]
                    ]
                );
            }
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            }
        } finally {
            $responseData = json_decode($response->getBody(), true);
            $resultInfo = $responseData["resultInfo"];
            $this->parseResultInfo($apiInfo, $resultInfo, $response->getStatusCode());
            return $responseData;
        }
    }

    protected function parseResultInfo($apiInfo, $resultInfo, $statusCode)
    {
        if (strcmp($resultInfo['code'], "SUCCESS") !== 0 && strcmp($resultInfo['code'], "REQUEST_ACCEPTED")) {
            throw new ClientControllerException(
                $apiInfo,
                $resultInfo, //PayPay API message
                $statusCode, // API response code
                $this->main()->GetConfig('DOC_URL') // PayPay Resolve URL
            );
        }
    }
}
