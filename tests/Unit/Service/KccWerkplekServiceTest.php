<?php

/**
 * Unit tests for KccWerkplekService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCA\Pipelinq\Service\RoutingService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for KccWerkplekService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class KccWerkplekServiceTest extends TestCase
{
    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The routing service mock.
     *
     * @var RoutingService&MockObject
     */
    private RoutingService $routingService;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig      = $this->createMock(IAppConfig::class);
        $this->container      = $this->createMock(ContainerInterface::class);
        $this->routingService = $this->createMock(RoutingService::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return KccWerkplekService
     */
    private function buildService(): KccWerkplekService
    {
        return new KccWerkplekService(
            appConfig: $this->appConfig,
            container: $this->container,
            routingService: $this->routingService,
            logger: $this->logger,
        );
    }//end buildService()

    /**
     * Configure the app config to return register + schema IDs.
     *
     * @return void
     */
    private function configureAppConfig(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnMap(
                [
                    [Application::APP_ID, 'register', '', 'reg-id'],
                    [Application::APP_ID, 'request_schema', '', 'req-schema'],
                    [Application::APP_ID, 'task_schema', '', 'task-schema'],
                    [Application::APP_ID, 'agentProfile_schema', '', 'agent-schema'],
                ]
            );
    }//end configureAppConfig()

    /**
     * Create a mock ObjectService returned by the container.
     *
     * @return MockObject
     */
    private function createObjectServiceMock(): MockObject
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject', 'updateObject'])
            ->getMock();

        $this->container->method('get')->willReturn($mock);

        return $mock;
    }//end createObjectServiceMock()

    /**
     * getWorkspaceState filters requests/tasks by open status, counts queues,
     * and reuses RoutingService for the workload number.
     *
     * @return void
     */
    public function testGetWorkspaceStateAggregatesOpenItems(): void
    {
        $this->configureAppConfig();
        $this->routingService->method('getAgentWorkload')->willReturn(4);

        $objectService = $this->createObjectServiceMock();
        $objectService->method('findAll')->willReturnCallback(
            static function (array $query): array {
                $schema = ($query['filters']['schema'] ?? '');
                if ($schema === 'agent-schema') {
                    return [['userId' => 'jan', 'isAvailable' => true, 'maxConcurrent' => 3, 'skills' => ['s1']]];
                }

                if ($schema === 'task-schema') {
                    return [
                        ['id' => 't1', 'subject' => 'Bel terug', 'type' => 'terugbelverzoek', 'status' => 'open', 'deadline' => '2026-06-10'],
                        ['id' => 't2', 'subject' => 'Done', 'status' => 'afgehandeld'],
                    ];
                }

                // request schema (used twice: assigned + queue counts).
                return [
                    ['id' => 'r1', 'title' => 'Vraag A', 'status' => 'new', 'priority' => 'high', 'channel' => 'phone', 'queue' => 'queue-vergunningen'],
                    ['id' => 'r2', 'title' => 'Vraag B', 'status' => 'completed', 'queue' => 'queue-vergunningen'],
                    ['id' => 'r3', 'title' => 'Vraag C', 'status' => 'in_progress', 'queue' => 'queue-algemene-zaken'],
                ];
            }
        );

        $state = $this->buildService()->getWorkspaceState(userId: 'jan');

        // Only open requests (new + in_progress) remain.
        $this->assertCount(2, $state['assignedRequests']);
        // Only open tasks (status open) remain.
        $this->assertCount(1, $state['openTasks']);
        $this->assertSame('t1', $state['openTasks'][0]['id']);
        // Queue counts only count open requests.
        $this->assertSame(1, $state['queueCounts']['queue-vergunningen']);
        $this->assertSame(1, $state['queueCounts']['queue-algemene-zaken']);
        // Profile normalised.
        $this->assertTrue($state['agentProfile']['isAvailable']);
        $this->assertSame(3, $state['agentProfile']['maxConcurrent']);
        // Workload from RoutingService.
        $this->assertSame(4, $state['workload']);
    }//end testGetWorkspaceStateAggregatesOpenItems()

    /**
     * getWorkspaceState returns null profile + empty lists when unconfigured.
     *
     * @return void
     */
    public function testGetWorkspaceStateEmptyWhenUnconfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->routingService->method('getAgentWorkload')->willReturn(0);

        $state = $this->buildService()->getWorkspaceState(userId: 'jan');

        $this->assertNull($state['agentProfile']);
        $this->assertSame([], $state['assignedRequests']);
        $this->assertSame([], $state['openTasks']);
        $this->assertSame([], $state['queueCounts']);
    }//end testGetWorkspaceStateEmptyWhenUnconfigured()

    /**
     * setAvailability updates an existing profile via updateObject.
     *
     * @return void
     */
    public function testSetAvailabilityUpdatesExistingProfile(): void
    {
        $this->configureAppConfig();

        $objectService = $this->createObjectServiceMock();
        $objectService->method('findAll')->willReturn(
            [['id' => 'profile-1', 'userId' => 'jan', 'isAvailable' => true, 'maxConcurrent' => 5]]
        );
        $objectService->expects($this->once())
            ->method('updateObject')
            ->with('reg-id', 'agent-schema', 'profile-1', $this->callback(
                static fn(array $payload): bool => $payload['userId'] === 'jan' && $payload['isAvailable'] === false
            ))
            ->willReturn(['userId' => 'jan', 'isAvailable' => false, 'maxConcurrent' => 5]);

        $result = $this->buildService()->setAvailability(userId: 'jan', available: false);

        $this->assertFalse($result['isAvailable']);
        $this->assertSame(5, $result['maxConcurrent']);
    }//end testSetAvailabilityUpdatesExistingProfile()

    /**
     * setAvailability creates a new profile when none exists.
     *
     * @return void
     */
    public function testSetAvailabilityCreatesProfileWhenAbsent(): void
    {
        $this->configureAppConfig();

        $objectService = $this->createObjectServiceMock();
        $objectService->method('findAll')->willReturn([]);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->with('reg-id', 'agent-schema', $this->callback(
                static fn(array $payload): bool => $payload['userId'] === 'newuser' && $payload['isAvailable'] === true
            ))
            ->willReturn(['userId' => 'newuser', 'isAvailable' => true, 'maxConcurrent' => 10]);

        $result = $this->buildService()->setAvailability(userId: 'newuser', available: true);

        $this->assertTrue($result['isAvailable']);
    }//end testSetAvailabilityCreatesProfileWhenAbsent()

    /**
     * setAvailability throws when the register/schema is not configured.
     *
     * @return void
     */
    public function testSetAvailabilityThrowsWhenUnconfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $this->expectException(RuntimeException::class);

        $this->buildService()->setAvailability(userId: 'jan', available: true);
    }//end testSetAvailabilityThrowsWhenUnconfigured()
}//end class
