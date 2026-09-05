<?php

/**
 * Pipelinq FlowNodeListener.
 *
 * Contributes pipelinq's own node types to OpenRegister's single flow engine
 * when it asks for them.
 *
 * 🔴 ONE UNRESOLVABLE NODE MUST NOT COST THE OTHERS THEIR PLACE. Each class is
 * resolved separately and a failure is logged and skipped, so a node whose
 * dependency is missing on this instance leaves the rest of the palette
 * intact instead of emptying it.
 *
 * @category Flow
 * @package  OCA\Pipelinq\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FlowNodeListener: pipelinq's contribution to the flow node registry.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class FlowNodeListener implements IEventListener {

	/**
	 * The node classes pipelinq contributes.
	 *
	 * @var array<int, class-string>
	 */
	private const NODES = [
		JourneyActionNode::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register every node pipelinq owns.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		foreach (self::NODES as $class) {
			try {
				$node = $this->container->get($class);
			} catch (Throwable $e) {
				$this->logger->warning(
					'FlowNodeListener: a pipelinq flow node could not be resolved',
					['node' => $class, 'exception' => $e->getMessage()]
				);
				continue;
			}

			if (($node instanceof IFlowNode) === false) {
				continue;
			}

			$event->registerNode(node: $node);
		}
	}//end handle()
}//end class
