<?php

/**
 * Tests for MessagingServiceAccountCheck.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\SetupCheck;

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\SetupCheck\MessagingServiceAccountCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The administration overview warns while the SMS and WhatsApp webhooks have no account to write as.
 */
class MessagingServiceAccountCheckTest extends TestCase {
	/**
	 * Run the check over a given account status.
	 *
	 * @param array{userId: string, usable: bool, reason: string|null} $status The status.
	 *
	 * @return SetupResult The result.
	 */
	private function check(array $status): SetupResult {
		$account = $this->createMock(MessagingServiceAccount::class);
		$account->method('status')->willReturn($status);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return (new MessagingServiceAccountCheck($account, $l10n))->run();
	}//end check()

	/**
	 * An unset account warns and says what stops working.
	 *
	 * @return void
	 */
	public function testAnUnsetAccountWarns(): void {
		$result = $this->check(['userId' => '', 'usable' => false, 'reason' => MessagingServiceAccount::REASON_UNSET]);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('No account is chosen.', (string)$result->getDescription());
		$this->assertStringContainsString('STOP', (string)$result->getDescription());
	}//end testAnUnsetAccountWarns()

	/**
	 * An account outside the messaging group warns and names the group.
	 *
	 * @return void
	 */
	public function testAnAccountOutsideTheGroupWarns(): void {
		$result = $this->check(['userId' => 'sms-bot', 'usable' => false, 'reason' => MessagingServiceAccount::REASON_NOT_IN_GROUP]);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString(MessagingServiceAccount::GROUP, (string)$result->getDescription());
	}//end testAnAccountOutsideTheGroupWarns()

	/**
	 * A usable account passes and is named.
	 *
	 * @return void
	 */
	public function testAUsableAccountPasses(): void {
		$result = $this->check(['userId' => 'sms-bot', 'usable' => true, 'reason' => null]);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringContainsString('sms-bot', (string)$result->getDescription());
	}//end testAUsableAccountPasses()
}//end class
