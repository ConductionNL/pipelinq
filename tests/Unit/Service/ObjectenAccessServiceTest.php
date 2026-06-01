<?php

/**
 * ObjectenAccessService Unit Tests
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ObjectenAccessService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectenAccessService.
 *
 * @spec openspec/changes/admin-settings/tasks.md#task-1.1
 */
class ObjectenAccessServiceTest extends TestCase
{

    private ObjectenAccessService $service;

    private IAppConfig&MockObject $appConfig;

    private IGroupManager&MockObject $groupManager;


    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new ObjectenAccessService(
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
        );

    }//end setUp()


    /**
     * Test that setSchemaAccess stores JSON-encoded group IDs in IAppConfig.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testSetSchemaAccessStoresGroupIds(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(
                Application::APP_ID,
                'objecten_access_client',
                json_encode(['sales-team', 'admin-team']),
            );

        $this->service->setSchemaAccess(schemaSlug: 'client', groupIds: ['sales-team', 'admin-team']);

    }//end testSetSchemaAccessStoresGroupIds()


    /**
     * Test that setSchemaAccess with empty groupIds deletes the config key.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testSetSchemaAccessWithEmptyGroupIdsDeletesKey(): void
    {
        $this->appConfig->expects($this->once())
            ->method('deleteKey')
            ->with(Application::APP_ID, 'objecten_access_client');

        $this->appConfig->expects($this->never())->method('setValueString');

        $this->service->setSchemaAccess(schemaSlug: 'client', groupIds: []);

    }//end testSetSchemaAccessWithEmptyGroupIdsDeletesKey()


    /**
     * Test that getAccessMap returns all configured schemas with their group IDs.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testGetAccessMapReturnsAllSchemas(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getKeys')
            ->with(Application::APP_ID)
            ->willReturn(['objecten_access_client', 'objecten_access_lead', 'oauth_client_id']);

        $this->appConfig->expects($this->exactly(2))
            ->method('getValueString')
            ->willReturnMap([
                [Application::APP_ID, 'objecten_access_client', '[]', json_encode(['sales-team'])],
                [Application::APP_ID, 'objecten_access_lead', '[]', json_encode(['sales-team', 'managers'])],
            ]);

        $map = $this->service->getAccessMap();

        $this->assertArrayHasKey('client', $map);
        $this->assertArrayHasKey('lead', $map);
        $this->assertArrayNotHasKey('oauth_client_id', $map);
        $this->assertEquals(['sales-team'], $map['client']);
        $this->assertEquals(['sales-team', 'managers'], $map['lead']);

    }//end testGetAccessMapReturnsAllSchemas()


    /**
     * Test that isAllowed returns true when no access map exists for a schema (open default).
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsTrueWhenNoMapExists(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'objecten_access_contact', '')
            ->willReturn('');

        $result = $this->service->isAllowed(schemaSlug: 'contact', userId: 'user1');

        $this->assertTrue($result);

    }//end testIsAllowedReturnsTrueWhenNoMapExists()


    /**
     * Test that isAllowed returns true when user is in a configured group.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsTrueForGroupMember(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'objecten_access_lead', '')
            ->willReturn(json_encode(['sales-team']));

        $this->groupManager->expects($this->once())
            ->method('isInGroup')
            ->with('user1', 'sales-team')
            ->willReturn(true);

        $result = $this->service->isAllowed('lead', 'user1');

        $this->assertTrue($result);

    }//end testIsAllowedReturnsTrueForGroupMember()


    /**
     * Test that isAllowed returns false when user is not in any configured group.
     *
     * @spec openspec/changes/admin-settings/tasks.md#task-1.1
     */
    public function testIsAllowedReturnsFalseForNonMember(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with(Application::APP_ID, 'objecten_access_lead', '')
            ->willReturn(json_encode(['sales-team']));

        $this->groupManager->expects($this->once())
            ->method('isInGroup')
            ->with('user1', 'sales-team')
            ->willReturn(false);

        $result = $this->service->isAllowed('lead', 'user1');

        $this->assertFalse($result);

    }//end testIsAllowedReturnsFalseForNonMember()


}//end class
