<?php

/**
 * Unit tests for IntegrationRegistrar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;

use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Integration\IntegrationRegistrar;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Integration\TicketLeafProvider;
use OCA\Pipelinq\Kernel\Contracts\ContactMomentLeafProviderInterface;
use OCA\Pipelinq\Kernel\Contracts\PartyLeafProviderInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketLeafProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The registrar aliases each Kernel leaf-provider contract to the Integration
 * class that implements it, and registers nothing else.
 *
 * @covers \OCA\Pipelinq\Integration\IntegrationRegistrar
 */
class IntegrationRegistrarTest extends TestCase {

	/**
	 * Every contract is aliased to its own implementation, once, in order,
	 * and no service or other registration is made.
	 *
	 * @return void
	 */
	public function testEachContractIsAliasedToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: 3))
			->method('registerServiceAlias')
			->willReturnCallback(
				static function (string $alias, string $target) use (&$aliases): void {
					$aliases[] = [$alias, $target];
				}
			);
		$context->expects($this->never())->method('registerService');

		(new IntegrationRegistrar())->register(context: $context);

		$this->assertSame(
			expected: [
				[ContactMomentLeafProviderInterface::class, ContactMomentLeafProvider::class],
				[PartyLeafProviderInterface::class, PartyLeafProvider::class],
				[TicketLeafProviderInterface::class, TicketLeafProvider::class],
			],
			actual: $aliases
		);
	}//end testEachContractIsAliasedToItsImplementation()

	/**
	 * Each implementation actually fulfils the contract it is aliased to, so
	 * the container hands out an instance of the requested interface.
	 *
	 * @return void
	 */
	public function testEachImplementationFulfilsItsContract(): void {
		$this->assertTrue(condition: is_subclass_of(ContactMomentLeafProvider::class, ContactMomentLeafProviderInterface::class));
		$this->assertTrue(condition: is_subclass_of(PartyLeafProvider::class, PartyLeafProviderInterface::class));
		$this->assertTrue(condition: is_subclass_of(TicketLeafProvider::class, TicketLeafProviderInterface::class));
	}//end testEachImplementationFulfilsItsContract()
}//end class
