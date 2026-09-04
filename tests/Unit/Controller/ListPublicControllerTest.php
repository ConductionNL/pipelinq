<?php

/**
 * Unit tests for ListPublicController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ListPublicController;
use OCA\Pipelinq\Service\Marketing\PreferenceCentreService;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the half of ADR-082 an attribute cannot carry.
 *
 * `#[BruteForceProtection]` ENFORCES a limit; `IThrottler::registerAttempt()`
 * is what COUNTS against it, and either alone is inert. A browser run can see
 * the 410 these endpoints answer but not the counter behind it, which is why
 * the spec excludes the scenario from e2e and asserts it here instead.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-public-list-endpoints-are-throttled-and-fail-closed
 */
class ListPublicControllerTest extends TestCase {
	/**
	 * The membership service, stubbed per test.
	 *
	 * @var SubscriptionService
	 */
	private SubscriptionService $subscriptions;

	/**
	 * The preference centre, stubbed per test.
	 *
	 * @var PreferenceCentreService
	 */
	private PreferenceCentreService $preferences;

	/**
	 * The throttler, whose calls are counted.
	 *
	 * @var IThrottler
	 */
	private IThrottler $throttler;

	/**
	 * Attempts the controller registered.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $attempts = [];

	/**
	 * Wire the controller over stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->subscriptions = $this->createMock(SubscriptionService::class);
		$this->preferences = $this->createMock(PreferenceCentreService::class);

		$this->throttler = $this->createMock(IThrottler::class);
		$this->throttler->method('registerAttempt')->willReturnCallback(
			function (string $action, string $ip): void {
				$this->attempts[] = ['action' => $action, 'ip' => $ip];
			}
		);
	}//end setUp()

	/**
	 * A confirmation token that does not verify is counted, and the page says
	 * nothing about which list or address it might have belonged to.
	 *
	 * @return void
	 */
	public function testConfirmRejectedTokenRegistersAttempt(): void {
		$this->subscriptions->method('confirm')->willReturn(['status' => 'invalid']);

		$response = $this->makeController()->confirm(token: 'not-a-token');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertCount(1, $this->attempts);
		$this->assertSame('pipelinq_mailing_list_token', $this->attempts[0]['action']);
		$this->assertSame('198.51.100.7', $this->attempts[0]['ip']);
	}//end testConfirmRejectedTokenRegistersAttempt()

	/**
	 * A confirmation that succeeds is not counted against the caller.
	 *
	 * @return void
	 */
	public function testSuccessfulConfirmIsNotCounted(): void {
		$this->subscriptions->method('confirm')->willReturn(
			['status' => 'confirmed', 'list' => ['id' => 'l1', 'name' => 'Nieuwsbrief', 'description' => '']]
		);

		$response = $this->makeController()->confirm(token: 'good');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->attempts);
	}//end testSuccessfulConfirmIsNotCounted()

	/**
	 * An unsubscribe token that does not verify is counted and answers 410.
	 *
	 * @return void
	 */
	public function testUnsubscribeRejectedTokenRegistersAttempt(): void {
		$this->subscriptions->method('unsubscribeByToken')->willReturn(
			['status' => 'invalid', 'count' => 0]
		);

		$response = $this->makeController()->unsubscribe(token: 'not-a-token');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertCount(1, $this->attempts);
	}//end testUnsubscribeRejectedTokenRegistersAttempt()

	/**
	 * The one-click unsubscribe answers 200 with no redirect, which is what
	 * RFC 8058 requires of the URL a List-Unsubscribe-Post header names.
	 *
	 * @return void
	 */
	public function testOneClickUnsubscribeAnswersTwoHundred(): void {
		$this->subscriptions->method('unsubscribeByToken')->willReturn(
			['status' => 'unsubscribed', 'count' => 1]
		);

		$response = $this->makeController()->unsubscribe(token: 'good');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['count']);
		$this->assertSame([], $this->attempts);
	}//end testOneClickUnsubscribeAnswersTwoHundred()

	/**
	 * A GET on the unsubscribe link renders and changes nothing, and its form
	 * posts back to the same URL so the page works with scripting off.
	 *
	 * @return void
	 */
	public function testUnsubscribePageRendersAFormAndChangesNothing(): void {
		$this->subscriptions->method('peekUnsubscribeToken')->willReturn(
			['list' => ['id' => 'l1', 'name' => 'Nieuwsbrief', 'description' => ''], 'state' => 'confirmed']
		);
		$this->subscriptions->expects($this->never())->method('unsubscribeByToken');

		$response = $this->makeController()->unsubscribePage(token: 'good');
		$html = $response->render();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('<form method="post"', $html);
		$this->assertStringContainsString('Nieuwsbrief', $html);
		$this->assertStringContainsString('<html lang="en">', $html);
	}//end testUnsubscribePageRendersAFormAndChangesNothing()

	/**
	 * A list name carrying markup is escaped, not rendered.
	 *
	 * The page is built in the controller rather than through a template, so
	 * the escaping is this controller's own responsibility and has to be
	 * asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testListNameIsEscapedIntoThePage(): void {
		$this->subscriptions->method('peekUnsubscribeToken')->willReturn(
			[
				'list' => ['id' => 'l1', 'name' => '<script>alert(1)</script>', 'description' => ''],
				'state' => 'confirmed',
			]
		);

		$html = $this->makeController()->unsubscribePage(token: 'good')->render();

		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
	}//end testListNameIsEscapedIntoThePage()

	/**
	 * A missing list answers exactly like a list closed to public signup, so
	 * the endpoint cannot be used to find out which lists exist.
	 *
	 * @return void
	 */
	public function testSubscribeAnswersTheSameForMissingAndClosedLists(): void {
		$this->subscriptions->method('subscribe')->willReturn(['status' => 'not-found']);

		$response = $this->makeController()->subscribe(id: 'no-such-list', email: 'a@b.nl');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('This link is no longer valid', $response->getData()['error']);
	}//end testSubscribeAnswersTheSameForMissingAndClosedLists()

	/**
	 * An accepted signup answers 202 with a message that says nothing about
	 * whether the address was already known.
	 *
	 * @return void
	 */
	public function testAcceptedSignupRevealsNothingAboutTheAddress(): void {
		$this->subscriptions->method('subscribe')->willReturn(['status' => 'accepted']);

		$response = $this->makeController()->subscribe(id: 'list-news', email: 'a@b.nl');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertStringNotContainsString('a@b.nl', (string)$response->getData()['message']);
	}//end testAcceptedSignupRevealsNothingAboutTheAddress()

	/**
	 * A preference token that does not verify is counted and answers 410.
	 *
	 * @return void
	 */
	public function testPreferencesRejectedTokenRegistersAttempt(): void {
		$this->preferences->method('preferencesForToken')->willReturn(null);

		$response = $this->makeController()->preferences(token: 'not-a-token');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertCount(1, $this->attempts);
	}//end testPreferencesRejectedTokenRegistersAttempt()

	/**
	 * Saving preferences answers with what changed, and a token that does not
	 * verify is counted and refused with the same 410 every other rejected
	 * token gets.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 *
	 * @return void
	 */
	public function testSavePreferencesAnswersWhatChangedAndCountsARejection(): void {
		$this->preferences->method('savePreferences')->willReturnOnConsecutiveCalls(
			['status' => 'saved', 'confirmed' => 1, 'unsubscribed' => 2],
			['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0],
		);

		$controller = $this->makeController();
		$saved = $controller->savePreferences(token: 'good', lists: ['list-1']);
		$refused = $controller->savePreferences(token: 'forged', lists: []);

		$this->assertSame(Http::STATUS_OK, $saved->getStatus());
		$this->assertSame(1, $saved->getData()['confirmed']);
		$this->assertSame(2, $saved->getData()['unsubscribed']);

		$this->assertSame(Http::STATUS_GONE, $refused->getStatus());
		$this->assertCount(1, $this->attempts);
		$this->assertSame('pipelinq_mailing_list_token', $this->attempts[0]['action']);
	}//end testSavePreferencesAnswersWhatChangedAndCountsARejection()

	/**
	 * Build the controller over the stubs, with a fixed caller address.
	 *
	 * @return ListPublicController The controller under test.
	 */
	private function makeController(): ListPublicController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('198.51.100.7');
		$request->method('getRequestUri')->willReturn('/index.php/apps/pipelinq/api/lists/unsubscribe/good');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}
				return vsprintf(str_replace('%s', '%1$s', $text), $parameters);
			}
		);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new ListPublicController(
			$request,
			$this->subscriptions,
			$this->preferences,
			$this->throttler,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeController()
}//end class
