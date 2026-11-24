<?php

/**
 * Unit tests for BookingLinkTokens.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\BookingLink
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\BookingLink;

use OCA\Pipelinq\Service\BookingLink\BookingLinkTokens;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The token of a personal booking link: made once, found back, withdrawn.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
#[CoversClass(BookingLinkTokens::class)]
class BookingLinkTokensTest extends TestCase {

	/**
	 * A well formed token.
	 *
	 * @var string
	 */
	private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';

	/**
	 * The user preferences, uid => token.
	 *
	 * @var array<string, string>
	 */
	private array $prefs = [];

	/**
	 * Tokens over an in-memory preference store and the given users.
	 *
	 * @param array<string, bool> $users Uid => whether the user is enabled.
	 *
	 * @return BookingLinkTokens The tokens.
	 */
	private function tokens(array $users = []): BookingLinkTokens {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => ($this->prefs[$uid] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->assertSame(expected: 'pipelinq', actual: $app);
				$this->assertSame(expected: BookingLinkTokens::KEY, actual: $key);
				$this->prefs[$uid] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $uid): void {
				unset($this->prefs[$uid]);
			}
		);
		$config->method('getUsersForUserValue')->willReturnCallback(
			fn (string $app, string $key, string $value): array => array_keys(array_filter($this->prefs, static fn (string $token): bool => $token === $value))
		);

		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length, string $chars): string {
				$this->assertSame(expected: ISecureRandom::CHAR_ALPHANUMERIC, actual: $chars);
				return str_repeat('k', $length);
			}
		);

		$manager = $this->createMock(originalClassName: IUserManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $uid) use ($users): ?IUser {
				if (array_key_exists($uid, $users) === false) {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($users[$uid]);
				return $user;
			}
		);

		return new BookingLinkTokens(config: $config, random: $random, users: $manager);
	}//end tokens()

	/**
	 * A user without a link gets a new 32 character token, and the same one after.
	 *
	 * @return void
	 */
	public function testATokenIsMadeOnceAndKept(): void {
		$tokens = $this->tokens();

		$first = $tokens->tokenFor(userId: 'daan');

		$this->assertSame(expected: str_repeat('k', 32), actual: $first);
		$this->assertSame(expected: $first, actual: $this->prefs['daan']);
		$this->prefs['daan'] = self::TOKEN;
		$this->assertSame(expected: self::TOKEN, actual: $tokens->tokenFor(userId: 'daan'));
	}//end testATokenIsMadeOnceAndKept()

	/**
	 * A stored value that is not a token is replaced by a new one.
	 *
	 * @return void
	 */
	public function testAMalformedStoredTokenIsReplaced(): void {
		$this->prefs['daan'] = 'short';

		$this->assertSame(expected: str_repeat('k', 32), actual: $this->tokens()->tokenFor(userId: 'daan'));
	}//end testAMalformedStoredTokenIsReplaced()

	/**
	 * The token names its enabled owner.
	 *
	 * @return void
	 */
	public function testTheTokenNamesItsOwner(): void {
		$this->prefs['daan'] = self::TOKEN;

		$this->assertSame(expected: 'daan', actual: $this->tokens(users: ['daan' => true])->ownerOf(token: self::TOKEN));
	}//end testTheTokenNamesItsOwner()

	/**
	 * A malformed token, an unknown token, a shared token and a disabled owner name nobody.
	 *
	 * @return void
	 */
	public function testNobodyIsNamedByABadToken(): void {
		$this->prefs['daan'] = self::TOKEN;
		$this->prefs['sanne'] = 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
		$this->prefs['bram'] = 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
		$tokens = $this->tokens(users: ['daan' => false, 'sanne' => true, 'bram' => true]);

		$this->assertNull(actual: $tokens->ownerOf(token: ''));
		$this->assertNull(actual: $tokens->ownerOf(token: '%'));
		$this->assertNull(actual: $tokens->ownerOf(token: str_repeat('q', 32)));
		$this->assertNull(actual: $tokens->ownerOf(token: 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ'));
		$this->assertNull(actual: $tokens->ownerOf(token: self::TOKEN));
	}//end testNobodyIsNamedByABadToken()

	/**
	 * A removed owner cannot be booked.
	 *
	 * @return void
	 */
	public function testARemovedOwnerIsNotNamed(): void {
		$this->prefs['gone'] = self::TOKEN;

		$this->assertNull(actual: $this->tokens()->ownerOf(token: self::TOKEN));
	}//end testARemovedOwnerIsNotNamed()

	/**
	 * Switching the link off makes the old token name nobody.
	 *
	 * @return void
	 */
	public function testRevokingForgetsTheToken(): void {
		$this->prefs['daan'] = self::TOKEN;
		$tokens = $this->tokens(users: ['daan' => true]);

		$tokens->revoke(userId: 'daan');

		$this->assertArrayNotHasKey(key: 'daan', array: $this->prefs);
		$this->assertNull(actual: $tokens->ownerOf(token: self::TOKEN));
	}//end testRevokingForgetsTheToken()
}//end class
