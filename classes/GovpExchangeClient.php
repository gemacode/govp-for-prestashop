<?php
// SPDX-License-Identifier: AFL-3.0
class GovpExchangeClient
{
    private $endpoint;
    private $token;

    public function __construct($endpoint, $token)
    {
        $this->endpoint = rtrim((string) $endpoint, '/'); $this->token = (string) $token;
        if (!$this->token || !filter_var($this->endpoint, FILTER_VALIDATE_URL) || strpos($this->endpoint, 'https://') !== 0) throw new RuntimeException('GOVP_EXCHANGE_NOT_CONFIGURED');
    }

    public function issue(array $payload, $idempotencyKey)
    {
        if (!function_exists('curl_init')) throw new RuntimeException('CURL_REQUIRED');
        $curl = curl_init($this->endpoint . '/connectors/issue');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Idempotency-Key: ' . $idempotencyKey, 'Content-Type: application/json', 'User-Agent: GOVP-for-PrestaShop/' . GovpExchange::VERSION], CURLOPT_POSTFIELDS => json_encode($payload)]);
        $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
        if ($body === false) throw new RuntimeException($error ?: 'GOVP_NETWORK_ERROR');
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['govp']['code'])) throw new RuntimeException(is_array($decoded) && !empty($decoded['error']) ? $decoded['error'] : 'GOVP_INVALID_RESPONSE');
        return $decoded;
    }
}
