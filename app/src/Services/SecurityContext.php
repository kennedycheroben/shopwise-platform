<?php

namespace App\Services;

use App\Database\Database;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit System Execution Context
 *
 * Provides a strictly controlled, narrow-capability boundary for automated background
 * workers, cron jobs, and asynchronous queue dispatchers.
 *
 * Security Architecture & Invariants:
 * 1. Strictly forbidden in Web/HTTP runtime (throws DomainException unconditionally).
 * 2. Unauthenticated requests can never enter or acquire system execution context.
 * 3. Ordinary CLI processes do not receive ambient or automatic privileges.
 * 4. Production jobs must declare a canonical identifier from a closed registry.
 * 5. Production jobs are constrained strictly to least-privilege capability sets.
 * 6. Nested contexts use privilege intersection (capabilities can only narrow, never expand).
 * 7. Context state is reliably restored in try/finally blocks even on unhandled exceptions.
 * 8. Testing contexts are strictly segregated and require verified connection to a test database.
 *
 * OPERATING SYSTEM SECURITY BOUNDARY NOTE:
 * In-process controls (such as debug_backtrace caller inspection or closed job registries)
 * provide vital application-level defense-in-depth against casual or accidental invocation
 * by unrelated application scripts. However, within PHP's shared-memory execution model,
 * code running in the same process can theoretically manipulate memory.
 * Genuine, unbreakable security boundaries for background workers MUST be enforced at the
 * Operating System level:
 * - Background workers and cron jobs should execute under a dedicated, low-privilege OS system
 *   user (e.g., 'shopwise_worker') that is strictly isolated from the web server user ('daemon'/'www-data').
 * - Database credentials used by background workers should follow least privilege.
 */
final class SecurityContext
{
    /** Canonical system job identifiers */
    public const JOB_ORDER_EXPIRATION = 'cron_order_expiration';
    public const JOB_EMAIL_DISPATCHER = 'cron_email_dispatcher';
    public const JOB_PURGE_CARTS      = 'cron_purge_abandoned_carts';
    public const JOB_TEST_RUNNER      = 'test_runner';

    /**
     * Canonical Job Capability Map for Production Background Workers
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
    ];

    /**
     * Sanctioned entry-point script basenames for production system jobs.
     * Application defense-in-depth check preventing casual invocation from unrelated scripts.
     */
    private const SANCTIONED_CALLERS = [
        self::JOB_ORDER_EXPIRATION => 'expire_orders.php',
        self::JOB_EMAIL_DISPATCHER => 'send_queued_emails.php',
        self::JOB_PURGE_CARTS      => 'purge_carts.php',
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

        // 2. Handle test_runner segregation: delegate to verified test context
        if ($jobIdentifier === self::JOB_TEST_RUNNER) {
            return self::runInTestContext($action, [
                'test.execute',
                'orders.expire',
                'orders.status_transition',
                'orders.manage',
                'orders.mark_as_paid',
                'orders.refund',
                'inventory.adjust',
                'email.dispatch',
                'email.queue',
                'carts.purge',
                'audit.log',
            ], self::JOB_TEST_RUNNER, $metadata);
        }

        // 3. Closed registry validation: Job identifier must be known and registered
        if (!array_key_exists($jobIdentifier, self::JOB_CAPABILITIES)) {
            throw new InvalidArgumentException("Unauthorized or unrecognized system job identifier '{$jobIdentifier}'.");
        }

        // 4. Controlled Caller Verification (Application Defense-in-Depth)
        // In production mode, verify caller script matches the sanctioned cron worker.
        // In a verified test environment, tests may execute system jobs directly against shopwise_test.
        if (!self::isVerifiedTestEnvironment()) {
            self::assertSanctionedCaller($jobIdentifier);
        }

        $childCapabilities = self::JOB_CAPABILITIES[$jobIdentifier];

        // 5. Nested Context Privilege Restriction:
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
     * Execute a callable in an isolated test execution context.
     *
     * SECURITY INVARIANT:
     * This method strictly requires CLI runtime AND cryptographically/physically verifies
     * that the active database connection is connected to 'shopwise_test' (or ends in '_test').
     * It is physically impossible to acquire test execution context against a production database.
     *
     * @template T
     * @param callable(): T $action
     * @param array $capabilities Specific capabilities granted for this test run
     * @param string $jobName Test identifier name
     * @param array $metadata Optional metadata
     * @return T
     * @throws DomainException If invoked outside CLI or against non-test database
     */
    public static function runInTestContext(
        callable $action,
        array $capabilities = ['test.execute'],
        string $jobName = 'test_runner',
        array $metadata = []
    ) {
        if (php_sapi_name() !== 'cli') {
            throw new DomainException("Security Violation: Test execution context is strictly forbidden in web/HTTP requests.");
        }

        // Verify that the active database connection is strictly an isolated test database
        if (!self::isVerifiedTestEnvironment()) {
            throw new DomainException(
                "Security Violation: Test execution context is rejected because the active database connection is not a verified test database (e.g. 'shopwise_test')."
            );
        }

        if (self::$activeContext !== null && (self::$activeContext['active'] ?? false)) {
            $parentCapabilities = self::$activeContext['capabilities'] ?? [];
            $effectiveCapabilities = array_values(array_intersect($parentCapabilities, $capabilities));
            $effectiveJob = self::$activeContext['job'] . ' > ' . $jobName;
        } else {
            $effectiveCapabilities = $capabilities;
            $effectiveJob = $jobName;
        }

        $previousContext = self::$activeContext;
        self::$activeContext = [
            'active'       => true,
            'job'          => $effectiveJob,
            'job_id'       => $jobName,
            'capabilities' => $effectiveCapabilities,
            'metadata'     => $metadata,
            'started_at'   => microtime(true),
            'sapi'         => php_sapi_name(),
            'is_test'      => true,
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

    /**
     * Validate that the caller matches the sanctioned entry point script for the job.
     */
    private static function assertSanctionedCaller(string $jobIdentifier): void
    {
        $expectedCaller = self::SANCTIONED_CALLERS[$jobIdentifier] ?? null;
        if ($expectedCaller === null) {
            throw new DomainException("Security Violation: No sanctioned caller script registered for job '{$jobIdentifier}'.");
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $callerFound = false;

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && basename($file) === $expectedCaller) {
                $callerFound = true;
                break;
            }
        }

        if (!$callerFound) {
            $callingFile = basename($trace[0]['file'] ?? 'unknown');
            throw new DomainException(
                "Security Violation: Unauthorized caller script '{$callingFile}' attempted to assume system job '{$jobIdentifier}'. Must be invoked from '{$expectedCaller}'."
            );
        }
    }

    /**
     * Verify whether the current execution runtime is connected to a genuine test database.
     */
    private static function isVerifiedTestEnvironment(): bool
    {
        // Must be in CLI
        if (php_sapi_name() !== 'cli') {
            return false;
        }

        // Must declare test intent via config or constant
        $isConfigTesting = (defined('SHOPWISE_TESTING_MODE') && SHOPWISE_TESTING_MODE) ||
                           (getenv('SHOPWISE_CONFIG') === 'testing') ||
                           (($_SERVER['SHOPWISE_CONFIG'] ?? '') === 'testing');

        if (!$isConfigTesting) {
            return false;
        }

        // Strictly verify active connected database name from the database server itself
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT DATABASE()");
            $dbName = (string)$stmt->fetchColumn();
            return ($dbName === 'shopwise_test' || str_ends_with($dbName, '_test'));
        } catch (Throwable $e) {
            return false;
        }
    }
}

