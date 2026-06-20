<?php

/**
 * Pipelinq CircuitBreakerService (StUF).
 *
 * Per-endpoint failure isolation. After `THRESHOLD` consecutive failures the
 * circuit opens for `COOLDOWN_SECONDS` seconds; while open, `checkEndpoint`
 * returns false (callers MUST short-circuit) and a needs-input event is
 * raised on transition. Successful calls reset the counter.
 *
 * State is persisted in IAppConfig under per-endpoint keys so the breaker
 * survives request boundaries (it is shared across workers).
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Per-endpoint circuit breaker.
 */
class CircuitBreakerService
{
    public const THRESHOLD = 4;

    public const COOLDOWN_SECONDS = 300;

    /**
     * Constructor.
     *
     * @param IAppConfig           $appConfig            The app config.
     * @param NeedsInputDispatcher $needsInputDispatcher The needs-input dispatcher.
     * @param LoggerInterface      $logger               The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private NeedsInputDispatcher $needsInputDispatcher,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return false when the circuit is open and the cooldown has not elapsed.
     *
     * @param array $endpoint The StufEndpoint as array.
     *
     * @return bool True when the endpoint may be called.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function checkEndpoint(array $endpoint): bool
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        if ($endpointId === '') {
            return true;
        }

        if ($this->isCircuitOpen(endpoint: $endpoint) === true) {
            return false;
        }

        return true;
    }//end checkEndpoint()

    /**
     * Record a failure for the endpoint. Opens the circuit when the threshold is reached.
     *
     * @param array               $endpoint The StufEndpoint as array.
     * @param array<string,mixed> $fout     The error payload (carried into needs-input).
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function recordFailure(array $endpoint, array $fout=[]): void
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        if ($endpointId === '') {
            return;
        }

        $count = ($this->getFailureCount(endpointId: $endpointId) + 1);
        $this->setFailureCount(endpointId: $endpointId, count: $count);

        if ($count >= self::THRESHOLD) {
            $this->openCircuit(endpointId: $endpointId);
            $this->logger->warning(
                message: 'StUF circuit OPEN for {ep} after {n} failures',
                context: ['ep' => $endpointId, 'n' => $count]
            );
            $this->needsInputDispatcher->dispatch(
                type: 'stuf_circuit_open',
                context: ['endpointId' => $endpointId, 'failureCount' => $count, 'fout' => $fout]
            );
        }
    }//end recordFailure()

    /**
     * Reset the endpoint's failure count (on a successful send / explicit reset).
     *
     * @param array $endpoint The StufEndpoint as array.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function resetEndpoint(array $endpoint): void
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        if ($endpointId === '') {
            return;
        }

        $this->setFailureCount(endpointId: $endpointId, count: 0);
        $this->appConfig->deleteKey(app: Application::APP_ID, key: $this->openKey(endpointId: $endpointId));
    }//end resetEndpoint()

    /**
     * Return true when the circuit is currently open AND cooldown has not elapsed.
     *
     * @param array $endpoint The StufEndpoint as array.
     *
     * @return bool
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function isCircuitOpen(array $endpoint): bool
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        if ($endpointId === '') {
            return false;
        }

        $openedAt = (int) $this->appConfig->getValueInt(
            app: Application::APP_ID,
            key: $this->openKey(endpointId: $endpointId),
            default: 0
        );

        if ($openedAt === 0) {
            return false;
        }

        if ((time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            $this->logger->info(
                message: 'StUF circuit RESET for {ep} (cooldown elapsed)',
                context: ['ep' => $endpointId]
            );
            $this->setFailureCount(endpointId: $endpointId, count: 0);
            $this->appConfig->deleteKey(app: Application::APP_ID, key: $this->openKey(endpointId: $endpointId));
            return false;
        }

        return true;
    }//end isCircuitOpen()

    /**
     * Snapshot the current breaker state for an endpoint (for admin health views).
     *
     * @param string $endpointId The endpoint id.
     *
     * @return array{state:string,failureCount:int,openedAt:int}
     */
    public function snapshot(string $endpointId): array
    {
        $failureCount = $this->getFailureCount(endpointId: $endpointId);
        $openedAt     = (int) $this->appConfig->getValueInt(
            app: Application::APP_ID,
            key: $this->openKey(endpointId: $endpointId),
            default: 0
        );

        if ($openedAt > 0 && (time() - $openedAt) < self::COOLDOWN_SECONDS) {
            $state = 'circuit_open';
        } else if ($failureCount > 0) {
            $state = 'degraded';
        } else {
            $state = 'ok';
        }

        return ['state' => $state, 'failureCount' => $failureCount, 'openedAt' => $openedAt];
    }//end snapshot()

    /**
     * Open the circuit by stamping the current unix timestamp.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return void
     */
    private function openCircuit(string $endpointId): void
    {
        $this->appConfig->setValueInt(
            app: Application::APP_ID,
            key: $this->openKey(endpointId: $endpointId),
            value: time()
        );
    }//end openCircuit()

    /**
     * Get the failure count for an endpoint.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return int
     */
    private function getFailureCount(string $endpointId): int
    {
        return (int) $this->appConfig->getValueInt(
            app: Application::APP_ID,
            key: $this->countKey(endpointId: $endpointId),
            default: 0
        );
    }//end getFailureCount()

    /**
     * Set the failure count for an endpoint.
     *
     * @param string $endpointId The endpoint id.
     * @param int    $count      The new count.
     *
     * @return void
     */
    private function setFailureCount(string $endpointId, int $count): void
    {
        $this->appConfig->setValueInt(
            app: Application::APP_ID,
            key: $this->countKey(endpointId: $endpointId),
            value: $count
        );
    }//end setFailureCount()

    /**
     * App-config key for the failure count.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return string
     */
    private function countKey(string $endpointId): string
    {
        return 'stuf.cb.count.'.$endpointId;
    }//end countKey()

    /**
     * App-config key for the open-since timestamp.
     *
     * @param string $endpointId The endpoint id.
     *
     * @return string
     */
    private function openKey(string $endpointId): string
    {
        return 'stuf.cb.open.'.$endpointId;
    }//end openKey()
}//end class
