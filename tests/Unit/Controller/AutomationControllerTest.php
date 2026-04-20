<?php

/**
 * Unit tests for AutomationController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\AutomationController;
use OCA\Pipelinq\Service\AutomationService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AutomationController.
 */
class AutomationControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var AutomationController
     */
    private AutomationController $controller;

    /**
     * Mock automation service.
     *
     * @var AutomationService
     */
    private AutomationService $automationService;

    /**
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Mock l10n.
     *
     * @var IL10N
     */
    private IL10N $l10n;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request              = $this->createMock(IRequest::class);
        $this->automationService    = $this->createMock(AutomationService::class);
        $this->l10n                 = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->controller = new AutomationController(
            $this->request,
            $this->automationService,
            $this->l10n,
        );
    }//end setUp()

    /**
     * Test index returns list of automations.
     *
     * @return void
     */
    public function testIndexReturnsListOfAutomations(): void
    {
        $automations = [
            ['id' => '1', 'name' => 'Auto 1', 'trigger' => 'lead_created'],
            ['id' => '2', 'name' => 'Auto 2', 'trigger' => 'lead_stage_changed'],
        ];

        $this->automationService->method('listAutomations')
            ->willReturn($automations);

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('automations', $data);
        $this->assertCount(2, $data['automations']);
    }//end testIndexReturnsListOfAutomations()

    /**
     * Test show returns automation details.
     *
     * @return void
     */
    public function testShowReturnsAutomationDetails(): void
    {
        $automation = ['id' => '123', 'name' => 'Test Automation', 'trigger' => 'lead_created'];

        $this->automationService->method('getAutomation')
            ->with('123')
            ->willReturn($automation);

        $response = $this->controller->show('123');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('automation', $data);
        $this->assertEquals('Test Automation', $data['automation']['name']);
    }//end testShowReturnsAutomationDetails()

    /**
     * Test show returns 404 when automation not found.
     *
     * @return void
     */
    public function testShowReturns404WhenNotFound(): void
    {
        $this->automationService->method('getAutomation')
            ->with('invalid-id')
            ->willReturn(null);

        $response = $this->controller->show('invalid-id');

        $this->assertSame(404, $response->getStatus());
    }//end testShowReturns404WhenNotFound()

    /**
     * Test create returns 400 when name is missing.
     *
     * @return void
     */
    public function testCreateReturns400WhenNameMissing(): void
    {
        $this->request->method('getParams')
            ->willReturn(['trigger' => 'lead_created']);

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('error', $data);
    }//end testCreateReturns400WhenNameMissing()

    /**
     * Test create returns 400 when trigger is missing.
     *
     * @return void
     */
    public function testCreateReturns400WhenTriggerMissing(): void
    {
        $this->request->method('getParams')
            ->willReturn(['name' => 'Test Automation']);

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
    }//end testCreateReturns400WhenTriggerMissing()

    /**
     * Test create successfully creates automation.
     *
     * @return void
     */
    public function testCreateSuccessfullyCreatesAutomation(): void
    {
        $data = [
            'name'    => 'New Automation',
            'trigger' => 'lead_created',
        ];

        $this->request->method('getParams')
            ->willReturn($data);

        $savedAutomation = array_merge($data, ['id' => '123', 'isActive' => true]);

        $this->automationService->method('saveAutomation')
            ->with($this->callback(function ($arg) {
                return $arg['name'] === 'New Automation' && $arg['trigger'] === 'lead_created';
            }))
            ->willReturn($savedAutomation);

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('automation', $data);
    }//end testCreateSuccessfullyCreatesAutomation()

    /**
     * Test update successfully updates automation.
     *
     * @return void
     */
    public function testUpdateSuccessfullyUpdatesAutomation(): void
    {
        $updateData = ['name' => 'Updated Automation'];
        $this->request->method('getParams')
            ->willReturn($updateData);

        $updatedAutomation = [
            'id'      => '123',
            'name'    => 'Updated Automation',
            'trigger' => 'lead_created',
        ];

        $this->automationService->method('saveAutomation')
            ->willReturn($updatedAutomation);

        $response = $this->controller->update('123');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertEquals('Updated Automation', $data['automation']['name']);
    }//end testUpdateSuccessfullyUpdatesAutomation()

    /**
     * Test destroy successfully deletes automation.
     *
     * @return void
     */
    public function testDestroySuccessfullyDeletesAutomation(): void
    {
        $this->automationService->method('deleteAutomation')
            ->with('123')
            ->willReturn(true);

        $response = $this->controller->destroy('123');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testDestroySuccessfullyDeletesAutomation()

    /**
     * Test destroy returns 404 when automation not found.
     *
     * @return void
     */
    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->automationService->method('deleteAutomation')
            ->with('invalid-id')
            ->willThrowException(
                new \Exception('Automation not found: invalid-id')
            );

        $response = $this->controller->destroy('invalid-id');

        $this->assertSame(404, $response->getStatus());
    }//end testDestroyReturns404WhenNotFound()

    /**
     * Test history returns empty history for automation.
     *
     * @return void
     */
    public function testHistoryReturnsEmptyHistoryForAutomation(): void
    {
        $automation = ['id' => '123', 'name' => 'Test Automation'];

        $this->automationService->method('getAutomation')
            ->with('123')
            ->willReturn($automation);

        $response = $this->controller->history('123');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('history', $data);
        $this->assertArrayHasKey('automationId', $data);
    }//end testHistoryReturnsEmptyHistoryForAutomation()

    /**
     * Test history returns 404 when automation not found.
     *
     * @return void
     */
    public function testHistoryReturns404WhenNotFound(): void
    {
        $this->automationService->method('getAutomation')
            ->with('invalid-id')
            ->willReturn(null);

        $response = $this->controller->history('invalid-id');

        $this->assertSame(404, $response->getStatus());
    }//end testHistoryReturns404WhenNotFound()

    /**
     * Test metadata returns valid triggers and actions.
     *
     * @return void
     */
    public function testMetadataReturnsValidTriggersAndActions(): void
    {
        $triggers = ['lead_created', 'lead_stage_changed'];
        $actions = ['assign_lead', 'move_stage', 'send_notification'];

        $this->automationService->method('getValidTriggers')
            ->willReturn($triggers);
        $this->automationService->method('getValidActions')
            ->willReturn($actions);

        $response = $this->controller->metadata();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('triggers', $data);
        $this->assertArrayHasKey('actions', $data);
        $this->assertCount(2, $data['triggers']);
        $this->assertCount(3, $data['actions']);
    }//end testMetadataReturnsValidTriggersAndActions()

    /**
     * Test test method evaluates conditions correctly.
     *
     * @return void
     */
    public function testTestMethodEvaluatesConditionsCorrectly(): void
    {
        $automation = ['isActive' => true, 'trigger' => 'lead_created'];
        $trigger = 'lead_created';
        $entityData = ['value' => 10000];

        $this->request->method('getParam')
            ->willReturnMap([
                ['automation', [], $automation],
                ['trigger', '', $trigger],
                ['entityData', [], $entityData],
            ]);

        $this->automationService->method('matchesConditions')
            ->willReturn(true);
        $this->automationService->method('buildWebhookPayload')
            ->willReturn(['test' => 'payload']);

        $response = $this->controller->test();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('matches', $data);
        $this->assertTrue($data['matches']);
    }//end testTestMethodEvaluatesConditionsCorrectly()
}//end class
