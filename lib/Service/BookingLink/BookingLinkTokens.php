<?php

/**
 * Pipelinq BookingLinkTokens.
 *
 * The secret token in a user's personal booking link. One per user, kept as a
 * user preference of pipelinq. Whoever holds the link may see when the user is
 * free and book a slot, so the token is long and random, and the user can
 * switch the link off, which makes the old link stop working.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\BookingLink
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

namespace OCA\Pipelinq\Service\BookingLink;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Hands out, finds and withdraws the token of a personal booking link.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class BookingLinkTokens {

	/**
	 * The user preference that holds the token.
	 *
	 * @var string
	 */
	public const KEY = 'booking_link_token';

	/**
	 * The length of a token.
	 *
	 * @var int
	 */
	private const LENGTH = 32;

	/**
	 * Constructor.
	 *
	 * @param IConfig       $config The user preferences.
	 * @param ISecureRandom $random Makes the token.
	 * @param IUserManager  $users  Says whether the owner may still be booked.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly ISecureRandom $random,
		private readonly IUserManager $users,
	) {
	}//end __construct()

	/**
	 * The user's token, made on first use.
	 *
	 * @param string $userId The user.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function tokenFor(string $userId): string {
		$token = (string)$this->config->getUserValue($userId, AppIdentity::APP_ID, self::KEY, '');
		if (self::wellFormed(token: $token) === true) {
			return $token;
		}

		$token = $this->random->generate(self::LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setUserValue($userId, AppIdentity::APP_ID, self::KEY, $token);
		return $token;
	}//end tokenFor()

	/**
	 * Switch the user's link off: the token is forgotten, the next link is a new one.
	 *
	 * @param string $userId The user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function revoke(string $userId): void {
		$this->config->deleteUserValue($userId, AppIdentity::APP_ID, self::KEY);
	}//end revoke()

	/**
	 * The user a token belongs to, or null for a token nobody holds.
	 *
	 * A malformed token is not looked up, a token two users share names
	 * neither, and a disabled or removed user cannot be booked.
	 *
	 * @param string $token The token from the link.
	 *
	 * @return string|null The owner's uid.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function ownerOf(string $token): ?string {
		if (self::wellFormed(token: $token) === false) {
			return null;
		}

		$owners = $this->config->getUsersForUserValue(AppIdentity::APP_ID, self::KEY, $token);
		if (count($owners) !== 1) {
			return null;
		}

		$user = $this->users->get((string)$owners[0]);
		if ($user === null || $user->isEnabled() === false) {
			return null;
		}

		return $user->getUID();
	}//end ownerOf()

	/**
	 * Whether a token has the shape this class makes.
	 *
	 * @param string $token The token.
	 *
	 * @return bool True for 32 letters and digits.
	 */
	private static function wellFormed(string $token): bool {
		return preg_match('/^[A-Za-z0-9]{' . self::LENGTH . '}$/', $token) === 1;
	}//end wellFormed()
}//end class
