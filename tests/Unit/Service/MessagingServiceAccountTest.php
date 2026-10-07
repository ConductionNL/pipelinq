<?php

/**
 * Tests for MessagingServiceAccount.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use OCA\Pipelinq\Tests\Unit\Support\FakeMessagingAccount;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The account a provider webhook writes as, after its signature checked out.
 */
class MessagingServiceAccountTest extends TestCase {
	/**
	 * Picking an account puts it in the messaging group, not the portal one.
	 *
	 * @return void
	 */
	public function testAssignEnrolsTheAccountInTheMessagingGroup(): void {
		$world = new FakeMessagingAccount($this);
		$world->user(uid: 'sms-bot');

		$status = $world->account->assign(userId: 'sms-bot');

		$this->assertSame(['userId' => 'sms-bot', 'usable' => true, 'reason' => null], $status);
		$this->assertSame('pipelinq-messaging-service', MessagingServiceAccount::GROUP);
		$this->assertSame(['sms-bot'], $world->members);
		$this->assertSame('sms-bot', $world->config[MessagingServiceAccount::CONFIG_KEY]);
	}//end testAssignEnrolsTheAccountInTheMessagingGroup()

	/**
	 * An unknown or disabled account is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAssignRefusesAnUnknownOrDisabledAccount(): void {
		$world = new FakeMessagingAccount($this);
		$world->user(uid: 'off', enabled: false);

		foreach (['nobody', 'off', ''] as $uid) {
			try {
				$world->account->assign(userId: $uid);
				$this->fail('Assigning "'.$uid.'" must be refused.');
			} catch (InvalidArgumentException $e) {
				$this->assertArrayNotHasKey(MessagingServiceAccount::CONFIG_KEY, $world->config);
			}
		}
	}//end testAssignRefusesAnUnknownOrDisabledAccount()

	/**
	 * runAs() acts as the account and restores the caller, also on a throw.
	 *
	 * @return void
	 */
	public function testRunAsActsAsTheAccountAndRestoresTheCaller(): void {
		$world = (new FakeMessagingAccount($this))->usable();

		$this->assertSame(FakeMessagingAccount::UID, $world->account->runAs(fn (): ?string => $world->actingUid()));
		$this->assertNull($world->actingUid());

		try {
			$world->account->runAs(
				static function (): void {
					throw new RuntimeException('boom');
				}
			);
			$this->fail('The throw must reach the caller.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertNull($world->actingUid());
	}//end testRunAsActsAsTheAccountAndRestoresTheCaller()

	/**
	 * Without a usable account nothing runs, and the refusal is a 503 so the
	 * provider retries.
	 *
	 * @return void
	 */
	public function testRunAsRefusesWith503AndDoesNotRun(): void {
		foreach (['unset', 'disabled', 'not-in-group'] as $case) {
			$world = new FakeMessagingAccount($this);
			if ($case === 'disabled') {
				$world->user(uid: FakeMessagingAccount::UID, enabled: false);
				$world->members = [FakeMessagingAccount::UID];
				$world->config[MessagingServiceAccount::CONFIG_KEY] = FakeMessagingAccount::UID;
			}

			if ($case === 'not-in-group') {
				$world->user(uid: FakeMessagingAccount::UID);
				$world->config[MessagingServiceAccount::CONFIG_KEY] = FakeMessagingAccount::UID;
			}

			$ran = false;
			try {
				$world->account->runAs(
					function () use (&$ran): void {
						$ran = true;
					}
				);
				$this->fail('A '.$case.' account must refuse.');
			} catch (ServiceAccountUnavailableException $e) {
				$this->assertSame(503, $e->getStatus());
			}

			$this->assertFalse($ran, $case);
			$this->assertNull($world->actingUid(), $case);
		}//end foreach
	}//end testRunAsRefusesWith503AndDoesNotRun()
}//end class
