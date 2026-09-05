<?php

/**
 * Test stub for OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys.
 *
 * Declares the config vocabulary a node accepts, so the engine's preflight
 * can raise a blocking finding on a key nothing reads. Declaration only.
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
 * A node that declares which configuration keys it reads.
 */
interface IFlowNodeConfigKeys {

	/**
	 * The configuration keys this node accepts.
	 *
	 * @return array The accepted keys.
	 */
	public function configKeys(): array;
}//end interface
