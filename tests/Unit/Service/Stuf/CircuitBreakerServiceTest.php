<?php

/**
 * Unit tests for CircuitBreakerService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
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

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\CircuitBreakerService;
use OCA\Pipelinq\Service\Stuf\NeedsInputDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CircuitBreakerService.
 *
 * IAppConfig is mocked with an in-memory backing store so the breaker state
 * machine is exercised end-to-end without touching real config.
 */
class CircuitBreakerServiceTest extends TestCase
{
    private CircuitBreakerService $service;

    /**
     * @var IAppConfig&MockObject
     */
    private IAppConfig $config;

    /**
     * In-memory store backing the IAppConfig mock.
     *
     * @var array<string,int|string>
     */
    private array $store = [];

    /**
     * @var NeedsInputDispatcher&MockObject
     */
    private NeedsInputDispatcher $dispatcher;

    /**
     * Dispatcher captures.
     *
     * @var array
     */
    private array $dispatched = [];

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store      = [];
        $this->config     = $this->createMock(IAppConfig::class);
        $this->dispatcher = $this->createMock(NeedsInputDispatcher::class);
        $logger           = $this->createMock(LoggerInterface::class);

        $this->config->method('getValueInt')->willReturnCallback(
            function (string $app, string $key, int $default = 0) {
                return (int) ($this->store[$key] ?? $default);
            }
        );
        $this->config->method('setValueInt')->willReturnCallback(
            function (string $app, string $key, int $value) {
                $this->store[$key] = $value;
                return true;
            }
        );
        $this->config->method('deleteKey')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->store[$key]);
            }
        );

        $captures = &$this->dispatched;
        $this->dispatcher->method('dispatch')->willReturnCallback(
            function (string $type, array $context = []) use (&$captures) {
                $captures[] = ['type' => $type, 'context' => $context];
            }
        );

        $this->service = new CircuitBreakerService($this->config, $this->dispatcher, $logger);
    }//end setUp()

    /**
     * @return void
     */
    public function testRecordFailureIncrementsCount(): void
    {
        $endpoint = ['id' => 'ep-1'];
        $this->service->recordFailure($endpoint);
        $this->assertTrue($this->service->checkEndpoint($endpoint));
        $snap = $this->service->snapshot('ep-1');
        $this->assertSame(1, $snap['failureCount']);
        $this->assertSame('degraded', $snap['state']);
    }//end testRecordFailureIncrementsCount()

    /**
     * @return void
     */
    public function testFourFailuresOpenCircuit(): void
    {
        $endpoint = ['id' => 'ep-2'];
        for ($i = 0; $i < 4; $i++) {
            $this->service->recordFailure($endpoint, ['code' => 'TIMEOUT']);
        }
        $this->assertFalse($this->service->checkEndpoint($endpoint));
        $this->assertCount(1, $this->dispatched);
        $this->assertSame('stuf_circuit_open', $this->dispatched[0]['type']);
        $this->assertSame('ep-2', $this->dispatched[0]['context']['endpointId']);
    }//end testFourFailuresOpenCircuit()

    /**
     * @return void
     */
    public function testResetEndpointClearsCount(): void
    {
        $endpoint = ['id' => 'ep-3'];
        $this->service->recordFailure($endpoint);
        $this->service->recordFailure($endpoint);
        $this->service->resetEndpoint($endpoint);
        $this->assertSame(0, $this->service->snapshot('ep-3')['failureCount']);
        $this->assertSame('ok', $this->service->snapshot('ep-3')['state']);
    }//end testResetEndpointClearsCount()

    /**
     * @return void
     */
    public function testCircuitResetsAfterCooldown(): void
    {
        $endpoint = ['id' => 'ep-4'];
        for ($i = 0; $i < 4; $i++) {
            $this->service->recordFailure($endpoint);
        }
        $this->assertFalse($this->service->checkEndpoint($endpoint));

        // Force the open timestamp into the past beyond the cooldown.
        $this->store['stuf.cb.open.ep-4'] = (time() - (CircuitBreakerService::COOLDOWN_SECONDS + 1));
        $this->assertTrue($this->service->checkEndpoint($endpoint));
    }//end testCircuitResetsAfterCooldown()

    /**
     * @return void
     */
    public function testCheckEndpointWithoutIdReturnsTrue(): void
    {
        $this->assertTrue($this->service->checkEndpoint([]));
    }//end testCheckEndpointWithoutIdReturnsTrue()
}//end class
