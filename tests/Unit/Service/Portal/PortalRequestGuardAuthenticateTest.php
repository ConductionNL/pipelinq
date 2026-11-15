<?php

/**
 * Unit tests for PortalRequestGuard authentication and request hashing.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCA\Pipelinq\Util\ContrastRatioCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Bearer-token authentication through the real session manager and tenant
 * service; only the object repository, clock and request are doubled.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalRequestGuard
 * @uses   \OCA\Pipelinq\Service\Portal\PortalSessionManager
 * @uses   \OCA\Pipelinq\Service\Portal\PortalTokenService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalTenantService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalException
 */
class PortalRequestGuardAuthenticateTest extends TestCase {
	/**
	 * Fixed "now" for the clock double.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * Repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private PortalObjectRepository&MockObject $repository;

	/**
	 * Stored sessions keyed by token hash.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sessions = [];

	/**
	 * Stored accounts keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $accounts = [];

	/**
	 * Guard under test.
	 *
	 * @var PortalRequestGuard
	 */
	private PortalRequestGuard $guard;

	/**
	 * Wire the guard with real services around a mocked repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->repository->method('findOneBy')->willReturnCallback(
			function (string $schema, array $filters): ?array {
				if ($schema === 'crmPortalSession') {
					return ($this->sessions[$filters['tokenHash']] ?? null);
				}

				return null;
			}
		);
		$this->repository->method('find')->willReturnCallback(
			function (string $schema, string $id): ?array {
				TestCase::assertSame('crmPortalAccount', $schema);
				return ($this->accounts[$id] ?? null);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): DateTime => (new DateTime())->setTimestamp(self::NOW)
		);

		$tokens   = new PortalTokenService($this->createMock(ISecureRandom::class), $time);
		$sessions = new PortalSessionManager($this->repository, $tokens, $time);
		$tenant   = new PortalTenantService($this->repository, new ContrastRatioCalculator());

		$this->guard = new PortalRequestGuard($this->repository, $sessions, $tenant);
	}//end setUp()

	/**
	 * Store a session for a plaintext token.
	 *
	 * @param string               $token   The plaintext token.
	 * @param array<string, mixed> $session The session fields.
	 *
	 * @return array<string, mixed> The stored session.
	 */
	private function session(string $token, array $session): array {
		$session += [
			'tenantId'  => 'default',
			'expiresAt' => date(DATE_ATOM, self::NOW + 3600),
		];
		$this->sessions[hash('sha256', $token)] = $session;
		return $session;
	}//end session()

	/**
	 * Build a request double.
	 *
	 * @param array<string, string> $headers The headers.
	 * @param string                $remote  The remote address.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $headers, string $remote = '10.0.0.1'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('');
		$request->method('getRemoteAddress')->willReturn($remote);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[$name] ?? '')
		);
		return $request;
	}//end request()

	/**
	 * Assert that authenticating the request is refused as unauthenticated.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return void
	 */
	private function assertUnauthenticated(IRequest $request): void {
		try {
			$this->guard->authenticate($request);
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(401, $e->getStatus());
			$this->assertSame('unauthenticated', $e->getErrorCode());
			$this->assertSame('Niet ingelogd.', $e->getMessage());
		}
	}//end assertUnauthenticated()

	/**
	 * A valid bearer token (any case of "Bearer") returns account, session and tenant.
	 *
	 * @return void
	 */
	public function testAuthenticateReturnsContext(): void {
		$session = $this->session('tok-1', ['accountId' => 'acc-1']);
		$this->accounts['acc-1'] = ['email' => 'a@b.test', 'status' => 'active'];

		$this->assertSame(
			[
				'account'   => ['email' => 'a@b.test', 'status' => 'active'],
				'accountId' => 'acc-1',
				'session'   => $session,
				'tenantId'  => 'default',
			],
			$this->guard->authenticate($this->request(['Authorization' => 'bEaReR   tok-1  ']))
		);
	}//end testAuthenticateReturnsContext()

	/**
	 * Missing, non-bearer and empty bearer headers are refused.
	 *
	 * @return void
	 */
	public function testAuthenticateRefusesMissingOrMalformedHeader(): void {
		$this->session('tok-1', ['accountId' => 'acc-1']);
		$this->accounts['acc-1'] = [];

		$this->assertUnauthenticated($this->request([]));
		$this->assertUnauthenticated($this->request(['Authorization' => 'Basic tok-1']));
		$this->assertUnauthenticated($this->request(['Authorization' => 'Bearer    ']));
		$this->assertUnauthenticated($this->request(['Authorization' => 'Bearer unknown']));
	}//end testAuthenticateRefusesMissingOrMalformedHeader()

	/**
	 * Revoked, MFA-pending, expired and other-tenant sessions are refused.
	 *
	 * @return void
	 */
	public function testAuthenticateRefusesUnusableSession(): void {
		$this->accounts['acc-1'] = [];
		$this->session('revoked', ['accountId' => 'acc-1', 'revoked' => true]);
		$this->session('pending', ['accountId' => 'acc-1', 'mfaPending' => true]);
		$this->session('expired', ['accountId' => 'acc-1', 'expiresAt' => date(DATE_ATOM, self::NOW - 1)]);
		$this->session('other', ['accountId' => 'acc-1', 'tenantId' => 'acme']);

		foreach (['revoked', 'pending', 'expired', 'other'] as $token) {
			$this->assertUnauthenticated($this->request(['Authorization' => 'Bearer ' . $token]));
		}
	}//end testAuthenticateRefusesUnusableSession()

	/**
	 * A session whose account is gone or closed is refused.
	 *
	 * @return void
	 */
	public function testAuthenticateRefusesMissingOrClosedAccount(): void {
		$this->session('gone', ['accountId' => 'acc-gone']);
		$this->session('closed', ['accountId' => 'acc-closed']);
		$this->accounts['acc-closed'] = ['status' => 'closed'];

		$this->assertUnauthenticated($this->request(['Authorization' => 'Bearer gone']));
		$this->assertUnauthenticated($this->request(['Authorization' => 'Bearer closed']));
	}//end testAuthenticateRefusesMissingOrClosedAccount()

	/**
	 * Widget mode is checked before the token: a disallowed origin is refused with 403.
	 *
	 * @return void
	 */
	public function testAuthenticateEnforcesWidgetOriginFirst(): void {
		$this->session('tok-1', ['accountId' => 'acc-1', 'tenantId' => 'widgetco']);
		$this->accounts['acc-1'] = [];

		try {
			$this->guard->authenticate(
				$this->request(['Authorization' => 'Bearer tok-1', 'X-Portal-Tenant' => 'widgetco', 'Origin' => 'https://evil.test'])
			);
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(403, $e->getStatus());
			$this->assertSame('originNotAllowed', $e->getErrorCode());
		}
	}//end testAuthenticateEnforcesWidgetOriginFirst()

	/**
	 * The IP and user-agent hashes are SHA-256 of the raw values.
	 *
	 * @return void
	 */
	public function testHashes(): void {
		$this->assertSame(hash('sha256', '192.0.2.7'), $this->guard->ipHash($this->request([], '192.0.2.7')));
		$this->assertSame(hash('sha256', 'Mozilla/5.0'), $this->guard->userAgentHash($this->request(['User-Agent' => 'Mozilla/5.0'])));
		$this->assertSame(hash('sha256', ''), $this->guard->userAgentHash($this->request([])));
	}//end testHashes()
}//end class
