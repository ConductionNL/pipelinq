<?php

/**
 * The administration overview says whether the background service account can be used.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\SetupCheck;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCA\Pipelinq\SetupCheck\BackgroundServiceAccountCheck;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The check over a given account status, and its registration.
 *
 * @covers \OCA\Pipelinq\SetupCheck\BackgroundServiceAccountCheck
 * @covers \OCA\Pipelinq\SetupCheck\ServiceAccountCheck
 * @uses   \OCA\Pipelinq\AppInfo\Application
 */
class BackgroundServiceAccountCheckTest extends TestCase {
	/**
	 * Build the check over a given account status.
	 *
	 * @param array{userId: string, usable: bool, reason: string|null} $status The status.
	 *
	 * @return BackgroundServiceAccountCheck The check.
	 */
	private function check(array $status): BackgroundServiceAccountCheck {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('status')->willReturn($status);
		$account->method('group')->willReturn(BackgroundServiceAccount::GROUP);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new BackgroundServiceAccountCheck($account, $l10n);
	}//end check()

	/**
	 * The application registers the check: a check nobody registers never shows.
	 *
	 * @return void
	 */
	public function testTheApplicationRegistersTheCheck(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerSetupCheck')->willReturnCallback(
			function (string $class) use (&$registered): void {
				$registered[] = ltrim($class, '\\');
			}
		);

		(new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

		$this->assertContains(BackgroundServiceAccountCheck::class, $registered);
	}//end testTheApplicationRegistersTheCheck()

	/**
	 * No account picked: a warning that says calls are not logged.
	 *
	 * @return void
	 */
	public function testAnUnsetAccountWarns(): void {
		$check = $this->check(['userId' => '', 'usable' => false, 'reason' => BackgroundServiceAccount::REASON_UNSET]);
		$result = $check->run();

		$this->assertSame('Pipelinq background service account', $check->getName());
		$this->assertSame('system', $check->getCategory());
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('No account is chosen.', (string)$result->getDescription());
		$this->assertStringContainsString('incoming calls are not logged', (string)$result->getDescription());
	}//end testAnUnsetAccountWarns()

	/**
	 * An account outside the group warns and names the group.
	 *
	 * @return void
	 */
	public function testAnAccountOutsideTheGroupWarns(): void {
		$result = $this->check(['userId' => 'call-bot', 'usable' => false, 'reason' => BackgroundServiceAccount::REASON_NOT_IN_GROUP])->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString(BackgroundServiceAccount::GROUP, (string)$result->getDescription());
	}//end testAnAccountOutsideTheGroupWarns()

	/**
	 * A usable account passes and is named.
	 *
	 * @return void
	 */
	public function testAUsableAccountPasses(): void {
		$result = $this->check(['userId' => 'call-bot', 'usable' => true, 'reason' => null])->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertSame('Incoming calls are logged as call-bot.', (string)$result->getDescription());
	}//end testAUsableAccountPasses()
}//end class
