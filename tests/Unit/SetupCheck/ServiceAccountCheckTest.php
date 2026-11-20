<?php

/**
 * Tests for the service account setup checks.
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

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCA\Pipelinq\Service\ServiceAccount;
use OCA\Pipelinq\SetupCheck\MessagingServiceAccountCheck;
use OCA\Pipelinq\SetupCheck\PortalServiceAccountCheck;
use OCA\Pipelinq\SetupCheck\ServiceAccountCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The exact wording, severity and category each service account check gives
 * for every state of its account.
 *
 * @covers \OCA\Pipelinq\SetupCheck\ServiceAccountCheck
 * @covers \OCA\Pipelinq\SetupCheck\PortalServiceAccountCheck
 * @covers \OCA\Pipelinq\SetupCheck\MessagingServiceAccountCheck
 */
class ServiceAccountCheckTest extends TestCase {

	private const PORTAL_CONSEQUENCE = 'Until you choose one in the Pipelinq settings, the customer portal cannot save anything: residents cannot log in or reset their password.';

	private const MESSAGING_CONSEQUENCE = 'Until you choose one in the Pipelinq settings, incoming SMS and WhatsApp messages are not saved, and a STOP reply is not recorded.';

	/**
	 * An IL10N that substitutes its parameters into the English text.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}//end l10n()

	/**
	 * Build one of the two checks over a mocked account.
	 *
	 * @param string $kind   'portal' or 'messaging'.
	 * @param array  $status The account status.
	 * @param string $group  The account's group.
	 *
	 * @return ServiceAccountCheck The check.
	 */
	private function check(string $kind, array $status, string $group='pipelinq-group'): ServiceAccountCheck {
		$class   = $kind === 'portal' ? PortalServiceAccount::class : MessagingServiceAccount::class;
		$account = $this->createMock($class);
		$account->expects($this->once())->method('status')->willReturn($status);
		$account->method('group')->willReturn($group);

		if ($kind === 'portal') {
			return new PortalServiceAccountCheck($account, $this->l10n());
		}

		return new MessagingServiceAccountCheck($account, $this->l10n());
	}//end check()

	/**
	 * Every refusal reason, for both checks.
	 *
	 * @return array<string, array{string, string|null, string}> Kind, reason and the full warning.
	 */
	public static function refusals(): array {
		$cases = [];
		foreach (['portal' => self::PORTAL_CONSEQUENCE, 'messaging' => self::MESSAGING_CONSEQUENCE] as $kind => $consequence) {
			$cases[$kind.' unset']        = [$kind, ServiceAccount::REASON_UNSET, 'No account is chosen. '.$consequence];
			$cases[$kind.' no reason']    = [$kind, null, 'No account is chosen. '.$consequence];
			$cases[$kind.' unknown']      = [$kind, ServiceAccount::REASON_UNKNOWN, 'The chosen account does not exist. '.$consequence];
			$cases[$kind.' disabled']     = [$kind, ServiceAccount::REASON_DISABLED, 'The chosen account is disabled. '.$consequence];
			$cases[$kind.' not in group'] = [$kind, ServiceAccount::REASON_NOT_IN_GROUP, 'The chosen account is not in the group pipelinq-group. '.$consequence];
		}

		return $cases;
	}//end refusals()

	/**
	 * An unusable account warns with the reason first and what stops working after it.
	 *
	 * @param string      $kind     Which check.
	 * @param string|null $reason   The account's refusal reason.
	 * @param string      $expected The full warning.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testAnUnusableAccountWarnsWithItsReason(string $kind, ?string $reason, string $expected): void {
		$result = $this->check(kind: $kind, status: ['userId' => 'svc', 'usable' => false, 'reason' => $reason])->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertSame($expected, $result->getDescription());
	}//end testAnUnusableAccountWarnsWithItsReason()

	/**
	 * Only the not-in-group reason asks the account for its group.
	 *
	 * @return void
	 */
	public function testTheGroupIsReadOnlyForTheNotInGroupReason(): void {
		$account = $this->createMock(PortalServiceAccount::class);
		$account->method('status')->willReturn(['userId' => 'svc', 'usable' => false, 'reason' => ServiceAccount::REASON_DISABLED]);
		$account->expects($this->never())->method('group');

		(new PortalServiceAccountCheck($account, $this->l10n()))->run();
	}//end testTheGroupIsReadOnlyForTheNotInGroupReason()

	/**
	 * A usable account passes, whatever reason is still set, and names the account.
	 *
	 * @return void
	 */
	public function testAUsableAccountPassesAndIsNamed(): void {
		$portal    = $this->check(kind: 'portal', status: ['userId' => 'portal-svc', 'usable' => true, 'reason' => ServiceAccount::REASON_DISABLED])->run();
		$messaging = $this->check(kind: 'messaging', status: ['userId' => 'sms-svc', 'usable' => true, 'reason' => null])->run();

		$this->assertSame(SetupResult::SUCCESS, $portal->getSeverity());
		$this->assertSame('Customer portal writes run as portal-svc.', $portal->getDescription());
		$this->assertSame(SetupResult::SUCCESS, $messaging->getSeverity());
		$this->assertSame('Incoming SMS and WhatsApp messages are saved as sms-svc.', $messaging->getDescription());
	}//end testAUsableAccountPassesAndIsNamed()

	/**
	 * Both checks sit under system, each under its own name.
	 *
	 * @return void
	 */
	public function testNameAndCategory(): void {
		$portal    = new PortalServiceAccountCheck($this->createMock(PortalServiceAccount::class), $this->l10n());
		$messaging = new MessagingServiceAccountCheck($this->createMock(MessagingServiceAccount::class), $this->l10n());

		$this->assertSame('Pipelinq customer portal service account', $portal->getName());
		$this->assertSame('system', $portal->getCategory());
		$this->assertSame('Pipelinq SMS and WhatsApp service account', $messaging->getName());
		$this->assertSame('system', $messaging->getCategory());
	}//end testNameAndCategory()
}//end class
