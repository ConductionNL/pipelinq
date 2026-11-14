<?php

/**
 * PipelinqFlowNodeListener: which nodes it registers and how it survives a failing one.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\Pipelinq\Flow\CompetitorWatchRunNode;
use OCA\Pipelinq\Flow\JourneyActionNode;
use OCA\Pipelinq\Flow\PipelinqFlowNodeListener;
use OCA\Pipelinq\Flow\ProvisionContactIdentityNode;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every node the app ships is fetched from the container and registered in
 * order; one that cannot be built is logged and skipped.
 *
 * @covers \OCA\Pipelinq\Flow\PipelinqFlowNodeListener
 */
class PipelinqFlowNodeListenerTest extends TestCase {

	/**
	 * The three nodes, in registration order.
	 *
	 * @return void
	 */
	public function testNodeClasses(): void {
		$this->assertSame(
			[CompetitorWatchRunNode::class, JourneyActionNode::class, ProvisionContactIdentityNode::class],
			PipelinqFlowNodeListener::nodeClasses()
		);
	}//end testNodeClasses()

	/**
	 * Each node is registered on the event, in order, and nothing is logged.
	 *
	 * @return void
	 */
	public function testRegistersEveryNode(): void {
		$nodes = [];
		foreach (PipelinqFlowNodeListener::nodeClasses() as $class) {
			$nodes[$class] = $this->createMock(IFlowNode::class);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(3))->method('get')->willReturnCallback(static fn (string $id): IFlowNode => $nodes[$id]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$event = new RegisterFlowNodesEvent();

		(new PipelinqFlowNodeListener($container, $logger))->handle($event);

		$this->assertSame(array_values($nodes), $event->nodes());
	}//end testRegistersEveryNode()

	/**
	 * A node the container cannot build is logged with its class and skipped; the others still register.
	 *
	 * @return void
	 */
	public function testSkipsANodeThatCannotBeBuilt(): void {
		$first = $this->createMock(IFlowNode::class);
		$third = $this->createMock(IFlowNode::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($first, $third): IFlowNode {
				if ($id === JourneyActionNode::class) {
					throw new RuntimeException('missing dependency');
				}

				return ($id === CompetitorWatchRunNode::class) ? $first : $third;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'pipelinq: flow node ' . JourneyActionNode::class . ' could not be registered: missing dependency',
			['app' => 'pipelinq']
		);
		$event = new RegisterFlowNodesEvent();

		(new PipelinqFlowNodeListener($container, $logger))->handle($event);

		$this->assertSame([$first, $third], $event->nodes());
	}//end testSkipsANodeThatCannotBeBuilt()

	/**
	 * Any other event is ignored without touching the container.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		(new PipelinqFlowNodeListener($container, $this->createMock(LoggerInterface::class)))->handle(new Event());
	}//end testIgnoresOtherEvents()
}//end class
