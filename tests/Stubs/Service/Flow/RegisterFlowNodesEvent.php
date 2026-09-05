<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent.
 *
 * Mirrors the shape `PipelinqFlowNodeListener` depends on: the event a leaf
 * app listens for, and the `registerNode()` it calls. The collected nodes are
 * readable here so a unit test can assert what was contributed, which the real
 * event does not need to offer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

if (class_exists(RegisterFlowNodesEvent::class) === false) {
	/**
	 * Stub of the node-registration event.
	 */
	class RegisterFlowNodesEvent extends Event {

		/**
		 * The nodes contributed so far.
		 *
		 * @var array<int, object>
		 */
		private array $nodes = [];

		/**
		 * Contribute one node.
		 *
		 * @param IFlowNode $node The node.
		 *
		 * @return void
		 */
		public function registerNode(IFlowNode $node): void {
			$this->nodes[] = $node;
		}//end registerNode()

		/**
		 * What was contributed.
		 *
		 * @return array<int, object> The nodes.
		 */
		public function nodes(): array {
			return $this->nodes;
		}//end nodes()
	}//end class
}
