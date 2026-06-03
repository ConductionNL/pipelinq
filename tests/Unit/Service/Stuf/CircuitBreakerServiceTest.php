<?php

/**
 * Unit tests for CircuitBreakerService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Stuf;

use OCA\Pipelinq\Service\Stuf\CircuitBreakerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CircuitBreakerService using an in-memory IAppConfig fake.
 */
class CircuitBreakerServiceTest extends TestCase
{

    /**
     * In-memory integer state store keyed by config key.
     *
     * @var array<string, int>
     */
    private array $store = [];

    /**
     * The breaker under test.
     *
     * @var CircuitBreakerService
     */
    private CircuitBreakerService $breaker;

    /**
     * Set up the test with an IAppConfig fake backed by $this->store.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store = [];

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            function (string $app, string $key, int $default=0): int {
                return $this->store[$key] ?? $default;
            }
        );
        $appConfig->method('setValueInt')->willReturnCallback(
            function (string $app, string $key, int $value): bool {
                $this->store[$key] = $value;
                return true;
            }
        );

        $this->breaker = new CircuitBreakerService(
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * recordFailure increments the consecutive-failure count.
     *
     * @return void
     */
    public function testRecordFailureIncrements(): void
    {
        $this->breaker->recordFailure(endpointId: 'ep-1');
        $this->breaker->recordFailure(endpointId: 'ep-1');

        $this->assertSame(2, $this->breaker->failureCount(endpointId: 'ep-1'));
    }//end testRecordFailureIncrements()

    /**
     * The breaker stays closed below the threshold and opens at the 4th failure.
     *
     * @return void
     */
    public function testOpensAtFourthFailure(): void
    {
        $this->assertTrue($this->breaker->checkEndpoint(endpointId: 'ep-1'));

        for ($i = 0; $i < 3; $i++) {
            $this->breaker->recordFailure(endpointId: 'ep-1');
        }

        $this->assertTrue($this->breaker->checkEndpoint(endpointId: 'ep-1'), 'still closed after 3 failures');

        $opened = $this->breaker->recordFailure(endpointId: 'ep-1');

        $this->assertTrue($opened, '4th failure opens the circuit');
        $this->assertFalse($this->breaker->checkEndpoint(endpointId: 'ep-1'));
    }//end testOpensAtFourthFailure()

    /**
     * resetEndpoint clears the failure count and re-closes the circuit.
     *
     * @return void
     */
    public function testResetClosesCircuit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->breaker->recordFailure(endpointId: 'ep-1');
        }

        $this->assertFalse($this->breaker->checkEndpoint(endpointId: 'ep-1'));

        $this->breaker->resetEndpoint(endpointId: 'ep-1');

        $this->assertTrue($this->breaker->checkEndpoint(endpointId: 'ep-1'));
        $this->assertSame(0, $this->breaker->failureCount(endpointId: 'ep-1'));
    }//end testResetClosesCircuit()

    /**
     * The circuit auto-resets once the cooldown has elapsed.
     *
     * @return void
     */
    public function testAutoResetAfterCooldown(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->breaker->recordFailure(endpointId: 'ep-1');
        }

        $this->assertFalse($this->breaker->checkEndpoint(endpointId: 'ep-1'));

        // Simulate the cooldown elapsing by back-dating the opened-at timestamp.
        $openedKey = null;
        foreach (array_keys($this->store) as $key) {
            if (str_contains($key, 'opened_at') === true) {
                $openedKey = $key;
            }
        }

        $this->assertNotNull($openedKey);
        $this->store[$openedKey] = (time() - 301);

        $this->assertTrue($this->breaker->checkEndpoint(endpointId: 'ep-1'), 'circuit re-closes after cooldown');
    }//end testAutoResetAfterCooldown()

    /**
     * Separate endpoints maintain independent breaker state.
     *
     * @return void
     */
    public function testEndpointsAreIsolated(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->breaker->recordFailure(endpointId: 'ep-1');
        }

        $this->assertFalse($this->breaker->checkEndpoint(endpointId: 'ep-1'));
        $this->assertTrue($this->breaker->checkEndpoint(endpointId: 'ep-2'));
    }//end testEndpointsAreIsolated()
}//end class
