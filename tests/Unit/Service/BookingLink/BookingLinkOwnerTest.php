<?php

/**
 * Unit tests for BookingLinkOwner.
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

use OCA\Pipelinq\Service\BookingLink\BookingLinkOwner;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The owner's time zone, with its fallbacks, and the owner's language.
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-user-can-send-a-booking-link-that-offers-the-free-slots-in-their-own-calendar
 */
#[CoversClass(BookingLinkOwner::class)]
class BookingLinkOwnerTest extends TestCase {

	/**
	 * An owner helper over a user zone and a server zone.
	 *
	 * @param string $userZone   The user's own zone setting.
	 * @param string $serverZone The server's default zone.
	 * @param IFactory|null $l10n The translations.
	 *
	 * @return BookingLinkOwner The helper.
	 */
	private function owner(string $userZone, string $serverZone, ?IFactory $l10n = null): BookingLinkOwner {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturn($userZone);
		$config->method('getSystemValueString')->willReturn($serverZone);

		$daan = $this->createMock(originalClassName: IUser::class);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnMap([['daan', $daan]]);

		return new BookingLinkOwner(config: $config, users: $users, l10n: ($l10n ?? $this->createMock(originalClassName: IFactory::class)));
	}//end owner()

	/**
	 * The user's own zone wins, then the server's, then UTC for a zone that does not exist.
	 *
	 * @return void
	 */
	public function testTheZoneFallsBack(): void {
		$own = $this->owner(userZone: 'Europe/Amsterdam', serverZone: 'Europe/London');
		$server = $this->owner(userZone: '', serverZone: 'Europe/London');
		$broken = $this->owner(userZone: 'Mars/Olympus', serverZone: 'Europe/London');

		$this->assertSame(expected: 'Europe/Amsterdam', actual: $own->zone(owner: 'daan')->getName());
		$this->assertSame(expected: 'Europe/London', actual: $server->zone(owner: 'daan')->getName());
		$this->assertSame(expected: 'UTC', actual: $broken->zone(owner: 'daan')->getName());
	}//end testTheZoneFallsBack()

	/**
	 * The translations are pipelinq's, in the owner's language.
	 *
	 * @return void
	 */
	public function testTheTranslationsAreInTheOwnersLanguage(): void {
		$translations = $this->createMock(originalClassName: IL10N::class);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->expects($this->once())->method('getUserLanguage')->willReturn('nl');
		$factory->expects($this->once())->method('get')->with('pipelinq', 'nl')->willReturn($translations);

		$this->assertSame(expected: $translations, actual: $this->owner(userZone: '', serverZone: 'UTC', l10n: $factory)->translations(owner: 'daan'));
	}//end testTheTranslationsAreInTheOwnersLanguage()
}//end class
