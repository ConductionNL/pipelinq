<?php

/**
 * Unit tests for SystemServiceAccount.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The system account is created disabled and only stands in when nobody is signed in.
 */
class SystemServiceAccountTest extends TestCase {
	/**
	 * A missing account is created with a random password, named, and disabled.
	 *
	 * @return void
	 */
	public function testCreatesTheAccountDisabled(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('setDisplayName')->with('Pipelinq system');
		$user->expects($this->once())->method('setEnabled')->with(false);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$users->expects($this->once())->method('createUser')->with('pipelinq-system', str_repeat('x', 64))->willReturn($user);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->with(64)->willReturn(str_repeat('x', 64));

		$account = new SystemServiceAccount(userManager: $users, userSession: $this->createMock(IUserSession::class), random: $random);
		$this->assertSame($user, $account->account());
	}//end testCreatesTheAccountDisabled()

	/**
	 * When the account cannot be created, it says so.
	 *
	 * @return void
	 */
	public function testFailsLoudWhenTheAccountCannotBeCreated(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$users->method('createUser')->willReturn(false);

		$account = new SystemServiceAccount(userManager: $users, userSession: $this->createMock(IUserSession::class), random: $this->createMock(ISecureRandom::class));
		$this->expectException(RuntimeException::class);
		$account->account();
	}//end testFailsLoudWhenTheAccountCannotBeCreated()

	/**
	 * An existing account is reused, and the previous user comes back even when the work throws.
	 *
	 * @return void
	 */
	public function testRestoresThePreviousUserWhenTheWorkThrows(): void {
		$system = $this->createMock(IUser::class);
		$users  = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($system);
		$users->expects($this->never())->method('createUser');

		$active  = null;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function () use (&$active) {
			return $active;
		});
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user) use (&$active): void {
			$active = $user;
		});

		$account = new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class));
		$seen    = null;
		try {
			$account->runAsCurrentOrSystem(function () use (&$seen, &$active): void {
				$seen = $active;
				throw new RuntimeException('boom');
			});
		} catch (RuntimeException $e) {
			// Expected.
		}

		$this->assertSame($system, $seen);
		$this->assertNull($active);
	}//end testRestoresThePreviousUserWhenTheWorkThrows()
}//end class
