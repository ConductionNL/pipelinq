<?php

/**
 * Unit tests for ObjectEventHandlerService.
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

use OCA\Pipelinq\Service\AutomationService;
use OCA\Pipelinq\Service\ObjectEventDispatcher;
use OCA\Pipelinq\Service\ObjectEventHandlerService;
use OCA\Pipelinq\Service\ObjectUpdateDiffService;
use OCA\Pipelinq\Service\SchemaMapService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectEventHandlerService.
 */
class ObjectEventHandlerServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ObjectEventHandlerService
     */
    private ObjectEventHandlerService $service;

    /**
     * Mock schema map service.
     *
     * @var SchemaMapService
     */
    private SchemaMapService $schemaMapService;

    /**
     * Mock dispatcher.
     *
     * @var ObjectEventDispatcher
     */
    private ObjectEventDispatcher $dispatcher;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->schemaMapService = $this->createMock(SchemaMapService::class);
        $this->dispatcher       = $this->createMock(ObjectEventDispatcher::class);
        $diffService            = new ObjectUpdateDiffService();
        $automationService      = $this->createMock(AutomationService::class);

        $this->service = new ObjectEventHandlerService(
            $this->schemaMapService,
            $this->dispatcher,
            $diffService,
            $automationService,
        );
    }//end setUp()

    /**
     * Test handleCreated skips irrelevant entity types.
     *
     * @return void
     */
    public function testHandleCreatedSkipsIrrelevantType(): void
    {
        $this->schemaMapService->method('resolveEntityType')->willReturn('pipeline');

        $entity = new class {
            public function getSchema(): string { return '100'; }
            public function getObject(): array { return []; }
            public function getId(): int { return 1; }
        };

        $this->dispatcher->expects($this->never())->method('dispatchCreated');

        $this->service->handleCreated($entity);
    }//end testHandleCreatedSkipsIrrelevantType()

    /**
     * Test handleCreated dispatches for lead type.
     *
     * @return void
     */
    public function testHandleCreatedDispatchesForLead(): void
    {
        $this->schemaMapService->method('resolveEntityType')->willReturn('lead');

        $entity = new class {
            public function getSchema(): string { return '100'; }
            public function getObject(): array { return ['title' => 'Deal', 'assignee' => 'user1']; }
            public function getId(): int { return 42; }
        };

        $this->dispatcher->expects($this->once())
            ->method('dispatchCreated')
            ->with('lead', 'Deal', '42', 'user1');

        $this->service->handleCreated($entity);
    }//end testHandleCreatedDispatchesForLead()

    /**
     * Test handleCreated skips null entity type.
     *
     * @return void
     */
    public function testHandleCreatedSkipsNullType(): void
    {
        $this->schemaMapService->method('resolveEntityType')->willReturn(null);

        $entity = new class {
            public function getSchema(): string { return '999'; }
            public function getObject(): array { return []; }
            public function getId(): int { return 1; }
        };

        $this->dispatcher->expects($this->never())->method('dispatchCreated');

        $this->service->handleCreated($entity);
    }//end testHandleCreatedSkipsNullType()

    /**
     * Test handleUpdated dispatches stage change for lead.
     *
     * @return void
     */
    public function testHandleUpdatedDispatchesStageChangeForLead(): void
    {
        $this->schemaMapService->method('resolveEntityType')->willReturn('lead');

        $newEntity = new class {
            public function getSchema(): string { return '100'; }
            public function getObject(): array { return ['title' => 'Deal', 'assignee' => 'u1', 'stage' => 'Won']; }
            public function getId(): int { return 42; }
        };

        $oldEntity = new class {
            public function getObject(): array { return ['title' => 'Deal', 'assignee' => 'u1', 'stage' => 'New']; }
        };

        $this->dispatcher->expects($this->once())->method('dispatchDealWon');

        $this->service->handleUpdated($newEntity, $oldEntity);
    }//end testHandleUpdatedDispatchesStageChangeForLead()
}//end class
