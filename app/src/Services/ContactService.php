<?php

namespace App\Services;

/**
 * Storefront Contact Service
 * Thin proxy / dedicated service for storefront contact interactions.
 */
class ContactService
{
    /**
     * Submit contact form inquiry
     */
    public static function submit(array $data, string $ip): array
    {
        return ContactMessageService::submit($data, $ip);
    }
}
