<?php

namespace App\Services;

interface HttpClientInterface
{
    /**
     * Send HTTP GET request
     *
     * @param string $url
     * @param array $headers Array of header strings or key-value pairs
     * @return array ['status' => int, 'body' => string, 'headers' => array]
     */
    public function get(string $url, array $headers = []): array;

    /**
     * Send HTTP POST request
     *
     * @param string $url
     * @param array|string $data Form fields or raw body
     * @param array $headers Array of header strings or key-value pairs
     * @return array ['status' => int, 'body' => string, 'headers' => array]
     */
    public function post(string $url, array|string $data = [], array $headers = []): array;
}
