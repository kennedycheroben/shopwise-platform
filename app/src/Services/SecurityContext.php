<?php

namespace App\Services;

use DomainException;
use InvalidArgumentException;

/**
 * Explicit System Execution Context
 *
 * Provides a strictly controlled, narrow-capability boundary for automated background
 * workers, cron jobs, and asynchronous queue dispatchers.
 *
 * Security Invariants:
 * 1. Strictly forbidden in Web/HTTP runtime (throws DomainException unconditionally).
 * 2. Unauthenticated requests can never enter or acquire system execution context.
 * 3. Ordinary CLI processes do not receive automatic privileges.
 * 4. Jobs must declare a canonical, pre-approved job identifier from a closed registry.
 * 5. Replaces unrestricted wildcards with job-specific, least-privilege capability sets.
 * 6. Nested contexts use privilege intersection (capabilities can only narrow, never expand).
 * 7. Context state is reliably restored in try/finally blocks even on unhandled exceptions.
 */
final class SecurityContext
{
    /** Canonical system job identifiers */
    public const JOB_ORDER_EXPIRATION = 'cron_order_expiration';
    public const JOB_EMAIL_DISPATCHER = 'cron_email_dispatcher';
    public const JOB_PURGE_CARTS      = 'cron_purge_abandoned_carts';
    public const JOB_TEST_RUNNER      = 'test_runner';

    /**
     * Canonical Job Capability Map (Least-Privilege Role Boundaries)
     * No system job possesses wildcard '*' or broad administrative capabilities.
     */
    private const JOB_CAPABILITIES = [
        self::JOB_ORDER_EXPIRATION => [
            'orders.expire',
            'orders.status_transition',
            'inventory.adjust',
            'payments.verify',
            'email.queue',
            'audit.log',
        ],
        self::JOB_EMAIL_DISPATCHER => [
            'email.dispatch',
            'email.queue',
            'audit.log',
        ],
        self::JOB_PURGE_CARTS => [
            'carts.purge',
            'rate_limits.purge',
            'audit.log',
        ],
        self::JOB_TEST_RUNNER => [
            'test.execute',
            'orders.expire',
            'orders.status_transition',
            'orders.manage',
            'inventory.adjust',
            'email.dispatch',
            'email.queue',
            'carts.purge',
            'audit.log',
        ],
    ];

    private static ?array $activeContext = null;

    /**
     * Execute a callable in an explicit, trusted system execution context.
     *
     * @template T
     * @param callable(): T $action
     * @param string $jobIdentifier One of SecurityContext::JOB_* constants
     * @param array $metadata Optional contextual details (e.g. ['batch_size' => 100])
     * @return T
     * @throws DomainException If invoked from web or outside permitted environment
     * @throws InvalidArgumentException If job identifier is unknown
     */
    public static function runAsSystem(callable $action, string $jobIdentifier, array $metadata = [])
    {
        // 1. Web/HTTP Guard: Absolute prohibition of system context in web requests
        if (php_sapi_name() !== 'cli') {
            throw new DomainException("Security Violation: System execution context is strictly forbidden in web/HTTP requests.");
        }

        // 2. Closed registry validation: Job identifier must be known and registered
        if (!array_key_exists($jobIdentifier, self::JOB_CAPABILITIES)) {
            throw new InvalidArgumentException("Unauthorized or unrecognized system job identifier '{$jobIdentifier}'.");
        }

        // 3. Testing job restriction: test_runner is only valid when testing mode is enabled
        if ($jobIdentifier === self::JOB_TEST_RUNNER) {
            $isTesting = (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE) ||
                         (getenv('SHOPWISE_CONFIG') === 'testing') ||
                         (($_SERVER['SHOPWISE_CONFIG'] ?? '') === 'testing');
            if (!$isTesting) {
                throw new DomainException("Security Violation: test_runner job is only permitted in an explicit testing environment.");
            }
        }

        $childCapabilities = self::JOB_CAPABILITIES[$jobIdentifier];

        // 4. Nested Context Privilege Restriction:
        // When nesting, capabilities are strictly intersected with the parent context.
        // A child context can NEVER broaden permissions beyond what the parent holds.
        if (self::$activeContext !== null && (self::$activeContext['active'] ?? false)) {
            $parentCapabilities = self::$activeContext['capabilities'] ?? [];
            $effectiveCapabilities = array_values(array_intersect($parentCapabilities, $childCapabilities));
            $effectiveJob = self::$activeContext['job'] . ' > ' . $jobIdentifier;
        } else {
            $effectiveCapabilities = $childCapabilities;
            $effectiveJob = $jobIdentifier;
        }

        $previousContext = self::$activeContext;
        self::$activeContext = [
            'active'       => true,
            'job'          => $effectiveJob,
            'job_id'       => $jobIdentifier,
            'capabilities' => $effectiveCapabilities,
            'metadata'     => $metadata,
            'started_at'   => microtime(true),
            'sapi'         => php_sapi_name(),
        ];

        try {
            return $action();
        } finally {
            self::$activeContext = $previousContext;
        }
    }

    /**
     * Check whether execution is currently within an active system context.
     */
    public static function isSystem(): bool
    {
        return self::$activeContext !== null && (self::$activeContext['active'] ?? false) === true;
    }

    /**
     * Check if the active system context has a specific capability.
     */
    public static function hasPermission(string $ability): bool
    {
        if (!self::isSystem()) {
            return false;
        }
        $capabilities = self::$activeContext['capabilities'] ?? [];
        return in_array($ability, $capabilities, true);
    }

    /**
     * Retrieve the canonical job identifier for the active system context, or null.
     */
    public static function getJobName(): ?string
    {
        return self::$activeContext['job'] ?? null;
    }

    /**
     * Retrieve the active job's declared capabilities.
     */
    public static function getActiveCapabilities(): array
    {
        return self::$activeContext['capabilities'] ?? [];
    }

    /**
     * Retrieve contextual metadata for the active system context.
     */
    public static function getSystemMetadata(): array
    {
        return self::$activeContext['metadata'] ?? [];
    }

    /**
     * Return synthetic actor array representing the system process for audit logging.
     */
    public static function getSystemActor(): array
    {
        return [
            'id'         => 0,
            'role'       => 'system',
            'first_name' => 'System',
            'last_name'  => self::getJobName() ?? 'Worker',
            'email'      => 'system@shopwise.internal',
        ];
    }
}
