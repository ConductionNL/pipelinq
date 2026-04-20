<?php

/**
 * Unit tests for AutomationService.
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
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AutomationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationService.
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
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
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock app manager.
     *
     * @var IAppManager
     */
    private IAppManager $appManager;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);

        $this->service = new AutomationService(
            $this->appConfig,
            $this->userSession,
            $this->logger,
            $this->container,
            $this->appManager,
        );
    }//end setUp()

    /**
     * Test getValidTriggers returns correct list of trigger types.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testGetValidTriggersReturnsCorrectTriggers(): void
    {
        $triggers = $this->service->getValidTriggers();

        self::assertIsArray($triggers);
        self::assertContains('lead_created', $triggers);
        self::assertContains('lead_stage_changed', $triggers);
        self::assertContains('lead_assigned', $triggers);
        self::assertContains('contact_created', $triggers);
        self::assertContains('request_created', $triggers);
        self::assertContains('request_status_changed', $triggers);
    }//end testGetValidTriggersReturnsCorrectTriggers()

    /**
     * Test getValidActions returns correct list of action types.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testGetValidActionsReturnsCorrectActions(): void
    {
        $actions = $this->service->getValidActions();

        self::assertIsArray($actions);
        self::assertContains('assign_lead', $actions);
        self::assertContains('move_stage', $actions);
        self::assertContains('send_notification', $actions);
        self::assertContains('update_field', $actions);
        self::assertContains('add_note', $actions);
        self::assertContains('webhook', $actions);
    }//end testGetValidActionsReturnsCorrectActions()

    /**
     * Test matchesConditions returns true when trigger and conditions match.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testMatchesConditionsWithNoConditions(): void
    {
        $automation = [
            'id' => 'auto-123',
            'name' => 'Test Automation',
            'trigger' => 'lead_created',
            'isActive' => true,
            'triggerConditions' => [],
        ];

        $trigger = 'lead_created';
        $entity = ['id' => 'lead-456', 'title' => 'Test Lead'];

        self::assertTrue(
            $this->service->matchesConditions(
                automation: $automation,
                trigger: $trigger,
                entityData: $entity
            )
        );
    }//end testMatchesConditionsWithNoConditions()

    /**
     * Test matchesConditions returns false when automation is inactive.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testMatchesConditionsReturnsFalseWhenInactive(): void
    {
        $automation = [
            'id' => 'auto-123',
            'name' => 'Test Automation',
            'trigger' => 'lead_created',
            'isActive' => false,
            'triggerConditions' => [],
        ];

        $trigger = 'lead_created';
        $entity = ['id' => 'lead-456', 'title' => 'Test Lead'];

        self::assertFalse(
            $this->service->matchesConditions(
                automation: $automation,
                trigger: $trigger,
                entityData: $entity
            )
        );
    }//end testMatchesConditionsReturnsFalseWhenInactive()

    /**
     * Test matchesConditions returns false when trigger does not match.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testMatchesConditionsReturnsFalseWhenTriggerNotMatching(): void
    {
        $automation = [
            'id' => 'auto-123',
            'name' => 'Test Automation',
            'trigger' => 'lead_created',
            'isActive' => true,
            'triggerConditions' => [],
        ];

        $trigger = 'contact_created';
        $entity = ['id' => 'lead-456', 'title' => 'Test Lead'];

        self::assertFalse(
            $this->service->matchesConditions(
                automation: $automation,
                trigger: $trigger,
                entityData: $entity
            )
        );
    }//end testMatchesConditionsReturnsFalseWhenTriggerNotMatching()

    /**
     * Test buildWebhookPayload creates correct payload structure.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-2.1
     */
    public function testBuildWebhookPayloadCreatesCorrectStructure(): void
    {
        $automation = [
            'id' => 'auto-123',
            'name' => 'Test Automation',
            'actions' => [['type' => 'webhook', 'config' => ['url' => 'http://example.com']]],
        ];

        $trigger = 'lead_created';
        $entity = ['id' => 'lead-456', 'title' => 'Test Lead'];

        $payload = $this->service->buildWebhookPayload(
            automation: $automation,
            trigger: $trigger,
            entityData: $entity
        );

        self::assertIsArray($payload);
        self::assertEquals('auto-123', $payload['automationId']);
        self::assertEquals('Test Automation', $payload['automationName']);
        self::assertEquals('lead_created', $payload['trigger']);
        self::assertEquals($entity, $payload['entity']);
        self::assertArrayHasKey('timestamp', $payload);
    }//end testBuildWebhookPayloadCreatesCorrectStructure()
}//end class
