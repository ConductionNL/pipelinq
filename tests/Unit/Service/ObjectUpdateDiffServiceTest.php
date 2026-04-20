<?php

/**
 * Unit tests for ObjectUpdateDiffService.
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

use OCA\Pipelinq\Service\ObjectEventDispatcher;
use OCA\Pipelinq\Service\ObjectUpdateDiffService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectUpdateDiffService.
 */
class ObjectUpdateDiffServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ObjectUpdateDiffService
     */
    private ObjectUpdateDiffService $service;

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
        $this->service    = new ObjectUpdateDiffService();
        $this->dispatcher = $this->createMock(ObjectEventDispatcher::class);
    }//end setUp()

    /**
     * Test assignee change dispatches event.
     *
     * @return void
     */
    public function testAssigneeChangeDispatches(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatchAssigneeChange')
            ->with('lead', 'Deal', '123', 'user2');

        $this->service->dispatchAssigneeChangeIfNeeded(
            ['assignee' => 'user1'],
            'lead',
            'Deal',
            '123',
            'user2',
            $this->dispatcher
        );
    }//end testAssigneeChangeDispatches()

    /**
     * Test same assignee does not dispatch.
     *
     * @return void
     */
    public function testSameAssigneeNoDispatch(): void
    {
        $this->dispatcher->expects($this->never())
            ->method('dispatchAssigneeChange');

        $this->service->dispatchAssigneeChangeIfNeeded(
            ['assignee' => 'user1'],
            'lead',
            'Deal',
            '123',
            'user1',
            $this->dispatcher
        );
    }//end testSameAssigneeNoDispatch()

    /**
     * Test empty assignee does not dispatch.
     *
     * @return void
     */
    public function testEmptyAssigneeNoDispatch(): void
    {
        $this->dispatcher->expects($this->never())
            ->method('dispatchAssigneeChange');

        $this->service->dispatchAssigneeChangeIfNeeded(
            ['assignee' => 'user1'],
            'lead',
            'Deal',
            '123',
            '',
            $this->dispatcher
        );
    }//end testEmptyAssigneeNoDispatch()

    /**
     * Test stage change dispatches regular stage change.
     *
     * @return void
     */
    public function testStageChangeDispatchesRegular(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatchStageChange')
            ->with('Deal', '123', 'Negotiation', 'user1');

        $this->service->dispatchStageChangeIfNeeded(
            ['stage' => 'Negotiation'],
            ['stage' => 'New'],
            'Deal',
            '123',
            'user1',
            $this->dispatcher
        );
    }//end testStageChangeDispatchesRegular()

    /**
     * Test stage Won dispatches deal won.
     *
     * @return void
     */
    public function testStageWonDispatchesDealWon(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatchDealWon')
            ->with('Deal', '50000', '123', 'user1');

        $this->service->dispatchStageChangeIfNeeded(
            ['stage' => 'Won', 'value' => '50000'],
            ['stage' => 'Negotiation'],
            'Deal',
            '123',
            'user1',
            $this->dispatcher
        );
    }//end testStageWonDispatchesDealWon()

    /**
     * Test stage Lost dispatches deal lost.
     *
     * @return void
     */
    public function testStageLostDispatchesDealLost(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatchDealLost')
            ->with('Deal', '123', 'user1');

        $this->service->dispatchStageChangeIfNeeded(
            ['stage' => 'Lost'],
            ['stage' => 'Negotiation'],
            'Deal',
            '123',
            'user1',
            $this->dispatcher
        );
    }//end testStageLostDispatchesDealLost()

    /**
     * Test same stage does not dispatch.
     *
     * @return void
     */
    public function testSameStageNoDispatch(): void
    {
        $this->dispatcher->expects($this->never())
            ->method('dispatchStageChange');

        $this->service->dispatchStageChangeIfNeeded(
            ['stage' => 'New'],
            ['stage' => 'New'],
            'Deal',
            '123',
            'user1',
            $this->dispatcher
        );
    }//end testSameStageNoDispatch()

    /**
     * Test status change dispatches.
     *
     * @return void
     */
    public function testStatusChangeDispatches(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatchStatusChange')
            ->with('Request', '456', 'completed', 'user1');

        $this->service->dispatchStatusChangeIfNeeded(
            ['status' => 'completed'],
            ['status' => 'new'],
            'Request',
            '456',
            'user1',
            $this->dispatcher
        );
    }//end testStatusChangeDispatches()

    /**
     * Test same status does not dispatch.
     *
     * @return void
     */
    public function testSameStatusNoDispatch(): void
    {
        $this->dispatcher->expects($this->never())
            ->method('dispatchStatusChange');

        $this->service->dispatchStatusChangeIfNeeded(
            ['status' => 'new'],
            ['status' => 'new'],
            'Request',
            '456',
            'user1',
            $this->dispatcher
        );
    }//end testSameStatusNoDispatch()
}//end class
