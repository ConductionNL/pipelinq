<?php

/**
 * Unit tests for AutomationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AutomationService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationService.
 */
class AutomationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AutomationService
     */
    private AutomationService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->appConfig   = $this->createMock(IAppConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new AutomationService(
            $this->container,
            $this->appConfig,
            $this->userSession,
            $this->logger
        );
    }//end setUp()

    /**
     * Test getValidTriggers returns the correct trigger types.
     *
     * @return void
     */
    public function testGetValidTriggersReturnsCorrectTypes(): void
    {
        $triggers = $this->service->getValidTriggers();

        $this->assertIsArray($triggers);
        $this->assertContains('lead_created', $triggers);
        $this->assertContains('lead_stage_changed', $triggers);
        $this->assertContains('lead_assigned', $triggers);
        $this->assertContains('contact_created', $triggers);
        $this->assertContains('request_created', $triggers);
    }//end testGetValidTriggersReturnsCorrectTypes()

    /**
     * Test getValidActions returns the correct action types.
     *
     * @return void
     */
    public function testGetValidActionsReturnsCorrectTypes(): void
    {
        $actions = $this->service->getValidActions();

        $this->assertIsArray($actions);
        $this->assertContains('assign_lead', $actions);
        $this->assertContains('move_stage', $actions);
        $this->assertContains('send_notification', $actions);
        $this->assertContains('add_note', $actions);
        $this->assertContains('webhook', $actions);
    }//end testGetValidActionsReturnsCorrectTypes()

    /**
     * Test matchesConditions returns true for active automation matching trigger.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsTrueForMatchingAutomation(): void
    {
        $automation = [
            'isActive' => true,
            'trigger'  => 'lead_created',
        ];

        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_created',
            entityData: []
        );

        $this->assertTrue($matches);
    }//end testMatchesConditionsReturnsTrueForMatchingAutomation()

    /**
     * Test matchesConditions returns false for inactive automation.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsFalseForInactiveAutomation(): void
    {
        $automation = [
            'isActive' => false,
            'trigger'  => 'lead_created',
        ];

        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_created',
            entityData: []
        );

        $this->assertFalse($matches);
    }//end testMatchesConditionsReturnsFalseForInactiveAutomation()

    /**
     * Test matchesConditions returns false for mismatched trigger.
     *
     * @return void
     */
    public function testMatchesConditionsReturnsFalseForMismatchedTrigger(): void
    {
        $automation = [
            'isActive' => true,
            'trigger'  => 'lead_created',
        ];

        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_updated',
            entityData: []
        );

        $this->assertFalse($matches);
    }//end testMatchesConditionsReturnsFalseForMismatchedTrigger()

    /**
     * Test matchesConditions evaluates trigger conditions correctly.
     *
     * @return void
     */
    public function testMatchesConditionsEvaluatesTriggerConditions(): void
    {
        $automation = [
            'isActive'         => true,
            'trigger'          => 'lead_created',
            'triggerConditions' => [
                'pipeline' => 'sales-pipeline',
                'value'    => ['operator' => 'gte', 'value' => 10000],
            ],
        ];

        // Should match
        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_created',
            entityData: ['pipeline' => 'sales-pipeline', 'value' => 15000]
        );
        $this->assertTrue($matches);

        // Should not match - value too low
        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_created',
            entityData: ['pipeline' => 'sales-pipeline', 'value' => 5000]
        );
        $this->assertFalse($matches);

        // Should not match - wrong pipeline
        $matches = $this->service->matchesConditions(
            automation: $automation,
            trigger: 'lead_created',
            entityData: ['pipeline' => 'other-pipeline', 'value' => 15000]
        );
        $this->assertFalse($matches);
    }//end testMatchesConditionsEvaluatesTriggerConditions()

    /**
     * Test buildWebhookPayload creates correct structure.
     *
     * @return void
     */
    public function testBuildWebhookPayloadCreatesCorrectStructure(): void
    {
        $automation = [
            'id'      => '123-456',
            'name'    => 'Test Automation',
            'actions' => [
                ['type' => 'send_notification'],
            ],
        ];

        $payload = $this->service->buildWebhookPayload(
            automation: $automation,
            trigger: 'lead_created',
            entityData: ['name' => 'Test Lead']
        );

        $this->assertIsArray($payload);
        $this->assertEquals('123-456', $payload['automationId']);
        $this->assertEquals('Test Automation', $payload['automationName']);
        $this->assertEquals('lead_created', $payload['trigger']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('entity', $payload);
    }//end testBuildWebhookPayloadCreatesCorrectStructure()

    /**
     * Test fireWebhook returns skipped when URL is empty.
     *
     * @return void
     */
    public function testFireWebhookReturnsSkippedWhenUrlEmpty(): void
    {
        $result = $this->service->fireWebhook(
            webhookUrl: '',
            payload: ['test' => 'data']
        );

        $this->assertEquals('skipped', $result['status']);
        $this->assertStringContainsString('No webhook URL', $result['reason'] ?? '');
    }//end testFireWebhookReturnsSkippedWhenUrlEmpty()

    /**
     * Test fireWebhook handles exceptions gracefully.
     *
     * @return void
     */
    public function testFireWebhookHandlesExceptionsGracefully(): void
    {
        // Use invalid URL to trigger exception
        $result = $this->service->fireWebhook(
            webhookUrl: 'not-a-valid-url',
            payload: ['test' => 'data']
        );

        $this->assertEquals('failure', $result['status']);
        $this->assertArrayHasKey('error', $result);
    }//end testFireWebhookHandlesExceptionsGracefully()
}//end class
