<?php

/**
 * A website chat: the visitor's start, send and poll, the handover, and the colleague's take, reply and end.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebChat
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
 * @spec openspec/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebChat;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use OCA\Pipelinq\Service\WebChat\ChatAssistant;
use OCA\Pipelinq\Service\WebChat\WebChatAccess;
use OCA\Pipelinq\Service\WebChat\WebChatHandover;
use OCA\Pipelinq\Service\WebChat\WebChatMessages;
use OCA\Pipelinq\Service\WebChat\WebChatRefusal;
use OCA\Pipelinq\Service\WebChat\WebChatAssistantTurn;
use OCA\Pipelinq\Service\WebChat\WebChatRecords;
use OCA\Pipelinq\Service\WebChat\WebChatService;
use OCA\Pipelinq\Service\WebChat\WebChatVisitorWrites;
use OCA\Pipelinq\Service\WebChat\WebChatWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-WCH-002 to REQ-WCH-005 on the service.
 *
 * @covers \OCA\Pipelinq\Service\WebChat\WebChatService
 * @covers \OCA\Pipelinq\Service\WebChat\WebChatAssistantTurn
 * @covers \OCA\Pipelinq\Service\WebChat\WebChatVisitorWrites
 * @uses   \OCA\Pipelinq\Service\WebChat\WebChatRecords
 * @uses   \OCA\Pipelinq\Service\WebChat\ChatAssistant
 * @uses   \OCA\Pipelinq\Service\WebChat\WebChatRefusal
 * @uses   \OCA\Pipelinq\Service\WebChat\WebChatStore
 */
class WebChatServiceTest extends TestCase {

	/**
	 * The channel token of the test channel.
	 *
	 * @var string
	 */
	private const TOKEN = 'abcdefghijklmnop1234';

	/**
	 * The in-memory store.
	 *
	 * @var FakeWebChatStore
	 */
	private FakeWebChatStore $store;

	/**
	 * The intake a handover goes to.
	 *
	 * @var InboundTicketService&MockObject
	 */
	private InboundTicketService $inbound;

	/**
	 * The tickets a colleague takes.
	 *
	 * @var TicketServiceInterface&MockObject
	 */
	private TicketServiceInterface $tickets;

	/**
	 * The container hermiq's entry point is resolved from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The messaging account the visitor's writes run as.
	 *
	 * @var MessagingServiceAccount&MockObject
	 */
	private MessagingServiceAccount $account;

	/**
	 * The current time, as a Unix timestamp.
	 *
	 * @var int
	 */
	private int $now = 1_800_000_000;

	/**
	 * The messages the intake received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $received = [];

	/**
	 * Whether the visitor's writes ran as the messaging account.
	 *
	 * @var int
	 */
	private int $runAs = 0;

	/**
	 * Set up one active channel with the assistant off.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeWebChatStore();
		$this->store->channels['ch-1'] = [
			'id'           => 'ch-1',
			'name'         => 'Website',
			'channelToken' => self::TOKEN,
			'active'       => true,
			'greeting'     => 'Waarmee kunnen we helpen?',
			'offlineText'  => 'Laat uw e-mailadres achter.',
			'waitSeconds'  => 180,
		];

		$this->inbound = $this->createMock(originalClassName: InboundTicketService::class);
		$this->inbound->method('receive')->willReturnCallback(
			function (array $message): array {
				$this->received[] = $message;
				return ['ticket' => 'ticket-1', 'created' => count($this->received) === 1, 'status' => 'new'];
			}
		);
		$this->tickets   = $this->createMock(originalClassName: TicketServiceInterface::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('hermiq is not installed'));
		$this->account = $this->createMock(originalClassName: MessagingServiceAccount::class);
		$this->account->method('runAs')->willReturnCallback(
			function (callable $operation): mixed {
				$this->runAs++;
				return $operation();
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return WebChatService
	 */
	private function service(): WebChatService {
		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('the-visitor-secret');
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => (new \DateTime())->setTimestamp($this->now));

		$routing = $this->createMock(originalClassName: RoutingService::class);
		$routing->method('filterByAvailability')->willReturnCallback(
			static fn (array $profiles): array => array_values(array_filter(
				$profiles,
				static fn (array $p): bool => ($p['isAvailable'] ?? true) !== false,
			))
		);

		$logger    = $this->createMock(originalClassName: LoggerInterface::class);
		$assistant = new ChatAssistant(container: $this->container, logger: $logger);
		$messages  = new WebChatMessages(store: $this->store, time: $time);

		$records = new WebChatRecords(
			store: $this->store,
			messages: $messages,
			access: new WebChatAccess(store: $this->store, logger: $logger, random: $random),
			window: new WebChatWindow(store: $this->store, messages: $messages, assistant: $assistant, routing: $routing),
		);

		return new WebChatService(
			records: $records,
			handover: new WebChatHandover(
				messages: $messages,
				inbound: $this->inbound,
				tickets: $this->tickets,
				logger: $logger,
			),
			writes: new WebChatVisitorWrites(account: $this->account, logger: $logger),
			turns: new WebChatAssistantTurn(assistant: $assistant, store: $this->store, messages: $messages),
		);
	}//end service()

	/**
	 * hermiq's entry point answers with these replies, in turn.
	 *
	 * @param list<array<string, mixed>> $replies The replies.
	 *
	 * @return object The entry point, which records the questions it was asked.
	 */
	private function withAssistant(array $replies): object {
		$this->store->channels['ch-1']['assistantEnabled'] = true;
		$entryPoint = new class($replies) {
			/**
			 * The questions asked, with their arguments.
			 *
			 * @var list<array<string, string>>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param list<array<string, mixed>> $replies The replies.
			 */
			public function __construct(private array $replies) {
			}

			/**
			 * Answer the next reply.
			 *
			 * @param string $channelKey        The channel key.
			 * @param string $question          The question.
			 * @param string $locale            The language.
			 * @param string $sourceScope       The scope.
			 * @param string $conversationToken The token.
			 *
			 * @return array<string, mixed>
			 */
			public function ask(string $channelKey, string $question, string $locale, string $sourceScope, string $conversationToken): array {
				$this->asked[] = compact('channelKey', 'question', 'locale', 'sourceScope', 'conversationToken');
				return array_shift($this->replies);
			}
		};
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->container->method('get')->with(ChatAssistant::ENTRY_POINT)->willReturn($entryPoint);
		return $entryPoint;
	}//end withAssistant()

	/**
	 * Start a chat; answers the conversation id.
	 *
	 * @param WebChatService $service The service.
	 *
	 * @return string
	 */
	private function started(WebChatService $service): string {
		return $service->start(token: self::TOKEN)['conversation'];
	}//end started()

	/**
	 * A start stores a hash of the secret, never the secret, and no address, as the messaging account.
	 *
	 * @return void
	 */
	public function testAStartStoresOnlyTheHashOfTheSecret(): void {
		$start = $this->service()->start(token: self::TOKEN);

		$this->assertSame(expected: 'the-visitor-secret', actual: $start['secret']);
		$this->assertSame(expected: 'person', actual: $start['mode']);
		$this->assertSame(expected: 'Waarmee kunnen we helpen?', actual: $start['greeting']);
		$stored = $this->store->conversations[$start['conversation']];
		$this->assertSame(expected: hash('sha256', 'the-visitor-secret'), actual: $stored['visitorSecretHash']);
		$this->assertSame(
			expected: ['chat', 'open', 'ch-1', 0],
			actual: [$stored['channel'], $stored['status'], $stored['webChatChannel'], $stored['unsourcedAnswers']],
		);
		$this->assertNotContains(needle: 'the-visitor-secret', haystack: $stored);
		$this->assertArrayNotHasKey(key: 'ipAddress', array: $stored);
		$this->assertSame(expected: 1, actual: $this->runAs);
	}//end testAStartStoresOnlyTheHashOfTheSecret()

	/**
	 * An inactive or unknown channel refuses a start.
	 *
	 * @return void
	 */
	public function testAnInactiveChannelRefuses(): void {
		$this->store->channels['ch-1']['active'] = false;

		try {
			$this->service()->start(token: self::TOKEN);
			$this->fail(message: 'An inactive channel must refuse.');
		} catch (WebChatRefusal $e) {
			$this->assertSame(expected: 404, actual: $e->getStatus());
		}

		$this->assertSame(expected: [], actual: $this->store->conversations);
	}//end testAnInactiveChannelRefuses()

	/**
	 * A wrong secret, or a chat of another channel, is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testAWrongSecretRefuses(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);

		foreach (['wrong', ''] as $secret) {
			try {
				$service->send(token: self::TOKEN, conversationId: $id, secret: $secret, turn: ['text' => 'Hallo']);
				$this->fail(message: 'A wrong secret must refuse.');
			} catch (WebChatRefusal $e) {
				$this->assertSame(expected: 403, actual: $e->getStatus());
			}
		}

		$this->store->conversations[$id]['webChatChannel'] = 'ch-other';
		try {
			$service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret');
			$this->fail(message: 'A chat of another channel must refuse.');
		} catch (WebChatRefusal $e) {
			$this->assertSame(expected: 404, actual: $e->getStatus());
		}

		$this->assertSame(expected: [], actual: $this->store->messages);
		$this->assertSame(expected: [], actual: $this->received);
	}//end testAWrongSecretRefuses()

	/**
	 * Without an assistant the first message hands over: one request ticket with the transcript.
	 *
	 * @return void
	 */
	public function testWithoutAnAssistantTheFirstMessageGoesToAPerson(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);

		$view = $service->send(
			token: self::TOKEN,
			conversationId: $id,
			secret: 'the-visitor-secret',
			turn: ['text' => 'Kan ik zaterdag mijn paspoort ophalen?'],
		);

		$this->assertCount(expectedCount: 1, haystack: $this->received);
		$this->assertSame(
			expected: [
				'channel'   => 'chat',
				'threadKey' => 'conversation:' . $id,
				'title'     => 'Kan ik zaterdag mijn paspoort ophalen?',
				'text'      => 'Visitor: Kan ik zaterdag mijn paspoort ophalen?',
			],
			actual: $this->received[0]
		);
		$this->assertSame(expected: 'ticket-1', actual: $this->store->conversations[$id]['ticket']);
		$this->assertSame(expected: 'waiting', actual: $view['state']);
		$this->assertSame(
			expected: [[
				'id' => 'msg-2',
				'author' => 'visitor',
				'body' => 'Kan ik zaterdag mijn paspoort ophalen?',
				'at' => $this->store->messages[0]['sentAt'],
			]],
			actual: $view['messages'],
		);
		$this->assertSame(
			expected: ['inbound', 'chat', 'visitor'],
			actual: [$this->store->messages[0]['direction'], $this->store->messages[0]['channel'], $this->store->messages[0]['metadata']['author']],
		);
	}//end testWithoutAnAssistantTheFirstMessageGoesToAPerson()

	/**
	 * A second press of Talk to a person makes no second ticket; a follow-up is added to the ticket.
	 *
	 * @return void
	 */
	public function testASecondHandoverMakesNoSecondTicket(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['handover' => true, 'text' => 'Een mens graag']);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['handover' => true]);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Nog een vraag']);

		$this->assertCount(expectedCount: 2, haystack: $this->received);
		$this->assertArrayHasKey(key: 'title', array: $this->received[0]);
		$this->assertSame(
			expected: ['channel' => 'chat', 'threadKey' => 'conversation:' . $id, 'text' => 'Nog een vraag'],
			actual: $this->received[1],
		);
	}//end testASecondHandoverMakesNoSecondTicket()

	/**
	 * The assistant answers with its sources and disclosure, under the chat's token and channel key.
	 *
	 * @return void
	 */
	public function testTheAssistantAnswersFirstWithItsSources(): void {
		$entryPoint = $this->withAssistant(
			replies: [
				[
					'answer'            => 'Op zaterdag van 9 tot 12 uur.',
					'sources'           => [
						['title' => 'Paspoort ophalen', 'url' => 'https://www.gemeente.nl/paspoort'],
						['title' => 'no link', 'url' => 'javascript:alert(1)'],
					],
					'conversationToken' => 'h-token',
					'disclosure'        => 'Dit antwoord komt van een AI-assistent.',
				],
			]
		);
		$service = $this->service();
		$start   = $service->start(token: self::TOKEN);
		$this->assertSame(expected: 'assistant', actual: $start['mode']);

		$view = $service->send(
			token: self::TOKEN,
			conversationId: $start['conversation'],
			secret: 'the-visitor-secret',
			turn: ['text' => 'Wanneer kan ik mijn paspoort ophalen?', 'locale' => 'nl'],
		);

		$this->assertSame(expected: [], actual: $this->received);
		$this->assertSame(expected: 'assistant', actual: $view['state']);
		$answer = $view['messages'][1];
		$this->assertSame(expected: 'assistant', actual: $answer['author']);
		$this->assertSame(expected: 'Op zaterdag van 9 tot 12 uur.', actual: $answer['body']);
		$this->assertSame(expected: [['title' => 'Paspoort ophalen', 'url' => 'https://www.gemeente.nl/paspoort']], actual: $answer['sources']);
		$this->assertSame(expected: 'Dit antwoord komt van een AI-assistent.', actual: $answer['disclosure']);
		$this->assertSame(expected: 'h-token', actual: $this->store->conversations[$start['conversation']]['assistantToken']);
		$this->assertSame(
			expected: [
				'channelKey' => 'app:pipelinq:ch-1',
				'question' => 'Wanneer kan ik mijn paspoort ophalen?',
				'locale' => 'nl',
				'sourceScope' => 'public',
				'conversationToken' => '',
			],
			actual: $entryPoint->asked[0]
		);
	}//end testTheAssistantAnswersFirstWithItsSources()

	/**
	 * Two answers in a row without a source hand the chat over; a sourced answer in between resets the count.
	 *
	 * @return void
	 */
	public function testTwoUnsourcedAnswersInARowHandOver(): void {
		$this->withAssistant(
			replies: [
				['answer' => 'Dat weet ik niet.', 'sources' => []],
				['answer' => 'Zie de site.', 'sources' => [['title' => 'Site', 'url' => 'https://www.gemeente.nl']]],
				['answer' => 'Dat weet ik niet.', 'sources' => []],
				['answer' => 'Dat weet ik ook niet.', 'sources' => []],
			]
		);
		$service = $this->service();
		$id      = $this->started(service: $service);
		$send    = fn (string $text): array => $service->send(
			token: self::TOKEN,
			conversationId: $id,
			secret: 'the-visitor-secret',
			turn: ['text' => $text],
		);

		$send('Een');
		$send('Twee');
		$send('Drie');
		$this->assertSame(expected: [], actual: $this->received, message: 'A sourced answer in between resets the count.');
		$view = $send('Vier');

		$this->assertCount(expectedCount: 1, haystack: $this->received);
		$this->assertSame(expected: 'Een', actual: $this->received[0]['title']);
		$this->assertStringContainsString(needle: "Visitor: Vier\nAssistant: Dat weet ik ook niet.", haystack: $this->received[0]['text']);
		$this->assertSame(expected: 'waiting', actual: $view['state']);
	}//end testTwoUnsourcedAnswersInARowHandOver()

	/**
	 * With every agent profile unavailable the chat opens offline, and an address goes on the ticket.
	 *
	 * @return void
	 */
	public function testNobodyAvailableAsksForAnAddress(): void {
		$this->store->profiles = [['isAvailable' => false], ['isAvailable' => false]];
		$service = $this->service();
		$start   = $service->start(token: self::TOKEN);
		$this->assertSame(expected: 'offline', actual: $start['mode']);
		$this->assertSame(expected: 'Laat uw e-mailadres achter.', actual: $start['offlineText']);

		$service->send(
			token: self::TOKEN,
			conversationId: $start['conversation'],
			secret: 'the-visitor-secret',
			turn: ['text' => 'Mijn vraag', 'email' => 'inwoner@example.org'],
		);

		$this->assertSame(expected: 'inwoner@example.org', actual: $this->received[0]['fromAddress']);

		try {
			$service->send(
				token: self::TOKEN,
				conversationId: $start['conversation'],
				secret: 'the-visitor-secret',
				turn: ['email' => 'not-an-address'],
			);
			$this->fail(message: 'An invalid address must refuse.');
		} catch (WebChatRefusal $e) {
			$this->assertSame(expected: 400, actual: $e->getStatus());
		}
	}//end testNobodyAvailableAsksForAnAddress()

	/**
	 * One available profile is enough for a person to answer.
	 *
	 * @return void
	 */
	public function testOneAvailableProfileIsEnough(): void {
		$this->store->profiles = [['isAvailable' => false], ['isAvailable' => true]];

		$this->assertSame(expected: 'person', actual: $this->service()->start(token: self::TOKEN)['mode']);
	}//end testOneAvailableProfileIsEnough()

	/**
	 * A handed-over chat nobody takes within the wait time is waited out; after thirty idle minutes it ends.
	 *
	 * @return void
	 */
	public function testAChatWaitsOutAndThenEnds(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Hallo']);

		$this->now += 179;
		$this->assertSame(
			expected: 'waiting',
			actual: $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret')['state'],
		);
		$this->now += 1;
		$this->assertSame(
			expected: 'offline',
			actual: $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret')['state'],
		);
		$this->now += 1800;
		$this->assertSame(expected: 'closed', actual: $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret')['state']);
		$this->assertSame(expected: 'closed', actual: $this->store->conversations[$id]['status']);
	}//end testAChatWaitsOutAndThenEnds()

	/**
	 * A poll answers only the messages after the time the window has.
	 *
	 * @return void
	 */
	public function testAPollAnswersOnlyNewerMessages(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Eerste']);
		$since = $this->store->messages[0]['sentAt'];
		$this->now += 5;
		$this->store->readable = [$id];
		$service->reply(conversationId: $id, userId: 'anna', displayName: 'Anna de Vries', text: 'Ik kijk het na');

		$messages = $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', since: $since)['messages'];

		$this->assertSame(expected: ['Ik kijk het na'], actual: array_column($messages, 'body'));
		$this->assertSame(expected: 'agent', actual: $messages[0]['author']);
		$this->assertArrayNotHasKey(key: 'agentName', array: $messages[0], message: 'The first name shows only when the channel says so.');

		$this->store->channels['ch-1']['showAgentFirstName'] = true;
		$messages = $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', since: $since)['messages'];
		$this->assertSame(expected: 'Anna', actual: $messages[0]['agentName']);
	}//end testAPollAnswersOnlyNewerMessages()

	/**
	 * The colleague's thread says per message which side it is on and who said it,
	 * for the conversation widget on the ticket.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function testTheColleaguesThreadNamesTheSideAndWhoSaidIt(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Eerste']);
		$this->now += 5;
		$this->store->readable = [$id];
		$service->reply(conversationId: $id, userId: 'anna', displayName: 'Anna de Vries', text: 'Ik kijk het na');

		$lines = $service->agentView(conversationId: $id)['messages'];

		$this->assertSame(expected: ['them', 'us'], actual: array_column($lines, 'side'));
		$this->assertSame(expected: ['Visitor', 'Anna'], actual: array_column($lines, 'authorName'));
		$this->assertSame(expected: ['Eerste', 'Ik kijk het na'], actual: array_column($lines, 'body'));
	}//end testTheColleaguesThreadNamesTheSideAndWhoSaidIt()

	/**
	 * Taking a chat assigns it and its ticket to the colleague, which takes the ticket out of the Queue.
	 *
	 * @return void
	 */
	public function testTakingAChatAssignsItAndItsTicket(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Hallo']);
		$this->store->readable = [$id];

		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['id' => 'ticket-1', 'ticketType' => 'request', 'title' => 'Hallo', 'status' => 'new']);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->once())->method('find')->with('ticket-1', $this->anything(), true, true)->willReturn($entity);
		$this->tickets->method('getObjectService')->willReturn($objects);
		$this->tickets->expects($this->once())->method('save')->with(
			'request',
			['ticketType' => 'request', 'title' => 'Hallo', 'status' => 'in_progress', 'assignee' => 'anna'],
			'ticket-1',
		);

		$view = $service->take(conversationId: $id, userId: 'anna');

		$this->assertSame(expected: ['assigned', 'anna', 'ticket-1'], actual: [$view['status'], $view['assignedTo'], $view['ticket']]);
		$this->assertSame(
			expected: 'with-agent',
			actual: $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret')['state'],
		);
	}//end testTakingAChatAssignsItAndItsTicket()

	/**
	 * A colleague who may not read the chat cannot view, take, reply to or end it.
	 *
	 * @return void
	 */
	public function testAColleagueWhoMayNotReadTheChatIsRefused(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);

		foreach (['agentView', 'take', 'reply', 'end'] as $verb) {
			try {
				match ($verb) {
					'agentView' => $service->agentView(conversationId: $id),
					'take'      => $service->take(conversationId: $id, userId: 'eve'),
					'reply'     => $service->reply(conversationId: $id, userId: 'eve', displayName: 'Eve', text: 'Hoi'),
					'end'       => $service->end(conversationId: $id),
				};
				$this->fail(message: $verb . ' must refuse.');
			} catch (WebChatRefusal $e) {
				$this->assertSame(expected: 404, actual: $e->getStatus(), message: $verb);
			}
		}

		$this->assertSame(expected: [], actual: $this->store->messages);
		$this->assertSame(expected: 'open', actual: $this->store->conversations[$id]['status']);
	}//end testAColleagueWhoMayNotReadTheChatIsRefused()

	/**
	 * Ending a chat closes it for the visitor, refuses more messages, and leaves the ticket alone.
	 *
	 * @return void
	 */
	public function testEndingAChatClosesItAndKeepsTheTicket(): void {
		$service = $this->service();
		$id      = $this->started(service: $service);
		$this->store->readable = [$id];
		$this->tickets->expects($this->never())->method('save');

		$this->assertSame(expected: 'closed', actual: $service->end(conversationId: $id)['status']);
		$this->assertSame(expected: 'closed', actual: $service->poll(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret')['state']);

		try {
			$service->send(token: self::TOKEN, conversationId: $id, secret: 'the-visitor-secret', turn: ['text' => 'Nog iets']);
			$this->fail(message: 'An ended chat must refuse.');
		} catch (WebChatRefusal $e) {
			$this->assertSame(expected: 409, actual: $e->getStatus());
		}
	}//end testEndingAChatClosesItAndKeepsTheTicket()

	/**
	 * Without the messaging account a visitor's write is refused as unavailable.
	 *
	 * @return void
	 */
	public function testWithoutTheMessagingAccountTheChatIsUnavailable(): void {
		$this->account = $this->createMock(originalClassName: MessagingServiceAccount::class);
		$this->account->method('runAs')->willThrowException(new ServiceAccountUnavailableException(message: 'no account'));

		try {
			$this->service()->start(token: self::TOKEN);
			$this->fail(message: 'A start without the account must refuse.');
		} catch (WebChatRefusal $e) {
			$this->assertSame(expected: 503, actual: $e->getStatus());
		}
	}//end testWithoutTheMessagingAccountTheChatIsUnavailable()
}//end class
