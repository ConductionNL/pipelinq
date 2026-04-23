<?php

/**
 * Unit tests for AutomationController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\AutomationController;
use OCA\Pipelinq\Service\AutomationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AutomationController.
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
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
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Mock automation service.
     *
     * @var AutomationService
     */
    private AutomationService $automationService;

    /**
     * Mock localization service.
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
        $this->request = $this->createMock(IRequest::class);
        $this->automationService = $this->createMock(AutomationService::class);
        $this->l10n = $this->createMock(IL10N::class);

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
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testIndexReturnsListOfAutomations(): void
    {
        $automations = [
            ['id' => 'auto-1', 'name' => 'Auto 1'],
            ['id' => 'auto-2', 'name' => 'Auto 2'],
        ];

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 100, 100],
                ['_offset', 0, 0],
            ]);

        $this->automationService->method('listAutomations')
            ->willReturn($automations);

        $response = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true, 'data' => $automations], $response->getData());
    }//end testIndexReturnsListOfAutomations()

    /**
     * Test show returns single automation.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testShowReturnsSingleAutomation(): void
    {
        $automation = ['id' => 'auto-1', 'name' => 'Test Auto'];

        $this->automationService->method('getAutomation')
            ->with('auto-1')
            ->willReturn($automation);

        $response = $this->controller->show('auto-1');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true, 'data' => $automation], $response->getData());
    }//end testShowReturnsSingleAutomation()

    /**
     * Test show returns 404 when automation not found.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testShowReturns404WhenNotFound(): void
    {
        $this->automationService->method('getAutomation')
            ->with('nonexistent')
            ->willReturn(null);

        $response = $this->controller->show('nonexistent');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['error' => 'Automation not found'], $response->getData());
        self::assertEquals(404, $response->getStatus());
    }//end testShowReturns404WhenNotFound()

    /**
     * Test create creates new automation.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testCreateCreatesNewAutomation(): void
    {
        $newAutomation = ['name' => 'New Auto', 'trigger' => 'lead_created'];
        $savedAutomation = array_merge($newAutomation, ['id' => 'auto-new']);

        $this->request->method('getParams')
            ->willReturn($newAutomation);

        $this->automationService->method('saveAutomation')
            ->with($newAutomation)
            ->willReturn($savedAutomation);

        $response = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true, 'data' => $savedAutomation], $response->getData());
        self::assertEquals(201, $response->getStatus());
    }//end testCreateCreatesNewAutomation()

    /**
     * Test update updates existing automation.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testUpdateUpdatesExistingAutomation(): void
    {
        $originalAutomation = ['id' => 'auto-1', 'name' => 'Old Name', 'trigger' => 'lead_created'];
        $updateData = ['name' => 'New Name'];
        $updatedAutomation = array_merge($originalAutomation, $updateData);

        $this->automationService->method('getAutomation')
            ->with('auto-1')
            ->willReturn($originalAutomation);

        $this->request->method('getParams')
            ->willReturn($updateData);

        $this->automationService->method('saveAutomation')
            ->with($updatedAutomation)
            ->willReturn($updatedAutomation);

        $response = $this->controller->update('auto-1');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true, 'data' => $updatedAutomation], $response->getData());
    }//end testUpdateUpdatesExistingAutomation()

    /**
     * Test destroy deletes automation.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testDestroyDeletesAutomation(): void
    {
        $this->automationService->method('deleteAutomation')
            ->with('auto-1')
            ->willReturn(true);

        $response = $this->controller->destroy('auto-1');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true], $response->getData());
    }//end testDestroyDeletesAutomation()

    /**
     * Test history returns execution history.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testHistoryReturnsExecutionHistory(): void
    {
        $history = [
            ['id' => 'log-1', 'status' => 'success'],
            ['id' => 'log-2', 'status' => 'failure'],
        ];

        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', 50, 50],
                ['_offset', 0, 0],
            ]);

        $this->automationService->method('getExecutionHistory')
            ->with('auto-1')
            ->willReturn($history);

        $response = $this->controller->history('auto-1');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertEquals(['success' => true, 'data' => $history], $response->getData());
    }//end testHistoryReturnsExecutionHistory()

    /**
     * Test metadata returns valid triggers and actions.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testMetadataReturnsTriggersAndActions(): void
    {
        $triggers = ['lead_created', 'lead_stage_changed'];
        $actions = ['webhook', 'send_notification'];

        $this->automationService->method('getValidTriggers')
            ->willReturn($triggers);

        $this->automationService->method('getValidActions')
            ->willReturn($actions);

        $response = $this->controller->metadata();

        self::assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        self::assertEquals($triggers, $data['triggers']);
        self::assertEquals($actions, $data['actions']);
    }//end testMetadataReturnsTriggersAndActions()

    /**
     * Test test endpoint evaluates conditions correctly.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function testTestEvaluatesConditionsCorrectly(): void
    {
        $automation = ['name' => 'Test Auto', 'trigger' => 'lead_created'];
        $trigger = 'lead_created';
        $entityData = ['title' => 'Test Lead'];
        $payload = ['automationId' => '', 'trigger' => 'lead_created'];

        $this->request->method('getParam')
            ->willReturnMap([
                ['automation', [], $automation],
                ['trigger', '', $trigger],
                ['entityData', [], $entityData],
            ]);

        $this->automationService->method('matchesConditions')
            ->with($automation, $trigger, $entityData)
            ->willReturn(true);

        $this->automationService->method('buildWebhookPayload')
            ->with($automation, $trigger, $entityData)
            ->willReturn($payload);

        $response = $this->controller->test();

        self::assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        self::assertTrue($data['matches']);
        self::assertEquals($payload, $data['payload']);
    }//end testTestEvaluatesConditionsCorrectly()
}//end class
