<?php

/**
 * PortalAccountService::close: every way an account closure ends.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Pipelinq\Service\Portal\PortalAccountService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The closure is allowed only with a valid, unexpired token of the tenant.
 */
class PortalAccountCloseTest extends TestCase {

	/** @var array<int, mixed> */
	private array $calls = [];

	private FakePortalObjectRepository $repository;

	/**
	 * The service over a fake repository, a real token service at a fixed
	 * clock, and recording mail, session and audit doubles.
	 *
	 * @return PortalAccountService
	 */
	private function service(): PortalAccountService {
		$this->repository = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-05-01T12:00:00+00:00'));
		$tokens = new PortalTokenService($this->createMock(ISecureRandom::class), $time);

		$mail = $this->createMock(PortalMailService::class);
		$mail->method('send')->willReturnCallback(
			function (string $recipient, string $subject, string $body): bool {
				$this->calls[] = ['mail', $recipient, $subject, $body];
				return true;
			}
		);
		$sessions = $this->createMock(PortalSessionManager::class);
		$sessions->method('revokeAllForAccount')->willReturnCallback(
			function (string $accountId, string $reason): int {
				$this->calls[] = ['revoke', $accountId, $reason];
				return 1;
			}
		);
		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = ['audit', $args];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		return new PortalAccountService($this->repository, $tokens, $mail, $sessions, $audit, $l10n);
	}//end service()

	/**
	 * A closure with the right token closes the account, clears the token,
	 * keeps the contact link, revokes every session, mails and audits, in
	 * that order.
	 *
	 * @return void
	 */
	public function testAValidTokenClosesTheAccount(): void {
		$service = $this->service();
		$this->repository->seed('crmPortalAccount', 'acc-1', ['email' => 'jan@example.test', 'tenantId' => 't-1', 'status' => 'active', 'linkedContactId' => 'c-1', 'closeTokenHash' => hash('sha256', 'tok'), 'closeExpiresAt' => '2026-05-01T13:00:00+00:00']);

		$service->close(token: 'tok', tenantId: 't-1');

		$stored = $this->repository->find('crmPortalAccount', 'acc-1');
		$this->assertSame(['email' => 'jan@example.test', 'tenantId' => 't-1', 'status' => 'closed', 'linkedContactId' => 'c-1', 'closeTokenHash' => null, 'closeExpiresAt' => null, '@self' => ['id' => 'acc-1']], $stored);
		$this->assertSame(
			[
				['revoke', 'acc-1', 'account-closure'],
				['mail', 'jan@example.test', 'T:Your portal account has been closed', 'T:Your account is closed. You can no longer sign in.'],
				['audit', ['acc-1', 't-1', 'account-close', 'success', []]],
			],
			$this->calls
		);
	}//end testAValidTokenClosesTheAccount()

	/**
	 * A wrong token, another tenant's token, an expired token, and an empty
	 * token are all refused the same way, and nothing changes.
	 *
	 * @return void
	 */
	public function testEveryInvalidTokenIsRefusedAlike(): void {
		$cases = [
			['tok-other', 't-1', '2026-05-01T13:00:00+00:00'],
			['tok', 't-2', '2026-05-01T13:00:00+00:00'],
			['tok', 't-1', '2026-05-01T11:59:59+00:00'],
			['tok', 't-1', null],
			['', 't-1', '2026-05-01T13:00:00+00:00'],
		];
		foreach ($cases as [$token, $tenant, $expires]) {
			$service = $this->service();
			$seeded = $this->repository->seed('crmPortalAccount', 'acc-1', ['email' => 'jan@example.test', 'tenantId' => 't-1', 'status' => 'active', 'closeTokenHash' => hash('sha256', 'tok'), 'closeExpiresAt' => $expires]);
			$this->calls = [];
			try {
				$service->close(token: $token, tenantId: $tenant);
				$this->fail('a closure with an invalid token must be refused');
			} catch (PortalException $e) {
				$this->assertSame([400, 'invalidToken', 'Deze bevestigings-link is ongeldig of verlopen.'], [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]);
			}

			$this->assertSame($seeded, $this->repository->find('crmPortalAccount', 'acc-1'));
			$this->assertSame([], $this->calls);
		}
	}//end testEveryInvalidTokenIsRefusedAlike()
}//end class
