<?php

/**
 * The public web chat routes: the framed window and start, send and poll.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\WebChatController;
use OCA\Pipelinq\Controller\WebChatWindowController;
use OCA\Pipelinq\Service\WebChat\WebChatRefusal;
use OCA\Pipelinq\Service\WebChat\WebChatService;
use OCA\Pipelinq\Service\WebChat\WebChatFrame;
use OCA\Pipelinq\Service\WebChat\WebChatStore;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * REQ-WCH-001 and REQ-WCH-002 on the controller.
 *
 * @covers \OCA\Pipelinq\Controller\WebChatController
 * @covers \OCA\Pipelinq\Controller\WebChatWindowController
 * @uses   \OCA\Pipelinq\Service\WebChat\WebChatRefusal
 */
class WebChatControllerTest extends TestCase {

	/**
	 * The chat service.
	 *
	 * @var WebChatService&MockObject
	 */
	private WebChatService $chat;

	/**
	 * The store.
	 *
	 * @var WebChatStore&MockObject
	 */
	private WebChatStore $store;

	/**
	 * The initial state the window got.
	 *
	 * @var array<string, mixed>
	 */
	private array $initial = [];

	/**
	 * The controller over a request with these parameters.
	 *
	 * @param array<string, mixed> $params The body.
	 *
	 * @return WebChatController
	 */
	private function controller(array $params=[]): WebChatController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$this->chat ??= $this->createMock(originalClassName: WebChatService::class);

		return new WebChatController(
			request: $request,
			chat: $this->chat,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The window's controller, over the store; the initial state it hands out lands in $initial.
	 *
	 * @return WebChatWindowController
	 */
	private function windowController(): WebChatWindowController {
		$this->store ??= $this->createMock(originalClassName: WebChatStore::class);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (
			string $route,
			array $args,
		): string => $route . ':' . ($args['channelToken'] ?? ''));
		$state = $this->createMock(originalClassName: IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $data): void {
				$this->initial[$key] = $data;
			}
		);

		return new WebChatWindowController(
			request: $this->createMock(originalClassName: IRequest::class),
			frame: new WebChatFrame(store: $this->store, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			urls: $urls,
			state: $state,
		);
	}//end windowController()

	/**
	 * Set up fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->chat  = $this->createMock(originalClassName: WebChatService::class);
		$this->store = $this->createMock(originalClassName: WebChatStore::class);
	}//end setUp()

	/**
	 * The window may be framed by exactly the channel's websites, and not by this site itself.
	 *
	 * @return void
	 */
	public function testTheWindowMayBeFramedOnlyByTheChannelsWebsites(): void {
		$this->store->method('activeChannel')->willReturn(
			[
				'id' => 'ch-1',
				'name' => 'Website',
				'allowedOrigins' => [
					'https://www.gemeente.nl/',
					'HTTPS://WWW.GEMEENTE.NL',
					'https://wijk.gemeente.nl:8443',
					"https://evil.example; script-src *",
					'javascript:x',
					7,
				],
			]
		);

		$response = $this->windowController()->show(channelToken: 'abcdefghijklmnop1234');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'base', actual: $response->getRenderAs());
		$this->assertStringContainsString(
			needle: 'frame-ancestors https://www.gemeente.nl https://wijk.gemeente.nl:8443;',
			haystack: $response->getContentSecurityPolicy()->buildPolicy(),
		);
		$this->assertStringNotContainsString(needle: 'evil', haystack: $response->getContentSecurityPolicy()->buildPolicy());
		$this->assertSame(
			expected: [
				'available' => true,
				'name'      => 'Website',
				'startUrl'  => 'pipelinq.webChat.start:abcdefghijklmnop1234',
				'sendUrl'   => 'pipelinq.webChat.send:abcdefghijklmnop1234',
				'pollUrl'   => 'pipelinq.webChat.poll:abcdefghijklmnop1234',
			],
			actual: $this->initial['web-chat']
		);
	}//end testTheWindowMayBeFramedOnlyByTheChannelsWebsites()

	/**
	 * An unknown or inactive channel answers 404 and may be framed by nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelMayBeFramedByNobody(): void {
		$this->store->method('activeChannel')->willReturn(null);

		$response = $this->windowController()->show(channelToken: 'abcdefghijklmnop1234');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertStringContainsString(needle: "frame-ancestors 'none';", haystack: $response->getContentSecurityPolicy()->buildPolicy());
		$this->assertFalse(condition: $this->initial['web-chat']['available']);
		$this->assertSame(expected: 'pipelinq.webChat.start:', actual: $this->initial['web-chat']['startUrl']);
	}//end testAnUnknownChannelMayBeFramedByNobody()

	/**
	 * A channel lookup that fails still answers a page framed by nobody.
	 *
	 * @return void
	 */
	public function testAFailedLookupAnswersAPageFramedByNobody(): void {
		$this->store->method('activeChannel')->willThrowException(new RuntimeException('down'));

		$response = $this->windowController()->show(channelToken: 'abcdefghijklmnop1234');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertStringContainsString(needle: "frame-ancestors 'none';", haystack: $response->getContentSecurityPolicy()->buildPolicy());
	}//end testAFailedLookupAnswersAPageFramedByNobody()

	/**
	 * Start answers 201 with the chat; send passes the turn from the body.
	 *
	 * @return void
	 */
	public function testStartAndSendPassTheBody(): void {
		$this->chat->expects($this->once())->method('start')->with('abcdefghijklmnop1234')->willReturn(['conversation' => 'c-1', 'secret' => 's']);
		$this->chat->expects($this->once())->method('send')->with(
			'abcdefghijklmnop1234',
			'c-1',
			's',
			['text' => 'Hallo', 'email' => '', 'handover' => true, 'locale' => 'en']
		)->willReturn(['state' => 'waiting', 'messages' => []]);

		$start = $this->controller()->start(channelToken: 'abcdefghijklmnop1234');
		$send  = $this->controller(params: [
			'conversation' => 'c-1',
			'secret' => 's',
			'text' => 'Hallo',
			'handover' => 'true',
			'locale' => 'en-GB',
			'email' => ['not', 'a', 'string'],
		])->send(channelToken: 'abcdefghijklmnop1234');

		$this->assertSame(expected: [201, ['conversation' => 'c-1', 'secret' => 's']], actual: [$start->getStatus(), $start->getData()]);
		$this->assertSame(expected: [200, ['state' => 'waiting', 'messages' => []]], actual: [$send->getStatus(), $send->getData()]);
	}//end testStartAndSendPassTheBody()

	/**
	 * A refusal answers its status and message; anything else answers 503 without detail.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatusAndAFailureNoDetail(): void {
		$this->chat->method('poll')->willThrowException(new WebChatRefusal(message: 'This chat is not known.', status: 403));
		$this->chat->method('start')->willThrowException(new RuntimeException('SQLSTATE secret detail'));

		$poll  = $this->controller(params: ['conversation' => 'c-1', 'secret' => 'wrong', 'since' => ''])->poll(channelToken: 'abcdefghijklmnop1234');
		$start = $this->controller()->start(channelToken: 'abcdefghijklmnop1234');

		$this->assertSame(expected: [403, ['error' => 'This chat is not known.']], actual: [$poll->getStatus(), $poll->getData()]);
		$this->assertSame(expected: [503, ['error' => 'The chat is not available right now.']], actual: [$start->getStatus(), $start->getData()]);
	}//end testARefusalAnswersItsStatusAndAFailureNoDetail()

	/**
	 * Every route is public, without CSRF, and rate limited per address.
	 *
	 * @return void
	 */
	public function testEveryRouteIsPublicAndRateLimited(): void {
		$routes = [
			[WebChatWindowController::class, 'show'],
			[WebChatController::class, 'start'],
			[WebChatController::class, 'send'],
			[WebChatController::class, 'poll'],
		];
		foreach ($routes as [$class, $method]) {
			$reflection = new ReflectionMethod($class, $method);
			foreach ([PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class] as $attribute) {
				$this->assertCount(expectedCount: 1, haystack: $reflection->getAttributes($attribute), message: $method . ' ' . $attribute);
			}
		}
	}//end testEveryRouteIsPublicAndRateLimited()
}//end class
