<?php

/**
 * Unit tests for the Lifecycle registrar.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Lifecycle\LifecycleGuardContext;
use OCA\Pipelinq\Kernel\Contracts\BillingHandoffAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastAccessInterface;
use OCA\Pipelinq\Kernel\Contracts\PosAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\SessionUserInterface;
use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Lifecycle\ForecastAccess;
use OCA\Pipelinq\Lifecycle\LifecycleRegistrar;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosRefundManagerGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard;
use OCA\Pipelinq\Lifecycle\SessionUser;
use OCA\Pipelinq\Lifecycle\TicketAssigneeGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The guard tags the POS schemas name are registered, and each factory
 * builds a guard wired to the shared access policy.
 *
 * @covers \OCA\Pipelinq\Lifecycle\LifecycleRegistrar
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosRefundManagerGuard
 * @uses   \OCA\Pipelinq\Lifecycle\TicketAssigneeGuard
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Lifecycle\LifecycleContractRegistrar
 */
class LifecycleRegistrarTest extends TestCase {

	/**
	 * The factories register() handed to the context, by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * The aliases register() handed to the context, contract => implementation.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Run register() against a context that records every factory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->factories = [];
		$context         = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(6))->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);
		$this->aliases   = [];
		$context->expects($this->exactly(4))->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);

		(new LifecycleRegistrar())->register($context);
	}//end setUp()

	/**
	 * A container that builds a registered service through its own factory,
	 * the way the app container resolves it, and serves the given boundaries.
	 *
	 * @param array<string, object> $services Boundary services by id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$factories = $this->factories;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$container, $services, $factories): mixed {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				if ($id === ContainerInterface::class) {
					return $container;
				}

				if (isset($factories[$id]) === true) {
					return $factories[$id]($container);
				}

				throw new RuntimeException('Unexpected service: '.$id);
			}
		);

		return $container;
	}//end container()

	/**
	 * A container whose group manager knows one admin, `root`.
	 *
	 * @return ContainerInterface
	 */
	private function containerWithAdmin(): ContainerInterface {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groupManager->method('isInGroup')->willReturn(false);

		return $this->container(
			[
				IAppConfig::class => $this->createMock(IAppConfig::class),
				IGroupManager::class => $groupManager,
				LoggerInterface::class => $this->createMock(LoggerInterface::class),
			]
		);
	}//end containerWithAdmin()

	/**
	 * Every guard tag the posTransaction / posRefund / ticket lifecycles
	 * reference is registered, the shared policy first.
	 *
	 * @return void
	 */
	public function testRegisterBindsThePolicyAndEveryPosGuard(): void {
		$this->assertSame(
			[
				PosAccessPolicy::class,
				PosTransactionAccessGuard::class,
				PosTransactionConfirmGuard::class,
				PosTransactionRefundGuard::class,
				PosRefundManagerGuard::class,
				TicketAssigneeGuard::class,
			],
			array_keys($this->factories)
		);
	}//end testRegisterBindsThePolicyAndEveryPosGuard()

	/**
	 * The Kernel contracts other components call resolve to their lifecycle
	 * implementations.
	 *
	 * @return void
	 */
	public function testRegisterAliasesTheLifecycleContracts(): void {
		$this->assertSame(
			[
				BillingHandoffAccessPolicyInterface::class => BillingHandoffAccessPolicy::class,
				ForecastAccessInterface::class             => ForecastAccess::class,
				PosAccessPolicyInterface::class            => PosAccessPolicy::class,
				SessionUserInterface::class                => SessionUser::class,
			],
			$this->aliases
		);
		foreach ($this->aliases as $contract => $implementation) {
			$this->assertTrue(is_subclass_of($implementation, $contract));
		}
	}//end testRegisterAliasesTheLifecycleContracts()

	/**
	 * Each factory builds an instance of the class its tag names.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsTheClassItIsRegisteredUnder(): void {
		$container = $this->containerWithAdmin();

		foreach ($this->factories as $name => $factory) {
			$this->assertInstanceOf($name, $factory($container), $name);
		}
	}//end testEveryFactoryBuildsTheClassItIsRegisteredUnder()

	/**
	 * The policy factory wires the group manager: an admin may access any
	 * transaction, a stranger may not.
	 *
	 * @return void
	 */
	public function testThePolicyFactoryConsultsTheGroupManager(): void {
		$policy = $this->factories[PosAccessPolicy::class]($this->containerWithAdmin());

		$this->assertTrue($policy->canAccessTransaction(['cashier' => 'alice'], 'root'));
		$this->assertFalse($policy->canAccessTransaction(['cashier' => 'alice'], 'mallory'));
	}//end testThePolicyFactoryConsultsTheGroupManager()

	/**
	 * The cashier-level guard built by its factory allows the owning cashier
	 * and refuses a stranger, through the policy the container resolves.
	 *
	 * @return void
	 */
	public function testTheAccessGuardFactoryUsesTheSharedPolicy(): void {
		$guard = $this->factories[PosTransactionAccessGuard::class]($this->containerWithAdmin());

		$this->assertTrue($guard->check(['cashier' => 'alice'], 'park', 'alice')->isAllowed());
		$this->assertTrue($guard->check(['cashier' => 'alice'], 'settle', 'root')->isAllowed());
		$this->assertFalse($guard->check(['cashier' => 'alice'], 'settle', 'mallory')->isAllowed());
	}//end testTheAccessGuardFactoryUsesTheSharedPolicy()

	/**
	 * The refund guard built by its factory is manager-only: an admin passes,
	 * even the owning cashier does not.
	 *
	 * @return void
	 */
	public function testTheRefundGuardFactoryIsManagerOnly(): void {
		$guard = $this->factories[PosTransactionRefundGuard::class]($this->containerWithAdmin());

		$this->assertTrue($guard->check(['cashier' => 'alice'], 'refund', 'root')->isAllowed());
		$this->assertFalse($guard->check(['cashier' => 'alice'], 'refund', 'alice')->isAllowed());
	}//end testTheRefundGuardFactoryIsManagerOnly()

	/**
	 * The ticket guard built by its factory admits the stored assignee and a staff
	 * member through the group manager the container resolves, and refuses
	 * anyone else.
	 *
	 * @return void
	 */
	public function testTheTicketGuardFactoryUsesTheGroupManager(): void {
		$guard = $this->factories[TicketAssigneeGuard::class]($this->containerWithAdmin());

		$stored = new ObjectEntity();
		$stored->setObject(['assignee' => 'alice']);
		$judge = static fn (string $uid): bool => $guard->checkTransition(
			context: new LifecycleGuardContext(object: [], stored: $stored, action: 'start', userId: $uid)
		)->isAllowed();

		$this->assertTrue($judge('alice'));
		$this->assertTrue($judge('root'));
		$this->assertFalse($judge('mallory'));
	}//end testTheTicketGuardFactoryUsesTheGroupManager()

	/**
	 * The confirm and refund-manager factories resolve every collaborator
	 * they need from the container, including the container itself.
	 *
	 * @return void
	 */
	public function testTheContainerAwareGuardsResolveTheirCollaborators(): void {
		$asked     = [];
		$inner     = $this->containerWithAdmin();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$asked, $inner): mixed {
				$asked[] = $id;
				return $inner->get($id);
			}
		);

		$this->assertInstanceOf(PosTransactionConfirmGuard::class, $this->factories[PosTransactionConfirmGuard::class]($container));
		$this->assertSame([PosAccessPolicy::class, ContainerInterface::class, IAppConfig::class, LoggerInterface::class], $asked);

		$asked = [];
		$this->assertInstanceOf(PosRefundManagerGuard::class, $this->factories[PosRefundManagerGuard::class]($container));
		$this->assertSame([PosAccessPolicy::class, ContainerInterface::class, IAppConfig::class, LoggerInterface::class], $asked);
	}//end testTheContainerAwareGuardsResolveTheirCollaborators()
}//end class
