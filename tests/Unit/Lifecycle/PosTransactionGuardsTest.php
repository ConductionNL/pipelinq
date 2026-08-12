<?php

/**
 * Unit tests for the posTransaction lifecycle guards.
 *
 * Proves the IDOR is closed: PosTransactionAccessGuard (settle/park/resume) and
 * PosTransactionConfirmGuard (confirm) DENY a non-owner, non-group, non-admin
 * caller and ALLOW the owning cashier / POS-group member / admin;
 * PosTransactionConfirmGuard additionally denies an empty cart; and
 * PosTransactionRefundGuard is manager-only. These guards run inside OR's
 * TransitionEngine, so a denial blocks the transition regardless of which
 * controller invoked it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A minimal ObjectService fake answering findAll for the line-count check.
 */
class GuardFakeObjectService {
	/** @var array<int, array<string, mixed>> */
	public array $lines = [];

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		return $this->lines;
	}
}

/**
 * Tests for the posTransaction lifecycle guards.
 */
class PosTransactionGuardsTest extends TestCase {
	/**
	 * Build a PosAccessPolicy with explicit admin / group behaviour.
	 *
	 * @param array<string, bool> $admins Map uid => isAdmin.
	 * @param array<string, array<int, string>> $groups Map uid => groups.
	 *
	 * @return PosAccessPolicy The policy.
	 */
	private function policy(array $admins, array $groups): PosAccessPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $a, string $k, string $d = ''): string => $d
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $u): bool => ($admins[$u] ?? false));
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $u, string $g): bool => in_array($g, ($groups[$u] ?? []), true)
		);

		return new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);
	}//end policy()

	/**
	 * AccessGuard denies a non-owner non-group non-admin user — IDOR closed.
	 *
	 * @return void
	 */
	public function testAccessGuardDeniesNonOwner(): void {
		$guard = new PosTransactionAccessGuard(policy: $this->policy(['bob' => false], ['bob' => []]));

		$result = $guard->check(['cashier' => 'alice'], 'settle', 'bob');

		$this->assertFalse($result->isAllowed());
		$this->assertNotEmpty($result->getMessage());
	}//end testAccessGuardDeniesNonOwner()

	/**
	 * AccessGuard allows the owning cashier, a POS-group member, and an admin.
	 *
	 * @return void
	 */
	public function testAccessGuardAllowsOwnerGroupAndAdmin(): void {
		$owner = new PosTransactionAccessGuard(policy: $this->policy([], []));
		$this->assertTrue($owner->check(['cashier' => 'alice'], 'park', 'alice')->isAllowed());

		$group = new PosTransactionAccessGuard(policy: $this->policy(['carol' => false], ['carol' => ['pos']]));
		$this->assertTrue($group->check(['cashier' => 'alice'], 'resume', 'carol')->isAllowed());

		$admin = new PosTransactionAccessGuard(policy: $this->policy(['root' => true], []));
		$this->assertTrue($admin->check(['cashier' => 'alice'], 'settle', 'root')->isAllowed());
	}//end testAccessGuardAllowsOwnerGroupAndAdmin()

	/**
	 * ConfirmGuard denies a non-owner even when the cart is non-empty (IDOR).
	 *
	 * @return void
	 */
	public function testConfirmGuardDeniesNonOwner(): void {
		$guard = $this->confirmGuard(policy: $this->policy(['bob' => false], ['bob' => []]), lines: [['id' => 'l1']]);

		$result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'bob');

		$this->assertFalse($result->isAllowed());
	}//end testConfirmGuardDeniesNonOwner()

	/**
	 * ConfirmGuard denies an empty cart for an authorised owner.
	 *
	 * @return void
	 */
	public function testConfirmGuardDeniesEmptyCart(): void {
		$guard = $this->confirmGuard(policy: $this->policy([], []), lines: []);

		$result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('artikel', (string)$result->getMessage());
	}//end testConfirmGuardDeniesEmptyCart()

	/**
	 * ConfirmGuard allows an authorised owner with a non-empty cart.
	 *
	 * @return void
	 */
	public function testConfirmGuardAllowsOwnerWithCart(): void {
		$guard = $this->confirmGuard(policy: $this->policy([], []), lines: [['id' => 'l1']]);

		$result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertTrue($result->isAllowed());
	}//end testConfirmGuardAllowsOwnerWithCart()

	/**
	 * RefundGuard is manager-only: a non-manager is denied, an admin allowed.
	 *
	 * @return void
	 */
	public function testRefundGuardManagerOnly(): void {
		$denied = new PosTransactionRefundGuard(policy: $this->policy(['clerk' => false], ['clerk' => []]));
		$this->assertFalse($denied->check(['cashier' => 'alice'], 'refund', 'clerk')->isAllowed());

		$allowed = new PosTransactionRefundGuard(policy: $this->policy(['root' => true], []));
		$this->assertTrue($allowed->check(['cashier' => 'alice'], 'refund', 'root')->isAllowed());
	}//end testRefundGuardManagerOnly()

	/**
	 * Build a PosTransactionConfirmGuard with a fake ObjectService returning the
	 * given lines.
	 *
	 * @param PosAccessPolicy $policy The access policy.
	 * @param array<int, array<string, mixed>> $lines The line rows to return.
	 *
	 * @return PosTransactionConfirmGuard The guard.
	 */
	private function confirmGuard(PosAccessPolicy $policy, array $lines): PosTransactionConfirmGuard {
		$objects = new GuardFakeObjectService();
		$objects->lines = $lines;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $a, string $k, string $d = ''): string {
				if ($k === 'register') {
					return 'reg';
				}
				if ($k === 'posTransactionLine_schema') {
					return 'line-schema';
				}
				return $d;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($objects) {
			if ($id === 'OCA\OpenRegister\Service\ObjectService') {
				return $objects;
			}
			throw new \RuntimeException('unknown service ' . $id);
		});

		return new PosTransactionConfirmGuard(
			policy: $policy,
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end confirmGuard()
}//end class
