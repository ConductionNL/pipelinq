<?php

/**
 * PreferenceCentreService: the link and the list a subscriber sees.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The preferences URL is built from a signed token; the preference list shows
 * every non-archived list with the contact's confirmed state.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\PreferenceCentreService
 */
class PreferenceCentreServiceTest extends TestCase {

	/**
	 * Token service mock.
	 *
	 * @var ListTokenService&MockObject
	 */
	private ListTokenService&MockObject $tokens;

	/**
	 * Mailing-list service mock.
	 *
	 * @var MailingListService&MockObject
	 */
	private MailingListService&MockObject $lists;

	/**
	 * Subscription query mock.
	 *
	 * @var SubscriptionQueryService&MockObject
	 */
	private SubscriptionQueryService&MockObject $queries;

	/**
	 * Subscription service mock.
	 *
	 * @var SubscriptionService&MockObject
	 */
	private SubscriptionService&MockObject $subscriptions;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tokens = $this->createMock(ListTokenService::class);
		$this->lists = $this->createMock(MailingListService::class);
		$this->queries = $this->createMock(SubscriptionQueryService::class);
		$this->subscriptions = $this->createMock(SubscriptionService::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return PreferenceCentreService
	 */
	private function service(): PreferenceCentreService {
		return new PreferenceCentreService($this->tokens, $this->lists, $this->queries, $this->subscriptions);
	}//end service()

	/**
	 * The URL wraps the token signed for that contact.
	 *
	 * @return void
	 */
	public function testPreferencesUrlSignsForTheContact(): void {
		$this->tokens->expects($this->once())->method('signPreferencesToken')->with('contact-1')->willReturn('signed');
		$this->tokens->expects($this->once())->method('preferencesUrl')->with('signed')->willReturn('https://host/prefs/signed');

		$this->assertSame('https://host/prefs/signed', $this->service()->preferencesUrlFor(contactId: 'contact-1'));
	}//end testPreferencesUrlSignsForTheContact()

	/**
	 * Archived lists are hidden; a list counts as subscribed only when the
	 * contact's membership on it is confirmed.
	 *
	 * @return void
	 */
	public function testPreferencesListTheActiveListsWithConfirmedState(): void {
		$this->tokens->expects($this->once())->method('verify')
			->with('tok', ListTokenService::PURPOSE_PREFERENCES)
			->willReturn(['c' => 'contact-1']);
		$this->queries->expects($this->once())->method('listSubscriptionsForContact')->with('contact-1')->willReturn(
			[
				['listId' => 'news', 'state' => SubscriptionService::STATE_CONFIRMED],
				['listId' => 'events', 'state' => 'pending'],
				['state' => SubscriptionService::STATE_CONFIRMED],
			]
		);
		$this->lists->expects($this->once())->method('listMailingLists')->with(1, 100)->willReturn(
			[
				'data' => [
					['id' => 'news', 'name' => 'News'],
					['id' => 'events', 'name' => 'Events', 'status' => 'active'],
					['id' => 'old', 'name' => 'Old', 'status' => 'archived'],
					['id' => 'offers', 'name' => 'Offers', 'status' => 'paused'],
				],
			]
		);
		$this->lists->method('publicProjection')->willReturnCallback(
			static fn (array $list): array => ['id' => $list['id'], 'name' => $list['name'], 'description' => 'about ' . $list['name']]
		);

		$this->assertSame(
			[
				['id' => 'news', 'name' => 'News', 'description' => 'about News', 'subscribed' => true],
				['id' => 'events', 'name' => 'Events', 'description' => 'about Events', 'subscribed' => false],
				['id' => 'offers', 'name' => 'Offers', 'description' => 'about Offers', 'subscribed' => false],
			],
			$this->service()->preferencesForToken(token: 'tok')
		);
	}//end testPreferencesListTheActiveListsWithConfirmedState()

	/**
	 * An invalid token, or one without a contact, shows nothing and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableTokenShowsNothing(): void {
		$this->tokens->method('verify')->willReturnOnConsecutiveCalls(null, ['c' => ''], ['x' => 'y']);
		$this->queries->expects($this->never())->method('listSubscriptionsForContact');
		$this->lists->expects($this->never())->method('listMailingLists');

		$service = $this->service();
		$this->assertNull($service->preferencesForToken(token: 'bad'));
		$this->assertNull($service->preferencesForToken(token: 'blank'));
		$this->assertNull($service->preferencesForToken(token: 'none'));
	}//end testAnUnusableTokenShowsNothing()
}//end class
