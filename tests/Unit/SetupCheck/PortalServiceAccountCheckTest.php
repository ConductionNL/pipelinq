<?php

/**
 * Tests for PortalServiceAccountCheck.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\SetupCheck
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\SetupCheck;

use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCA\Pipelinq\SetupCheck\PortalServiceAccountCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The admin overview warns until a usable portal service account is chosen.
 */
class PortalServiceAccountCheckTest extends TestCase {

	/**
	 * Run the check over a given status.
	 *
	 * @param array{userId: string, usable: bool, reason: string|null} $status The status.
	 *
	 * @return SetupResult The result.
	 */
	private function check(array $status): SetupResult {
		$account = $this->createMock(PortalServiceAccount::class);
		$account->method('status')->willReturn($status);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return (new PortalServiceAccountCheck($account, $l10n))->run();
	}//end check()

	/**
	 * An unset account is a warning.
	 *
	 * @return void
	 */
	public function testAnUnsetAccountWarns(): void {
		$result = $this->check(['userId' => '', 'usable' => false, 'reason' => PortalServiceAccount::REASON_UNSET]);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('No account is chosen.', (string)$result->getDescription());
	}//end testAnUnsetAccountWarns()

	/**
	 * A disabled account is a warning that says so.
	 *
	 * @return void
	 */
	public function testADisabledAccountWarns(): void {
		$result = $this->check(['userId' => 'portal-service', 'usable' => false, 'reason' => PortalServiceAccount::REASON_DISABLED]);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('disabled', (string)$result->getDescription());
	}//end testADisabledAccountWarns()

	/**
	 * A usable account passes and is named.
	 *
	 * @return void
	 */
	public function testAUsableAccountPasses(): void {
		$result = $this->check(['userId' => 'portal-service', 'usable' => true, 'reason' => null]);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringContainsString('portal-service', (string)$result->getDescription());
	}//end testAUsableAccountPasses()
}//end class
