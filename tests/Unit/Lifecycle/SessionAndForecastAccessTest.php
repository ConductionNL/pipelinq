<?php

/**
 * Unit tests for SessionUser and ForecastAccess: who is acting, and which forecast refusals are logged.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\ForecastAccess;
use OCA\Pipelinq\Lifecycle\ForecastAccessPolicy;
use OCA\Pipelinq\Lifecycle\SessionUser;
use OCA\Pipelinq\Service\ForecastSnapshotQuery;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Lifecycle\SessionUser
 * @covers \OCA\Pipelinq\Lifecycle\ForecastAccess
 * @uses   \OCA\Pipelinq\Service\ForecastSnapshotQuery
 */
class SessionAndForecastAccessTest extends TestCase {

	/**
	 * The forecast policy double.
	 *
	 * @var ForecastAccessPolicy&MockObject
	 */
	private ForecastAccessPolicy&MockObject $policy;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policy = $this->createMock(ForecastAccessPolicy::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A session for the given uid, or for nobody.
	 *
	 * @param string|null $uid The signed-in uid, or null.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * Forecast access for a session nobody needs.
	 *
	 * @return ForecastAccess
	 */
	private function forecast(): ForecastAccess {
		return new ForecastAccess(userSession: $this->session(uid: 'ann'), accessPolicy: $this->policy, logger: $this->logger);
	}//end forecast()

	/**
	 * The session user is the signed-in uid, or empty for nobody; the admin flag comes from the group manager.
	 *
	 * @return void
	 */
	public function testTheSessionUser(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnMap([['root', true], ['ann', false]]);

		$this->assertSame('ann', (new SessionUser(userSession: $this->session(uid: 'ann'), groupManager: $groups))->uid());
		$this->assertSame('', (new SessionUser(userSession: $this->session(uid: null), groupManager: $groups))->uid());
		$this->assertTrue((new SessionUser(userSession: $this->session(uid: null), groupManager: $groups))->isAdmin(uid: 'root'));
		$this->assertFalse((new SessionUser(userSession: $this->session(uid: null), groupManager: $groups))->isAdmin(uid: 'ann'));
	}//end testTheSessionUser()

	/**
	 * The acting forecast user is the signed-in uid, or empty for nobody.
	 *
	 * @return void
	 */
	public function testTheActingForecastUser(): void {
		$this->assertSame('ann', $this->forecast()->actingUserId());
		$this->assertSame(
			'',
			(new ForecastAccess(userSession: $this->session(uid: null), accessPolicy: $this->policy, logger: $this->logger))->actingUserId()
		);
	}//end testTheActingForecastUser()

	/**
	 * A read passes the query's level and owner to the policy; an allowed read is not logged.
	 *
	 * @return void
	 */
	public function testAnAllowedReadIsNotLogged(): void {
		$this->policy->expects($this->once())->method('canRead')->with('ann', 'team', 'bob')->willReturn(true);
		$this->logger->expects($this->never())->method('warning');

		$query = new ForecastSnapshotQuery(values: ['periodId' => 'p1', 'level' => 'team', 'ownerId' => 'bob', 'format' => 'json', 'limit' => 50, 'offset' => 0]);
		$this->assertTrue($this->forecast()->mayRead(uid: 'ann', query: $query));
	}//end testAnAllowedReadIsNotLogged()

	/**
	 * A refused read answers false and is logged with uid, level and owner.
	 *
	 * @return void
	 */
	public function testARefusedReadIsLogged(): void {
		$this->policy->method('canRead')->with('ann', 'company', null)->willReturn(false);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: forecast read denied', ['uid' => 'ann', 'level' => 'company', 'ownerId' => null]);

		$query = new ForecastSnapshotQuery(values: ['periodId' => 'p1', 'level' => 'company', 'ownerId' => null, 'format' => 'json', 'limit' => 50, 'offset' => 0]);
		$this->assertFalse($this->forecast()->mayRead(uid: 'ann', query: $query));
	}//end testARefusedReadIsLogged()

	/**
	 * An override is allowed or refused by the policy for the level; only a refusal is logged.
	 *
	 * @return void
	 */
	public function testOverridesFollowThePolicyAndRefusalsAreLogged(): void {
		$this->policy->method('canOverride')->willReturnMap([['ann', 'rep', true], ['ann', 'division', false]]);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: forecast override denied', ['uid' => 'ann', 'level' => 'division']);

		$this->assertTrue($this->forecast()->mayOverride(uid: 'ann', level: 'rep'));
		$this->assertFalse($this->forecast()->mayOverride(uid: 'ann', level: 'division'));
	}//end testOverridesFollowThePolicyAndRefusalsAreLogged()

	/**
	 * Deleting an override is decided on the override's own level; a missing level asks about ''.
	 *
	 * @return void
	 */
	public function testDeletingAnOverrideUsesItsOwnLevel(): void {
		$this->policy->method('canOverride')->willReturnMap([['ann', 'team', true], ['ann', '', false]]);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: forecast override delete denied', ['uid' => 'ann', 'id' => 'ov-2']);

		$this->assertTrue($this->forecast()->mayDeleteOverride(uid: 'ann', override: ['level' => 'team', 'requestedId' => 'ov-1']));
		$this->assertFalse($this->forecast()->mayDeleteOverride(uid: 'ann', override: ['requestedId' => 'ov-2']));
	}//end testDeletingAnOverrideUsesItsOwnLevel()
}//end class
