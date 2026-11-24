<?php

/**
 * Unit tests for the Lifecycle contract registrar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Kernel\Contracts\BillingHandoffAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastAccessInterface;
use OCA\Pipelinq\Kernel\Contracts\PosAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\SessionUserInterface;
use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Lifecycle\ForecastAccess;
use OCA\Pipelinq\Lifecycle\LifecycleContractRegistrar;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\SessionUser;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Each lifecycle contract resolves to the class that implements it.
 *
 * @covers \OCA\Pipelinq\Lifecycle\LifecycleContractRegistrar
 */
class LifecycleContractRegistrarTest extends TestCase {

	/**
	 * register() aliases every lifecycle contract, and only those, to its implementation.
	 *
	 * @return void
	 */
	public function testRegisterAliasesEveryContractToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->exactly(4))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new LifecycleContractRegistrar())->register(context: $context);

		$this->assertSame(
			[
				BillingHandoffAccessPolicyInterface::class => BillingHandoffAccessPolicy::class,
				ForecastAccessInterface::class             => ForecastAccess::class,
				PosAccessPolicyInterface::class            => PosAccessPolicy::class,
				SessionUserInterface::class                => SessionUser::class,
			],
			$aliases
		);
		foreach ($aliases as $contract => $implementation) {
			$this->assertTrue(is_subclass_of($implementation, $contract));
		}
	}//end testRegisterAliasesEveryContractToItsImplementation()
}//end class
