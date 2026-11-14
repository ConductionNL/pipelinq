<?php

/**
 * PasswordResetService::resetPassword: allowed and refused.
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
use OCA\Pipelinq\Service\Portal\PasswordResetService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * A new password is set only with a strong password and the tenant's valid, unexpired token.
 */
class PasswordResetServiceTest extends TestCase {

	/** @var array<int, mixed> */
	private array $calls = [];

	private FakePortalObjectRepository $repository;

	/**
	 * The service over the fake repository and recording doubles.
	 *
	 * @return PasswordResetService
	 */
	private function service(): PasswordResetService {
		$this->repository = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-05-01T12:00:00+00:00'));
		$tokens = new PortalTokenService($this->createMock(ISecureRandom::class), $time);
		$hasher = $this->createMock(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $plain): string => 'H(' . $plain . ')');
		$sessions = $this->createMock(PortalSessionManager::class);
		$sessions->method('revokeAllForAccount')->willReturnCallback(
			function (string $accountId, string $reason): int {
				$this->calls[] = ['revoke', $accountId, $reason];
				return 2;
			}
		);
		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = ['audit', $args];
			}
		);

		return new PasswordResetService($this->repository, $tokens, $hasher, $this->createMock(PortalMailService::class), $sessions, $audit, $this->createMock(IL10N::class));
	}//end service()

	/**
	 * The stored account a reset is asked for.
	 *
	 * @param string $expires When its reset token expires.
	 *
	 * @return array<string, mixed>
	 */
	private function seeded(string $expires): array {
		return $this->repository->seed('crmPortalAccount', 'acc-1', [
			'tenantId' => 't-1',
			'passwordHash' => 'old',
			'passwordResetTokenHash' => hash('sha256', 'tok'),
			'passwordResetExpiresAt' => $expires,
			'failedLoginAttempts' => 4,
			'lockedUntil' => '2026-05-01T13:00:00+00:00',
		]);
	}//end seeded()

	/**
	 * Allowed: the password is set, the token cleared, the lock lifted, every
	 * session revoked and the reset audited, in that order.
	 *
	 * @return void
	 */
	public function testAValidResetSetsThePassword(): void {
		$service = $this->service();
		$this->seeded(expires: '2026-05-01T12:30:00+00:00');

		$service->resetPassword(token: 'tok', newPassword: 'long enough', tenantId: 't-1');

		$this->assertSame(
			['tenantId' => 't-1', 'passwordHash' => 'H(long enough)', 'passwordResetTokenHash' => null, 'passwordResetExpiresAt' => null, 'failedLoginAttempts' => 0, 'lockedUntil' => null, '@self' => ['id' => 'acc-1']],
			$this->repository->find('crmPortalAccount', 'acc-1')
		);
		$this->assertSame([['revoke', 'acc-1', 'password-reset'], ['audit', ['acc-1', 't-1', 'password-reset', 'success', []]]], $this->calls);
	}//end testAValidResetSetsThePassword()

	/**
	 * Refused: a password under ten characters (before any lookup), a wrong
	 * token, another tenant's token, an expired token; nothing changes.
	 *
	 * @return void
	 */
	public function testEveryRefusal(): void {
		$cases = [
			['tok', 'short', 't-1', '2026-05-01T12:30:00+00:00', [422, 'weakPassword', 'Wachtwoord moet minimaal 10 tekens bevatten.']],
			['other', 'long enough', 't-1', '2026-05-01T12:30:00+00:00', [400, 'invalidToken', 'Deze herstel-link is ongeldig of verlopen.']],
			['tok', 'long enough', 't-2', '2026-05-01T12:30:00+00:00', [400, 'invalidToken', 'Deze herstel-link is ongeldig of verlopen.']],
			['tok', 'long enough', 't-1', '2026-05-01T11:59:00+00:00', [400, 'invalidToken', 'Deze herstel-link is ongeldig of verlopen.']],
			['tok', '123456789', 'nope', '2026-05-01T12:30:00+00:00', [422, 'weakPassword', 'Wachtwoord moet minimaal 10 tekens bevatten.']],
		];
		foreach ($cases as [$token, $password, $tenant, $expires, $refusal]) {
			$service = $this->service();
			$seeded = $this->seeded(expires: $expires);
			$this->calls = [];
			try {
				$service->resetPassword(token: $token, newPassword: $password, tenantId: $tenant);
				$this->fail('the reset must be refused');
			} catch (PortalException $e) {
				$this->assertSame($refusal, [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]);
			}

			$this->assertSame($seeded, $this->repository->find('crmPortalAccount', 'acc-1'));
			$this->assertSame([], $this->calls);
		}
	}//end testEveryRefusal()
}//end class
