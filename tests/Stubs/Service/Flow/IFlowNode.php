<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\IFlowNode.
 *
 * Mirrors the real interface
 * (openregister/lib/Service/Flow/IFlowNode.php) method for method, so a
 * pipelinq node implementing it analyses without OpenRegister on the path.
 * Declaration only: PSR-4 maps `OCA\Pipelinq\` to `lib/`, so this file is
 * never autoloaded at runtime and the real interface always wins.
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

/**
 * A flow node type contributed to OpenRegister's single flow engine.
 */
interface IFlowNode {

	/**
	 * The app-namespaced type identifier.
	 *
	 * @return string The type identifier.
	 */
	public function getId(): string;

	/**
	 * Human-readable name for the palette.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string;

	/**
	 * One sentence describing what the node does.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string;

	/**
	 * Absolute URL of the palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string;

	/**
	 * Whether the node may be used in the given workflow scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * Refuse an unusable configuration at save time.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When the configuration is unusable.
	 */
	public function validateConfig(array $config): void;

	/**
	 * Do the work: items in, items out.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 */
	public function execute(array $items, array $config, array $context): array;
}//end interface
