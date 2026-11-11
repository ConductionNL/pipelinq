<?php

/**
 * Characterisation test for PreferenceCentreService::savePreferences().
 *
 * Pins, for a signed preferences token, which memberships are closed and
 * which lists are confirmed (in order, with which address), the counts, and
 * the refusal of an unusable token before anything is read or written.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\SubscriptionService;
use PHPUnit\Framework\TestCase;

/**
 * The save, call by call.
 */
class PreferenceSaveCharacterisationTest extends TestCase {
	/**
	 * Calls seen, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A valid token closes the unticked memberships and confirms every ticked list.
	 *
	 * @return void
	 */
	public function testASaveClosesUntickedAndConfirmsTicked(): void {
		$existing = [
			['listId' => 'news', 'email' => ''],
			['listId' => 'events', 'email' => 'anna@example.org'],
			['listId' => 'offers', 'email' => 'other@example.org'],
			['listId' => '7'],
		];
		$service = $this->service(payload: ['c' => 'contact-1'], existing: $existing, closes: ['news' => true, 'offers' => false], confirms: ['events' => false]);

		$answer = $service->savePreferences('tok', ['events', 7, 'webinars']);

		self::assertSame(['status' => 'saved', 'confirmed' => 2, 'unsubscribed' => 1], $answer);
		self::assertSame(
			[
				['verify', 'tok', ListTokenService::PURPOSE_PREFERENCES],
				['list', 'contact-1'],
				['unsubscribe', 'news', 'contact-1', 'preference-centre'],
				['unsubscribe', 'offers', 'contact-1', 'preference-centre'],
				['confirm', 'events', 'contact-1', 'anna@example.org'],
				['confirm', '7', 'contact-1', 'anna@example.org'],
				['confirm', 'webinars', 'contact-1', 'anna@example.org'],
			],
			$this->calls
		);
	}//end testASaveClosesUntickedAndConfirmsTicked()

	/**
	 * Without any address on file a ticked list is confirmed with an empty address.
	 *
	 * @return void
	 */
	public function testWithoutAnAddressTheEmailIsEmpty(): void {
		$service = $this->service(payload: ['c' => 'contact-2'], existing: [], closes: [], confirms: []);

		self::assertSame(['status' => 'saved', 'confirmed' => 1, 'unsubscribed' => 0], $service->savePreferences('tok', ['news']));
		self::assertSame(['confirm', 'news', 'contact-2', ''], $this->calls[2]);
	}//end testWithoutAnAddressTheEmailIsEmpty()

	/**
	 * An unusable token is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testAnUnusableTokenChangesNothing(): void {
		foreach ([null, ['c' => ''], []] as $payload) {
			$service = $this->service(payload: $payload, existing: [['listId' => 'news']], closes: [], confirms: []);

			self::assertSame(['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0], $service->savePreferences('bad', ['news']));
			self::assertSame([['verify', 'bad', ListTokenService::PURPOSE_PREFERENCES]], $this->calls);
		}
	}//end testAnUnusableTokenChangesNothing()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed>|null        $payload  The verified token payload.
	 * @param array<int, array<string, mixed>> $existing The contact's memberships.
	 * @param array<string, bool>              $closes   Unsubscribe answers per list (default true).
	 * @param array<string, bool>              $confirms Confirm answers per list (default true).
	 *
	 * @return PreferenceCentreService
	 */
	private function service(?array $payload, array $existing, array $closes, array $confirms): PreferenceCentreService {
		$this->calls = [];
		$tokens = $this->createMock(ListTokenService::class);
		$tokens->method('verify')->willReturnCallback(
			function (string $token, string $purpose) use ($payload): ?array {
				$this->calls[] = ['verify', $token, $purpose];
				return $payload;
			}
		);
		$queries = $this->createMock(SubscriptionQueryService::class);
		$queries->method('listSubscriptionsForContact')->willReturnCallback(
			function (string $contactId) use ($existing): array {
				$this->calls[] = ['list', $contactId];
				return $existing;
			}
		);
		$subscriptions = $this->createMock(SubscriptionService::class);
		$subscriptions->method('unsubscribeFromList')->willReturnCallback(
			function (string $listId, string $contactId, string $reason = '') use ($closes): bool {
				$this->calls[] = ['unsubscribe', $listId, $contactId, $reason];
				return $closes[$listId] ?? true;
			}
		);
		$subscriptions->method('confirmForContact')->willReturnCallback(
			function (string $listId, string $contactId, string $email) use ($confirms): bool {
				$this->calls[] = ['confirm', $listId, $contactId, $email];
				return $confirms[$listId] ?? true;
			}
		);

		return new PreferenceCentreService($tokens, $this->createMock(MailingListService::class), $queries, $subscriptions);
	}//end service()
}//end class
