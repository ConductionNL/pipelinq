<?php

/**
 * Pipelinq BookingLinkOwner.
 *
 * What the booking link needs to know of the user who owns it: the time zone
 * their working day is in, and the language the booked event is written in.
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

use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Throwable;

/**
 * The owner's time zone and language.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
class BookingLinkOwner {

	/**
	 * Constructor.
	 *
	 * @param IConfig      $config The user's and the server's time zone.
	 * @param IUserManager $users  The user, for their language.
	 * @param IFactory     $l10n   The translations.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserManager $users,
		private readonly IFactory $l10n,
	) {
	}//end __construct()

	/**
	 * The owner's time zone: their own setting, else the server's default, else UTC.
	 *
	 * @param string $owner The owner's uid.
	 *
	 * @return DateTimeZone The zone.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function zone(string $owner): DateTimeZone {
		$name = (string)$this->config->getUserValue($owner, 'core', 'timezone', '');
		if ($name === '') {
			$name = $this->config->getSystemValueString('default_timezone', 'UTC');
		}

		try {
			return new DateTimeZone($name);
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}//end zone()

	/**
	 * Pipelinq's translations in the owner's language.
	 *
	 * @param string $owner The owner's uid.
	 *
	 * @return IL10N The translations.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#task-34.1
	 */
	public function translations(string $owner): IL10N {
		return $this->l10n->get(AppIdentity::APP_ID, $this->l10n->getUserLanguage($this->users->get($owner)));
	}//end translations()
}//end class
