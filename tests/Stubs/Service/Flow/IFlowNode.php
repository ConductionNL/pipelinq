<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\IFlowNode.
 *
 * Mirrors the real interface (openregister/lib/Service/Flow/IFlowNode.php)
 * method for method, so `CompetitorWatchRunNode` compiles and can be unit
 * tested on an instance where OpenRegister is not installed. The real
 * interface wins whenever OpenRegister is enabled: PSR-4 maps only
 * `OCA\Pipelinq\` to `lib/`, so this file is scanned by psalm and phpstan and
 * loaded by the test bootstrap, never autoloaded at run time.
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

if (interface_exists(IFlowNode::class) === false) {
	/**
	 * Stub of the flow node contract.
	 */
	interface IFlowNode {

		/**
		 * The node type.
		 *
		 * @return string The id.
		 */
		public function getId(): string;

		/**
		 * Palette name.
		 *
		 * @return string The display name.
		 */
		public function getDisplayName(): string;

		/**
		 * Palette description.
		 *
		 * @return string The description.
		 */
		public function getDescription(): string;

		/**
		 * Palette icon.
		 *
		 * @return string The icon URL.
		 */
		public function getIcon(): string;

		/**
		 * Whether the node is offered in a scope.
		 *
		 * @param int $scope The Nextcloud workflow scope constant.
		 *
		 * @return bool
		 */
		public function isAvailableForScope(int $scope): bool;

		/**
		 * Refuse a configuration that cannot work, at save time.
		 *
		 * @param array $config The authored configuration.
		 *
		 * @return void
		 */
		public function validateConfig(array $config): void;

		/**
		 * Do the work.
		 *
		 * @param array $items The input items.
		 * @param array $config The step configuration.
		 * @param array $context Run-level metadata.
		 *
		 * @return array The output items.
		 */
		public function execute(array $items, array $config, array $context): array;
	}//end interface
}
