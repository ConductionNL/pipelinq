<?php

/**
 * Golden test of the group-based access policies.
 *
 * Every decision of BillingHandoffAccessPolicy, PosAccessPolicy and
 * ForecastAccessPolicy is called for an empty user, an administrator, a group
 * member and an outsider, over a configured, an explicitly empty and an unset
 * group setting, and pins both the answer and the exact sequence of admin,
 * config and group lookups it took.
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

use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins every allowed and refused outcome, and the lookups behind it.
 */
final class GroupAccessPoliciesGoldenTest extends TestCase {
	/**
	 * The golden file.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__.'/Fixtures/group-access-policies.json';

	/**
	 * The lookups of the current call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every decision answers and looks up exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryDecisionAnswersAndLooksUpAsRecorded(): void {
		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $this->decisions());
	}//end testEveryDecisionAnswersAndLooksUpAsRecorded()

	/**
	 * Run every decision over every user and setting.
	 *
	 * @return array<string, array<string, mixed>> Outcome and lookups per case.
	 */
	private function decisions(): array {
		$decisions = [
			'billing isManager' => static fn (object $policy, string $uid): bool => $policy->isManager($uid),
			'pos isPosUser' => static fn (object $policy, string $uid): bool => $policy->isPosUser($uid),
			'pos isManager' => static fn (object $policy, string $uid): bool => $policy->isManager($uid),
			'pos canAccessTransaction, no cashier' => static fn (object $policy, string $uid): bool => $policy->canAccessTransaction([], $uid),
			'pos canAccessTransaction, own sale' => static fn (object $policy, string $uid): bool => $policy->canAccessTransaction(['cashier' => $uid], $uid),
			'pos canAccessTransaction, other cashier' => static fn (object $policy, string $uid): bool => $policy->canAccessTransaction(['cashier' => 'someone'], $uid),
			'forecast isManager' => static fn (object $policy, string $uid): bool => $policy->isManager($uid),
			'forecast canRead rep own' => static fn (object $policy, string $uid): bool => $policy->canRead($uid, 'rep', $uid),
			'forecast canRead rep other' => static fn (object $policy, string $uid): bool => $policy->canRead($uid, 'rep', 'someone'),
			'forecast canRead rep no owner' => static fn (object $policy, string $uid): bool => $policy->canRead($uid, 'rep', null),
			'forecast canRead team own' => static fn (object $policy, string $uid): bool => $policy->canRead($uid, 'team', $uid),
			'forecast canOverride team' => static fn (object $policy, string $uid): bool => $policy->canOverride($uid, 'team'),
			'forecast canOverride company' => static fn (object $policy, string $uid): bool => $policy->canOverride($uid, 'company'),
			'forecast canSetQuota division' => static fn (object $policy, string $uid): bool => $policy->canSetQuota($uid, 'division'),
			'forecast canSetQuota company' => static fn (object $policy, string $uid): bool => $policy->canSetQuota($uid, 'company'),
		];
		$settings = ['configured' => 'grp', 'explicitly empty' => '', 'unset' => null];
		$seen = [];
		foreach ($decisions as $name => $decide) {
			foreach ($settings as $settingName => $setting) {
				foreach (['', 'admin', 'member', 'outsider'] as $uid) {
					$this->calls = [];
					$policy = $this->policy(name: $name, setting: $setting);
					$seen[$name.' / '.$settingName.' / '.($uid === '' ? '(empty)' : $uid)] = [
						'answer' => $decide($policy, $uid),
						'lookups' => $this->calls,
					];
				}
			}
		}

		return $seen;
	}//end decisions()

	/**
	 * The policy a decision belongs to, over recording collaborators.
	 *
	 * @param string      $name    The decision name.
	 * @param string|null $setting Every group setting's value, or null when unset.
	 *
	 * @return object The policy.
	 */
	private function policy(string $name, ?string $setting): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($setting): string {
				$this->calls[] = ['getValueString', $app, $key, $default];
				return ($setting ?? $default);
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->calls[] = ['isAdmin', $uid];
				return $uid === 'admin';
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group): bool {
				$this->calls[] = ['isInGroup', $uid, $group];
				return $uid === 'member' && in_array($group, ['grp', 'pos'], true);
			}
		);

		return match (strtok($name, ' ')) {
			'billing' => new BillingHandoffAccessPolicy($appConfig, $groups),
			'pos' => new PosAccessPolicy($appConfig, $groups),
			default => new ForecastAccessPolicy($appConfig, $groups),
		};
	}//end policy()
}//end class
