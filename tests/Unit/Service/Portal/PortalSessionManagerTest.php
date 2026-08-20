<?php

/**
 * Unit tests for PortalSessionManager.
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

use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Tests for session lifecycle + validation.
 */
class PortalSessionManagerTest extends TestCase {
	/**
	 * Mutable "now".
	 *
	 * @var int
	 */
	private int $now = 2000000;

	/**
	 * The fake repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $repository;

	/**
	 * The manager under test.
	 *
	 * @var PortalSessionManager
	 */
	private PortalSessionManager $manager;

	/**
	 * Set up the manager with deterministic time + random tokens.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository();

		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return str_pad((string)$counter, $length, 'x');
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			fn (): \DateTime => (new \DateTime())->setTimestamp($this->now)
		);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$tokens = new PortalTokenService($random, $time);
		$this->manager = new PortalSessionManager($this->repository, $tokens, $time);
	}//end setUp()

	/**
	 * A created session validates with its plaintext token.
	 *
	 * @return void
	 */
	public function testCreatedSessionValidates(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');
		$session = $this->manager->validateSession($created['token'], 'tenant-a');

		$this->assertNotNull($session);
		$this->assertSame('acc-1', $session['accountId']);
	}//end testCreatedSessionValidates()

	/**
	 * A token from another tenant is rejected (cross-tenant boundary).
	 *
	 * @return void
	 */
	public function testCrossTenantTokenRejected(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');
		$this->assertNull($this->manager->validateSession($created['token'], 'tenant-b'));
	}//end testCrossTenantTokenRejected()

	/**
	 * A revoked session no longer validates.
	 *
	 * @return void
	 */
	public function testRevokedSessionRejected(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');
		$sessionId = $this->repository->idOf($created['session']);
		$this->manager->revokeSession($sessionId, 'logout');

		$this->assertNull($this->manager->validateSession($created['token'], 'tenant-a'));
	}//end testRevokedSessionRejected()

	/**
	 * An expired session no longer validates.
	 *
	 * @return void
	 */
	public function testExpiredSessionRejected(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash', 8);
		// Advance past the 8-hour TTL.
		$this->now += (9 * 3600);
		$this->assertNull($this->manager->validateSession($created['token'], 'tenant-a'));
	}//end testExpiredSessionRejected()

	/**
	 * An MFA-pending (half-open) session never validates, and the manager
	 * exposes no method that would promote it.
	 *
	 * @return void
	 */
	public function testMfaPendingSessionNeverValidates(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash', 8, true);
		$this->assertNull($this->manager->validateSession($created['token'], 'tenant-a'));

		// The live flow (PortalAuthService::completeMfaStage) verifies the TOTP
		// code BEFORE creating a session, so a half-open session is never
		// promoted — and there must be no primitive that promotes one.
		$this->assertFalse(method_exists(PortalSessionManager::class, 'clearMfaPending'));
		$this->assertNull($this->manager->validateSession($created['token'], 'tenant-a'));
	}//end testMfaPendingSessionNeverValidates()

	/**
	 * A garbage token never validates.
	 *
	 * @return void
	 */
	public function testGarbageTokenRejected(): void {
		$this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');
		$this->assertNull($this->manager->validateSession('not-a-real-token', 'tenant-a'));
		$this->assertNull($this->manager->validateSession(null, 'tenant-a'));
	}//end testGarbageTokenRejected()

	/**
	 * Revoking all sessions for an account revokes each active one.
	 *
	 * @return void
	 */
	public function testRevokeAllForAccount(): void {
		$first = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');
		$second = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash');

		$count = $this->manager->revokeAllForAccount('acc-1', 'account-closure');
		$this->assertSame(2, $count);
		$this->assertNull($this->manager->validateSession($first['token'], 'tenant-a'));
		$this->assertNull($this->manager->validateSession($second['token'], 'tenant-a'));
	}//end testRevokeAllForAccount()

	/**
	 * Extending a session pushes the expiry into the future.
	 *
	 * @return void
	 */
	public function testExtendSession(): void {
		$created = $this->manager->createSession('acc-1', 'tenant-a', 'iphash', 'uahash', 8);
		$sessionId = $this->repository->idOf($created['session']);

		$this->now += (7 * 3600);
		$updated = $this->manager->extendSession($sessionId, 8);
		$this->assertNotNull($updated);

		// 6 more hours: still valid because we extended to now+8h.
		$this->now += (6 * 3600);
		$this->assertNotNull($this->manager->validateSession($created['token'], 'tenant-a'));
	}//end testExtendSession()
}//end class
