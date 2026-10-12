<?php

/**
 * The background service account: who the call mirror writes as.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCA\Pipelinq\Service\ServiceAccount;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use OCA\Pipelinq\Tests\Unit\Support\FakeBackgroundAccount;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The real account class over a fake Nextcloud.
 *
 * @covers \OCA\Pipelinq\Service\BackgroundServiceAccount
 * @covers \OCA\Pipelinq\Service\ServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccountUnavailableException
 */
class BackgroundServiceAccountTest extends TestCase {
	/**
	 * Picking an account puts it in the background group and stores the choice.
	 *
	 * @return void
	 */
	public function testAssignEnrolsTheAccountInTheBackgroundGroup(): void {
		$world = new FakeBackgroundAccount($this);
		$world->user(uid: 'call-bot');

		$status = $world->account->assign(userId: 'call-bot');

		$this->assertSame(['userId' => 'call-bot', 'usable' => true, 'reason' => null], $status);
		$this->assertSame('pipelinq-background-service', $world->account->group());
		$this->assertSame(['call-bot'], $world->members);
		$this->assertSame('call-bot', $world->config[BackgroundServiceAccount::CONFIG_KEY]);
	}//end testAssignEnrolsTheAccountInTheBackgroundGroup()

	/**
	 * An unknown, disabled or empty uid is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAssignRefusesAnUnknownOrDisabledAccount(): void {
		$world = new FakeBackgroundAccount($this);
		$world->user(uid: 'off', enabled: false);

		foreach (['nobody', 'off', ''] as $uid) {
			try {
				$world->account->assign(userId: $uid);
				$this->fail('Assigning "' . $uid . '" must be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertArrayNotHasKey(BackgroundServiceAccount::CONFIG_KEY, $world->config);
			}
		}
	}//end testAssignRefusesAnUnknownOrDisabledAccount()

	/**
	 * Work runs as the account, and whoever was acting before is back afterwards, also after a failure.
	 *
	 * @return void
	 */
	public function testRunAsActsAsTheAccountAndRestoresTheCaller(): void {
		$world = (new FakeBackgroundAccount($this))->usable();

		$this->assertSame(FakeBackgroundAccount::UID, $world->account->runAs(fn (): ?string => $world->actingUid()));
		$this->assertNull($world->actingUid());

		try {
			$world->account->runAs(
				static function (): void {
					throw new RuntimeException('the work failed');
				}
			);
			$this->fail('The failure must reach the caller.');
		} catch (RuntimeException $e) {
			$this->assertNull($world->actingUid());
		}
	}//end testRunAsActsAsTheAccountAndRestoresTheCaller()

	/**
	 * Why an account cannot be used, for each way it can go wrong.
	 *
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public static function unusable(): array {
		return [
			'none picked' => [
				static function (FakeBackgroundAccount $world): void {
				},
				ServiceAccount::REASON_UNSET,
			],
			'the uid names nobody' => [
				static function (FakeBackgroundAccount $world): void {
					$world->config[BackgroundServiceAccount::CONFIG_KEY] = 'gone';
				},
				ServiceAccount::REASON_UNKNOWN,
			],
			'the account is disabled' => [
				static function (FakeBackgroundAccount $world): void {
					$world->usable();
					$world->user(uid: FakeBackgroundAccount::UID, enabled: false);
				},
				ServiceAccount::REASON_DISABLED,
			],
			'the account left the group' => [
				static function (FakeBackgroundAccount $world): void {
					$world->usable();
					$world->members = [];
				},
				ServiceAccount::REASON_NOT_IN_GROUP,
			],
		];
	}//end unusable()

	/**
	 * Without a usable account the work does not run, nobody is impersonated, and the log says why.
	 *
	 * @param callable $arrange Breaks the world one way.
	 * @param string $reason The reason expected in the log and the status.
	 *
	 * @return void
	 *
	 * @dataProvider unusable
	 */
	public function testAnUnusableAccountRefusesAndSaysSoInTheLog(callable $arrange, string $reason): void {
		$world = new FakeBackgroundAccount($this);
		$arrange($world);
		$ran = false;

		try {
			$world->account->runAs(
				function () use (&$ran): void {
					$ran = true;
				}
			);
			$this->fail('The work must be refused.');
		} catch (ServiceAccountUnavailableException $e) {
			$this->assertSame(503, $e->getStatus());
		}

		$this->assertFalse($ran);
		$this->assertNull($world->actingUid());
		$this->assertSame($reason, $world->account->status()['reason']);
		$this->assertCount(1, $world->logged);
		[$level, $message, $context] = $world->logged[0];
		$this->assertSame('warning', $level);
		$this->assertStringContainsString('no usable background service account', $message);
		$this->assertSame($reason, $context['reason']);
		$this->assertSame(BackgroundServiceAccount::GROUP, $context['group']);
	}//end testAnUnusableAccountRefusesAndSaysSoInTheLog()
}//end class
