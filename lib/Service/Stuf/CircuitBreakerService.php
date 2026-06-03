<?php

/**
 * Pipelinq CircuitBreakerService.
 *
 * Per-endpoint failure isolation: after a threshold of consecutive failures the
 * circuit opens for a cooldown, short-circuiting further sends to that endpoint.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.6
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker keyed by StufEndpoint id, with state persisted in IAppConfig.
 *
 * State per endpoint: a consecutive-failure counter and an "opened-at" epoch.
 * On the FAILURE_THRESHOLD-th consecutive failure the circuit opens; while open
 * (within COOLDOWN_SECONDS) {@see self::checkEndpoint()} returns false. After the
 * cooldown elapses the breaker auto-resets on the next check.
 */
class CircuitBreakerService
{
    /**
     * Consecutive failures that trip the breaker.
     *
     * @var int
     */
    private const FAILURE_THRESHOLD = 4;

    /**
     * Cooldown in seconds while the circuit stays open.
     *
     * @var int
     */
    private const COOLDOWN_SECONDS = 300;

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app config (state store).
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return true when the endpoint may be used (circuit closed or cooled down).
     *
     * @param string $endpointId The endpoint id.
     *
     * @return bool True when sending is permitted.
     */
    public function checkEndpoint(string $endpointId): bool
    {
        return $this->isCircuitOpen(endpointId: $endpointId) === false;
    }//end checkEndpoint()

    /**
     * Return true when the circuit is currently open (and not yet cooled down).
     *
     * Auto-resets the breaker when the cooldown has elapsed.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return bool True when the circuit is open.
     */
    public function isCircuitOpen(string $endpointId): bool
    {
        $openedAt = $this->readInt(endpointId: $endpointId, suffix: 'opened_at');
        if ($openedAt === 0) {
            return false;
        }

        if ((time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            // Cooldown elapsed: auto-reset and allow traffic again.
            $this->resetEndpoint(endpointId: $endpointId);
            $this->logger->info('StUF circuit reset after cooldown', ['endpoint' => $endpointId]);
            return false;
        }

        return true;
    }//end isCircuitOpen()

    /**
     * Record a failure; open the circuit when the threshold is reached.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return bool True when this failure opened (or kept open) the circuit.
     */
    public function recordFailure(string $endpointId): bool
    {
        $failures = ($this->readInt(endpointId: $endpointId, suffix: 'failures') + 1);
        $this->writeInt(endpointId: $endpointId, suffix: 'failures', value: $failures);

        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->writeInt(endpointId: $endpointId, suffix: 'opened_at', value: time());
            $this->logger->warning(
                'StUF circuit opened',
                ['endpoint' => $endpointId, 'failures' => $failures]
            );
            return true;
        }

        return false;
    }//end recordFailure()

    /**
     * Reset the failure counter and clear the open state (on success).
     *
     * @param string $endpointId The endpoint id.
     *
     * @return void
     */
    public function resetEndpoint(string $endpointId): void
    {
        $this->writeInt(endpointId: $endpointId, suffix: 'failures', value: 0);
        $this->writeInt(endpointId: $endpointId, suffix: 'opened_at', value: 0);
    }//end resetEndpoint()

    /**
     * Current consecutive-failure count for an endpoint.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return int The failure count.
     */
    public function failureCount(string $endpointId): int
    {
        return $this->readInt(endpointId: $endpointId, suffix: 'failures');
    }//end failureCount()

    /**
     * Read a persisted integer state value.
     *
     * @param string $endpointId The endpoint id.
     * @param string $suffix     The state key suffix.
     *
     * @return int The stored value (0 when unset).
     */
    private function readInt(string $endpointId, string $suffix): int
    {
        return $this->appConfig->getValueInt(
            Application::APP_ID,
            $this->stateKey(endpointId: $endpointId, suffix: $suffix),
            0
        );
    }//end readInt()

    /**
     * Persist an integer state value.
     *
     * @param string $endpointId The endpoint id.
     * @param string $suffix     The state key suffix.
     * @param int    $value      The value to store.
     *
     * @return void
     */
    private function writeInt(string $endpointId, string $suffix, int $value): void
    {
        $this->appConfig->setValueInt(
            Application::APP_ID,
            $this->stateKey(endpointId: $endpointId, suffix: $suffix),
            $value
        );
    }//end writeInt()

    /**
     * Build the IAppConfig state key for an endpoint + suffix.
     *
     * @param string $endpointId The endpoint id.
     * @param string $suffix     The state key suffix.
     *
     * @return string The namespaced state key.
     */
    private function stateKey(string $endpointId, string $suffix): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $endpointId) ?? 'unknown';

        return 'stuf.circuit.'.$safeId.'.'.$suffix;
    }//end stateKey()
}//end class
