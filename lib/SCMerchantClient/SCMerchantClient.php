<?php

declare(strict_types=1);

namespace SpectroCoin\SCMerchantClient;

use SpectroCoin\SCMerchantClient\Config;
use SpectroCoin\SCMerchantClient\Utils;
use SpectroCoin\SCMerchantClient\Exception\ApiError;
use SpectroCoin\SCMerchantClient\Exception\GenericError;
use SpectroCoin\SCMerchantClient\Http\CreateOrderRequest;
use SpectroCoin\SCMerchantClient\Http\CreateOrderResponse;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;

use InvalidArgumentException;
use Exception;
use RuntimeException;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/../../vendor/autoload.php';

class SCMerchantClient
{
    private string $project_id;
    private string $client_id;
    private string $client_secret;
    private string $encryption_key;
    protected Client $http_client;

    /**
     * Constructor
     * 
     * @param string $project_id
     * @param string $client_id
     * @param string $client_secret
     */
    public function __construct(string $project_id, string $client_id, string $client_secret)
    {
        $this->project_id = $project_id;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;

        $this->encryption_key = $this->InitializeEncryptionKey();
        $this->http_client = new Client();
    }

    /**
     * Create an order
     * 
     * @param array $order_data
     * @return CreateOrderResponse|ApiError|GenericError|null
     */
    public function createOrder(array $order_data)
    {
        $access_token_data = $this->getAccessTokenData();

        if (!$access_token_data || $access_token_data instanceof ApiError) {
            return $access_token_data;
        }

        try {
            $create_order_request = new CreateOrderRequest($order_data);
        } catch (InvalidArgumentException $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        }

        $order_payload = $create_order_request->toArray();
        $order_payload['projectId'] = $this->project_id;

        return $this->sendCreateOrderRequest(json_encode($order_payload));
    }

    /**
     * Send create order request
     * 
     * @param string $order_payload
     * @return CreateOrderResponse|ApiError|GenericError
     */
    private function sendCreateOrderRequest(string $order_payload)
    {
        try {
            $response = $this->http_client->request('POST', Config::MERCHANT_API_URL . '/merchants/orders/create', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->getAccessTokenData()['access_token'],
                    'Content-Type' => 'application/json'
                ],
                RequestOptions::BODY => $order_payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to parse JSON response: ' . json_last_error_msg());
            }

            $responseData = [
                'preOrderId' => $body['preOrderId'] ?? null,
                'orderId' => $body['orderId'] ?? null,
                'validUntil' => $body['validUntil'] ?? null,
                'payCurrencyCode' => $body['payCurrencyCode'] ?? null,
                'payNetworkCode' => $body['payNetworkCode'] ?? null,
                'receiveCurrencyCode' => $body['receiveCurrencyCode'] ?? null,
                'payAmount' => $body['payAmount'] ?? null,
                'receiveAmount' => $body['receiveAmount'] ?? null,
                'depositAddress' => $body['depositAddress'] ?? null,
                'memo' => $body['memo'] ?? null,
                'redirectUrl' => $body['redirectUrl'] ?? null
            ];

            return new CreateOrderResponse($responseData);
        } catch (InvalidArgumentException $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        } catch (RequestException $e) {
            return new ApiError($e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Retrieves the current access token data
     * 
     * @return array|null
     */
    public function getAccessTokenData()
    {
        $now    = time();
        $cipher = $this->retrieveEncryptedData();

        if ($cipher) {
            try {
                $json = Utils::decryptAuthData($cipher, $this->encryption_key);
                $data = json_decode($json, true);
                if ($this->isTokenValid($data, $now)) {
                    return $data;
                }
            } catch (\RuntimeException $e) {
                error_log(sprintf(
                    '[SpectroCoin] decryptAuthData failed, refreshing token: %s',
                    $e->getMessage()
                ));
            }
        }

        return $this->refreshAccessToken($now);
    }


    /**
     * Refreshes the access token
     * 
     * @param int $current_time
     * @return array|ApiError
     * @throws RequestException
     */
    public function refreshAccessToken(int $current_time)
    {
        try {
            $response = $this->http_client->post(Config::AUTH_URL, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                ],
            ]);

            $access_token_data = json_decode((string) $response->getBody(), true);
            if (!isset($access_token_data['access_token'], $access_token_data['expires_in'])) {
                return new ApiError('Invalid access token response');
            }

            $access_token_data['expires_at'] = $current_time + $access_token_data['expires_in'];
            $encrypted_access_token_data = Utils::EncryptAuthData(json_encode($access_token_data), $this->encryption_key);
            $this->storeEncryptedData($encrypted_access_token_data);
            return $access_token_data;
        } catch (RequestException $e) {
            return new ApiError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Checks if the current access token is valid
     * 
     * @param array $access_token_data
     * @param int $current_time
     * @return bool
     */
    private function isTokenValid(array $access_token_data, int $current_time): bool
    {
        return isset($access_token_data['expires_at']) && $current_time < $access_token_data['expires_at'];
    }

    /**
     * Initialize encryption key
     * 
     * This method retrieves the encryption key from the Joomla database. If the key does not exist,
     * it generates a new key, saves it to the database, and then returns it.
     * 
     * @return string The encryption key
     */
    private function InitializeEncryptionKey(): string
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);

        $query->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('spectrocoin'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

        $db->setQuery($query);
        $paramsJson = $db->loadResult();

        $params = new Registry;
        $params->loadString($paramsJson);

        $key = $params->get('spectrocoin_encryption_key', '');
        if (empty($key)) {
            $key = bin2hex(random_bytes(32));

            $params->set('spectrocoin_encryption_key', $key);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote((string) $params))
                ->where($db->quoteName('element') . ' = ' . $db->quote('spectrocoin'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));

            $db->setQuery($query);
            $db->execute();
        }

        return $key;
    }

    /**
     * Store encrypted access token data in the session
     * 
     * @param string $encrypted_access_token_data The encrypted access token data
     * @return void
     */
    private function storeEncryptedData($encrypted_access_token_data): void
    {
        $session = Factory::getSession();
        if (!$session->isActive()) {
            $session->start();
        }
        $session->set('spectrocoin_encrypted_access_token', $encrypted_access_token_data);
    }

    /**
     * Retrieve encrypted access token data from the session
     * 
     * @return string|null The encrypted access token data or null if not found
     */
    private function retrieveEncryptedData(): ?string
    {
        $session = Factory::getSession();
        if (!$session->isActive()) {
            $session->start();
        }
        return $session->get('spectrocoin_encrypted_access_token', null);
    }

    /**
     * Uses unique order's UUID and access token data to request GET /merchants/orders/{$id} and retrieve the data of the order in array format.
     * @param string $order_id
     * @param array $access_token_data
     * 
     * @return array|ApiError|GenericError The response array containing order details or an error object if an error occurs.
     */
    public function getOrderById(string $order_id)
    {
        try {
            $access_token_data = $this->getAccessTokenData();
            $response = $this->http_client->request(
                'GET',
                Config::MERCHANT_API_URL . '/merchants/orders/' . $order_id,
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer ' . $access_token_data['access_token'],
                        'Content-Type'  => 'application/json',
                    ],
                ]
            );

            $order = json_decode($response->getBody()->getContents(), true);

            return $order;
        } catch (InvalidArgumentException $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        } catch (RequestException $e) {
            return new ApiError($e->getMessage(), $e->getCode());
        } catch (Exception $e) {
            return new GenericError($e->getMessage(), $e->getCode());
        }
    }
}
