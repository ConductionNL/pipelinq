<?php

/**
 * Unit tests for PortalAuthService — login, lockout, closed-account, MFA.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the portal login authority.
 */
class PortalAuthServiceTest extends TestCase {
	/**
	 * Mutable "now".
	 *
	 * @var int
	 */
	private int $now = 5000000;

	/**
	 * The fake repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $repository;

	/**
	 * The hasher (a real-ish fake: hash = "h:" . password).
	 *
	 * @var IHasher
	 */
	private IHasher $hasher;

	/**
	 * The tenant service mock.
	 *
	 * @var PortalTenantService
	 */
	private $tenant;

	/**
	 * The auth service under test.
	 *
	 * @var PortalAuthService
	 */
	private PortalAuthService $auth;

	/**
	 * Set up the auth service with deterministic collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository();

		$this->hasher = $this->createMock(IHasher::class);
		$this->hasher->method('hash')->willReturnCallback(static fn (string $m): string => 'h:' . $m);
		$this->hasher->method('verify')->willReturnCallback(
			static fn (string $m, string $h): bool => $h === 'h:' . $m
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $l): string => str_repeat('t', $l));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			fn (): \DateTime => (new \DateTime())->setTimestamp($this->now)
		);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$tokens = new PortalTokenService($random, $time);
		$sessions = new PortalSessionManager($this->repository, $tokens, $time);
		$mfa = $this->createMock(PortalMfaService::class);
		$mfa->method('verifyCode')->willReturnCallback(
			static fn (?string $secret, ?string $code): bool => $code === '123456'
		);
		$audit = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);

		$this->tenant = $this->createMock(PortalTenantService::class);
		$this->tenant->method('sessionTtlHours')->willReturn(8);
		$this->tenant->method('mfaEnforced')->willReturn(false);

		$this->auth = new PortalAuthService($this->repository,
			$this->hasher,
			$sessions,
			$mfa,
			$audit,
			$this->tenant,
			$time
		);
	}//end setUp()

	/**
	 * Seed an account.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed> The seeded account.
	 */
	private function seedAccount(array $overrides = []): array {
		return $this->repository->seed('crmPortalAccount', 'acc-1', array_merge([
			'email' => 'jan@pietersen.nl',
			'tenantId' => 'tenant-a',
			'passwordHash' => 'h:geheimwachtwoord123',
			'status' => 'active',
			'failedLoginAttempts' => 0,
			'mfaEnabled' => false,
		], $overrides));
	}//end seedAccount()

	/**
	 * A correct password yields an authenticated session token.
	 *
	 * @return void
	 */
	public function testSuccessfulLogin(): void {
		$this->seedAccount();
		$result = $this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', null, 'ip', 'ua');

		$this->assertSame('authenticated', $result['status']);
		$this->assertArrayHasKey('token', $result);
		$this->assertNotEmpty($result['token']);
	}//end testSuccessfulLogin()

	/**
	 * A wrong password is rejected with 401 invalidCredentials.
	 *
	 * @return void
	 */
	public function testWrongPasswordRejected(): void {
		$this->seedAccount();
		try {
			$this->auth->login('jan@pietersen.nl', 'wrong', 'tenant-a', null, 'ip', 'ua');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $e->getStatus());
			$this->assertSame('invalidCredentials', $e->getErrorCode());
		}
	}//end testWrongPasswordRejected()

	/**
	 * An unknown account gives the SAME error as a wrong password (no enumeration).
	 *
	 * @return void
	 */
	public function testUnknownAccountUniformError(): void {
		try {
			$this->auth->login('nobody@nowhere.nl', 'whatever1234', 'tenant-a', null, 'ip', 'ua');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame('invalidCredentials', $e->getErrorCode());
		}
	}//end testUnknownAccountUniformError()

	/**
	 * A closed account is rejected with accountClosed even with the right password.
	 *
	 * @return void
	 */
	public function testClosedAccountRejected(): void {
		$this->seedAccount(['status' => 'closed']);
		try {
			$this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', null, 'ip', 'ua');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $e->getStatus());
			$this->assertSame('accountClosed', $e->getErrorCode());
		}
	}//end testClosedAccountRejected()

	/**
	 * After 5 failed attempts the 6th is locked out with 429.
	 *
	 * @return void
	 */
	public function testRateLimitLockout(): void {
		$this->seedAccount();
		for ($i = 0; $i < 5; $i++) {
			try {
				$this->auth->login('jan@pietersen.nl', 'wrong', 'tenant-a', null, 'ip', 'ua');
			} catch (PortalException $e) {
				// expected invalidCredentials
			}
		}

		try {
			$this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', null, 'ip', 'ua');
			$this->fail('Expected lockout');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $e->getStatus());
			$this->assertSame('rateLimited', $e->getErrorCode());
		}
	}//end testRateLimitLockout()

	/**
	 * A successful login resets the failure counter.
	 *
	 * @return void
	 */
	public function testSuccessResetsFailureCounter(): void {
		$this->seedAccount(['failedLoginAttempts' => 3]);
		$this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', null, 'ip', 'ua');

		$account = $this->repository->find('crmPortalAccount', 'acc-1');
		$this->assertSame(0, $account['failedLoginAttempts']);
	}//end testSuccessResetsFailureCounter()

	/**
	 * With MFA enabled and no code, login returns mfa-required (no token).
	 *
	 * @return void
	 */
	public function testMfaRequiredWithoutCode(): void {
		$this->seedAccount(['mfaEnabled' => true, 'mfaSecret' => 'enc-secret']);
		$result = $this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', null, 'ip', 'ua');

		$this->assertSame('mfa-required', $result['status']);
		$this->assertTrue($result['mfaRequired']);
		$this->assertArrayNotHasKey('token', $result);
	}//end testMfaRequiredWithoutCode()

	/**
	 * With MFA enabled and the correct code, login completes with a token.
	 *
	 * @return void
	 */
	public function testMfaSuccess(): void {
		$this->seedAccount(['mfaEnabled' => true, 'mfaSecret' => 'enc-secret']);
		$result = $this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', '123456', 'ip', 'ua');

		$this->assertSame('authenticated', $result['status']);
		$this->assertNotEmpty($result['token']);
	}//end testMfaSuccess()

	/**
	 * With MFA enabled and a wrong code, login is rejected.
	 *
	 * @return void
	 */
	public function testMfaWrongCodeRejected(): void {
		$this->seedAccount(['mfaEnabled' => true, 'mfaSecret' => 'enc-secret']);
		try {
			$this->auth->login('jan@pietersen.nl', 'geheimwachtwoord123', 'tenant-a', '000000', 'ip', 'ua');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame('invalidMfaCode', $e->getErrorCode());
		}
	}//end testMfaWrongCodeRejected()
}//end class
