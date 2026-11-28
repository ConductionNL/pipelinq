<?php

/**
 * ListPublicController: what the public list endpoints answer when a service throws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ListPublicController;
use OCA\Pipelinq\Kernel\Contracts\PreferenceCentreServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionServiceInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every endpoint fails closed: a throwing service is logged, never leaked, and a
 * token endpoint counts the attempt against the throttler.
 *
 * @covers \OCA\Pipelinq\Controller\ListPublicController
 * @uses   \OCA\Pipelinq\Controller\ListPageText
 */
class ListPublicControllerFailureTest extends TestCase {

	/**
	 * Subscription service mock.
	 *
	 * @var SubscriptionServiceInterface&MockObject
	 */
	private SubscriptionServiceInterface&MockObject $subscriptions;

	/**
	 * Preference centre mock.
	 *
	 * @var PreferenceCentreServiceInterface&MockObject
	 */
	private PreferenceCentreServiceInterface&MockObject $preferences;

	/**
	 * Throttler mock.
	 *
	 * @var IThrottler&MockObject
	 */
	private IThrottler&MockObject $throttler;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the controller's collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->subscriptions = $this->createMock(SubscriptionServiceInterface::class);
		$this->preferences = $this->createMock(PreferenceCentreServiceInterface::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ListPublicController
	 */
	private function controller(): ListPublicController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new ListPublicController(
			$request,
			$this->subscriptions,
			$this->preferences,
			$this->throttler,
			$l10n,
			$this->logger
		);
	}//end controller()

	/**
	 * Expect one warning with the given message and exception text.
	 *
	 * @param string $message   The log message.
	 * @param string $exception The exception message.
	 *
	 * @return void
	 */
	private function expectWarning(string $message, string $exception): void {
		$this->logger->expects($this->once())->method('warning')
			->with($message, ['exception' => $exception]);
	}//end expectWarning()

	/**
	 * Expect the rejected token to be counted once against the list throttle action.
	 *
	 * @return void
	 */
	private function expectCountedAttempt(): void {
		$this->throttler->expects($this->once())->method('registerAttempt')
			->with('pipelinq_mailing_list_token', '192.0.2.7');
	}//end expectCountedAttempt()

	/**
	 * A throwing signup answers the same 404 as a missing list.
	 *
	 * @return void
	 */
	public function testAThrowingSignupAnswersTheGenericRefusal(): void {
		$this->subscriptions->method('subscribe')->willThrowException(new RuntimeException('db down'));
		$this->expectWarning('ListPublicController.subscribe: failed', 'db down');

		$response = $this->controller()->subscribe('list-1', 'a@example.org');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'This link is no longer valid'], $response->getData());
	}//end testAThrowingSignupAnswersTheGenericRefusal()

	/**
	 * A malformed address is a 400 that says what is wrong with it.
	 *
	 * @return void
	 */
	public function testAMalformedAddressIsABadRequest(): void {
		$this->subscriptions->expects($this->once())->method('subscribe')
			->with('list-1', 'not-an-address', 'filled-by-bot')
			->willReturn(['status' => 'invalid']);

		$response = $this->controller()->subscribe('list-1', 'not-an-address', 'filled-by-bot');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'That does not look like an email address'], $response->getData());
	}//end testAMalformedAddressIsABadRequest()

	/**
	 * A throwing confirmation renders the expired page and counts the attempt.
	 *
	 * @return void
	 */
	public function testAThrowingConfirmationRendersTheExpiredPage(): void {
		$this->subscriptions->method('confirm')->willThrowException(new RuntimeException('bad signature'));
		$this->expectWarning('ListPublicController.confirm: failed', 'bad signature');
		$this->expectCountedAttempt();

		$response = $this->controller()->confirm('tok');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertStringContainsString('<h1>This link no longer works</h1>', $response->getData());
	}//end testAThrowingConfirmationRendersTheExpiredPage()

	/**
	 * An unusable unsubscribe link renders the expired page and no form.
	 *
	 * @return void
	 */
	public function testAThrowingUnsubscribePeekRendersTheExpiredPage(): void {
		$this->subscriptions->method('peekUnsubscribeToken')->willThrowException(new RuntimeException('expired'));
		$this->expectWarning('ListPublicController.unsubscribePage: failed', 'expired');
		$this->expectCountedAttempt();

		$response = $this->controller()->unsubscribePage('tok');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertStringContainsString('<title>Link expired</title>', $response->getData());
		$this->assertStringNotContainsString('<form', $response->getData());
	}//end testAThrowingUnsubscribePeekRendersTheExpiredPage()

	/**
	 * A throwing one-click unsubscribe answers 410 and counts the attempt.
	 *
	 * @return void
	 */
	public function testAThrowingOneClickUnsubscribeIsGone(): void {
		$this->subscriptions->method('unsubscribeByToken')->willThrowException(new RuntimeException('locked'));
		$this->expectWarning('ListPublicController.unsubscribe: failed', 'locked');
		$this->expectCountedAttempt();

		$response = $this->controller()->unsubscribe('tok', '1', 'too many');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame(['error' => 'This link is no longer valid'], $response->getData());
	}//end testAThrowingOneClickUnsubscribeIsGone()

	/**
	 * The preference centre returns the lists the token may hold, uncounted.
	 *
	 * @return void
	 */
	public function testAUsablePreferencesTokenListsTheSubscriptions(): void {
		$rows = [['id' => 'list-1', 'name' => 'News', 'subscribed' => true]];
		$this->preferences->expects($this->once())->method('preferencesForToken')->with('tok')->willReturn($rows);
		$this->throttler->expects($this->never())->method('registerAttempt');

		$response = $this->controller()->preferences('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['lists' => $rows], $response->getData());
	}//end testAUsablePreferencesTokenListsTheSubscriptions()

	/**
	 * A throwing preference lookup answers 410 and counts the attempt.
	 *
	 * @return void
	 */
	public function testAThrowingPreferencesLookupIsGone(): void {
		$this->preferences->method('preferencesForToken')->willThrowException(new RuntimeException('no contact'));
		$this->expectWarning('ListPublicController.preferences: failed', 'no contact');
		$this->expectCountedAttempt();

		$response = $this->controller()->preferences('tok');

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame(['error' => 'This link is no longer valid'], $response->getData());
	}//end testAThrowingPreferencesLookupIsGone()

	/**
	 * A throwing preference save answers 410 and counts the attempt.
	 *
	 * @return void
	 */
	public function testAThrowingPreferenceSaveIsGone(): void {
		$this->preferences->method('savePreferences')->willThrowException(new RuntimeException('write failed'));
		$this->expectWarning('ListPublicController.savePreferences: failed', 'write failed');
		$this->expectCountedAttempt();

		$response = $this->controller()->savePreferences('tok', ['list-1']);

		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame(['error' => 'This link is no longer valid'], $response->getData());
	}//end testAThrowingPreferenceSaveIsGone()
}//end class
