<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent.
 *
 * The event a leaf app listens for to contribute its own node types.
 * Declaration only; the constructor argument is dropped because nothing in
 * pipelinq constructs one.
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

/**
 * Dispatched once so every app may contribute its flow node types.
 */
class RegisterFlowNodesEvent extends Event {

	/**
	 * Contribute a node type.
	 *
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 */
	public function registerNode(IFlowNode $node): void {
	}//end registerNode()
}//end class
