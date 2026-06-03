<?php

/**
 * Unit tests for ObjectenAccessService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ObjectenAccessService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectenAccessService.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1
 */
class ObjectenAccessServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ObjectenAccessService
     */
    private ObjectenAccessService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock group manager.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ObjectenAccessService(
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that setSchemaAccess stores JSON-encoded group IDs.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testSetSchemaAccessStoresGroupIds(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(
                Application::APP_ID,
                'objecten_access_lead',
                json_encode(['sales-team', 'managers'])
            );

        $this->service->setSchemaAccess(schemaSlug: 'lead', groupIds: ['sales-team', 'managers']);
    }//end testSetSchemaAccessStoresGroupIds()

    /**
     * Test that getAccessMap returns map for all configured schemas.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testGetAccessMapReturnsAllSchemas(): void
    {
        $this->appConfig->method('getKeys')
            ->with(Application::APP_ID)
            ->willReturn(['register', 'objecten_access_lead', 'objecten_access_request']);

        $this->appConfig->method('getValueString')
            ->willReturnMap(
                    [
                        [Application::APP_ID, 'objecten_access_lead', '[]', '["sales-team"]'],
                        [Application::APP_ID, 'objecten_access_request', '[]', '["support"]'],
                    ]
                    );

        $map = $this->service->getAccessMap();

        $this->assertArrayHasKey(key: 'lead', array: $map);
        $this->assertArrayHasKey(key: 'request', array: $map);
        $this->assertSame(expected: ['sales-team'], actual: $map['lead']);
        $this->assertSame(expected: ['support'], actual: $map['request']);
    }//end testGetAccessMapReturnsAllSchemas()

    /**
     * Test that isAllowed returns true when no access map exists.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsTrueWhenNoMapExists(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->service->isAllowed(schemaSlug: 'lead', userId: 'user1');

        $this->assertTrue(condition: $result);
    }//end testIsAllowedReturnsTrueWhenNoMapExists()

    /**
     * Test that isAllowed returns true when user is in a configured group.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsTrueForGroupMember(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('["sales-team"]');

        $this->groupManager->method('isInGroup')
            ->with('user1', 'sales-team')
            ->willReturn(true);

        $result = $this->service->isAllowed(schemaSlug: 'lead', userId: 'user1');

        $this->assertTrue(condition: $result);
    }//end testIsAllowedReturnsTrueForGroupMember()

    /**
     * Test that isAllowed returns false when user is not in any configured group.
     *
     * @return void
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsFalseForNonMember(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('["sales-team"]');

        $this->groupManager->method('isInGroup')
            ->willReturn(false);

        $result = $this->service->isAllowed(schemaSlug: 'lead', userId: 'outsider');

        $this->assertFalse(condition: $result);
    }//end testIsAllowedReturnsFalseForNonMember()
}//end class
