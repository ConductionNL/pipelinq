<?php

/**
 * Unit tests for InboundTicketService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-a-follow-up-stays-on-its-ticket-req-itq-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A new thread opens one ticket in the Queue; its follow-ups stay on it.
 *
 * @covers \OCA\Pipelinq\Service\InboundTicketService
 */
class InboundTicketServiceTest extends TestCase {

	/**
	 * The stored tickets by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every lookup: its filters and whether it applied RBAC and multitenancy.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lookups = [];

	/**
	 * Every save: type, payload, uuid.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service over an in-memory ticket store.
	 *
	 * @param bool $lookupFails Whether the open-ticket lookup throws.
	 *
	 * @return InboundTicketService The service.
	 */
	private function service(bool $lookupFails = false): InboundTicketService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true) use ($lookupFails): array {
				$this->lookups[] = ['filters' => $config['filters'], '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($lookupFails === true) {
					throw new RuntimeException('database gone');
				}

				return array_values(array_filter(
					$this->store,
					static fn (array $t): bool => ($t['inboundThreadKey'] ?? null) === $config['filters']['inboundThreadKey']
				));
			}
		);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getObjectService')->willReturn($objects);
		$tickets->method('getRegisterId')->willReturn('reg-1');
		$tickets->method('getSchemaId')->willReturn('sch-ticket');
		$tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload, ?string $uuid = null): object {
				$this->saves[] = ['type' => $ticketType, 'payload' => $payload, 'uuid' => $uuid];
				$uuid = ($uuid ?? 'ticket-' . (count($this->store) + 1));
				$this->store[$uuid] = ['id' => $uuid, 'ticketType' => $ticketType] + $payload;
				return new class($this->store[$uuid]) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $data The object data.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * The object data.
					 *
					 * @return array<string, mixed> The data.
					 */
					public function getObject(): array {
						return $this->data;
					}
				};
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T12:00:00+00:00'));
		$this->logger = $this->createMock(LoggerInterface::class);

		return new InboundTicketService(tickets: $tickets, timeFactory: $time, logger: $this->logger);
	}//end service()

	/**
	 * A WhatsApp message on a new thread opens one request ticket in the Queue: new, unassigned, normal.
	 *
	 * @return void
	 */
	public function testANewThreadOpensOneUnassignedRequestTicket(): void {
		$result = $this->service()->receive(
			message: [
				'channel' => 'whatsapp',
				'threadKey' => 'conversation:c-1',
				'contact' => 'fatima',
				'client' => '',
				'text' => '  Is my permit ready?  ',
				'fromAddress' => '+31611119999',
				'fromName' => 'Fatima',
				'mailbox' => '',
			]
		);

		$this->assertSame(['ticket' => 'ticket-1', 'created' => true, 'status' => 'new'], $result);
		$this->assertCount(1, $this->saves);
		$this->assertSame('request', $this->saves[0]['type']);
		$this->assertNull($this->saves[0]['uuid']);
		$this->assertSame(
			[
				'title' => 'Is my permit ready?',
				'description' => 'Is my permit ready?',
				'status' => 'new',
				'priority' => 'normal',
				'channel' => 'whatsapp',
				'inboundThreadKey' => 'conversation:c-1',
				'channelMetadata' => [
					'messages' => [['at' => '2026-11-13T12:00:00+00:00', 'text' => 'Is my permit ready?', 'from' => 'Fatima']],
					'lastMessageAt' => '2026-11-13T12:00:00+00:00',
					'fromAddress' => '+31611119999',
					'fromName' => 'Fatima',
				],
				'contact' => 'fatima',
			],
			$this->saves[0]['payload']
		);
		$this->assertArrayNotHasKey('assignee', $this->saves[0]['payload']);
		$this->assertSame(
			[['filters' => ['register' => 'reg-1', 'schema' => 'sch-ticket', 'inboundThreadKey' => 'conversation:c-1'], '_rbac' => false, '_multitenancy' => false]],
			$this->lookups
		);
	}//end testANewThreadOpensOneUnassignedRequestTicket()

	/**
	 * The same thread twice is one ticket with both messages; a waiting ticket goes back to work.
	 *
	 * @return void
	 */
	public function testAFollowUpStaysOnItsTicketAndMovesItBackToWork(): void {
		$service = $this->service();
		$service->receive(message: ['channel' => 'sms', 'threadKey' => 'conversation:c-2', 'text' => 'Hello']);
		$this->store['ticket-1']['status'] = 'awaiting_customer';

		$result = $service->receive(message: ['channel' => 'sms', 'threadKey' => 'conversation:c-2', 'text' => 'Here is the answer', 'fromAddress' => '+3161']);

		$this->assertSame(['ticket' => 'ticket-1', 'created' => false, 'status' => 'in_progress'], $result);
		$this->assertCount(1, $this->store);
		$this->assertSame('ticket-1', $this->saves[1]['uuid']);
		$this->assertSame('request', $this->saves[1]['type']);
		$this->assertArrayNotHasKey('id', $this->saves[1]['payload']);
		$this->assertSame('in_progress', $this->store['ticket-1']['status']);
		$this->assertSame(
			['Hello', 'Here is the answer'],
			array_column($this->store['ticket-1']['channelMetadata']['messages'], 'text')
		);
		$this->assertSame('+3161', $this->store['ticket-1']['channelMetadata']['messages'][1]['from']);
	}//end testAFollowUpStaysOnItsTicketAndMovesItBackToWork()

	/**
	 * A follow-up on an open ticket that is not waiting keeps its status.
	 *
	 * @return void
	 */
	public function testAFollowUpKeepsAnInProgressStatus(): void {
		$this->store['t-9'] = ['id' => 't-9', 'ticketType' => 'complaint', 'status' => 'in_progress', 'inboundThreadKey' => 'mail:a:1', 'channelMetadata' => 'broken'];

		$result = $this->service()->receive(message: ['channel' => 'email', 'threadKey' => 'mail:a:1', 'text' => 'Re: invoice']);

		$this->assertSame(['ticket' => 't-9', 'created' => false, 'status' => 'in_progress'], $result);
		$this->assertSame('complaint', $this->saves[0]['type']);
		$this->assertSame(['Re: invoice'], array_column($this->store['t-9']['channelMetadata']['messages'], 'text'));
	}//end testAFollowUpKeepsAnInProgressStatus()

	/**
	 * A thread whose ticket is closed opens a new ticket.
	 *
	 * @return void
	 */
	public function testAClosedTicketsThreadOpensANewTicket(): void {
		foreach (InboundTicketService::CLOSED_STATUSES as $i => $status) {
			$this->store['old-' . $i] = ['id' => 'old-' . $i, 'status' => $status, 'inboundThreadKey' => 'conversation:c-3'];
		}

		$result = $this->service()->receive(message: ['channel' => 'whatsapp', 'threadKey' => 'conversation:c-3', 'text' => 'New question']);

		$this->assertTrue($result['created']);
		$this->assertNull($this->saves[0]['uuid']);
	}//end testAClosedTicketsThreadOpensANewTicket()

	/**
	 * A ticket keeps the last messages only, and a long text is cut and titled short.
	 *
	 * @return void
	 */
	public function testMessagesAreCappedAndALongTextGetsAShortTitle(): void {
		$service = $this->service();
		$service->receive(message: ['channel' => 'sms', 'threadKey' => 'k', 'text' => str_repeat('word ', 30)]);
		for ($i = 1; $i <= InboundTicketService::KEPT_MESSAGES + 5; $i++) {
			$service->receive(message: ['channel' => 'sms', 'threadKey' => 'k', 'text' => 'm' . $i]);
		}

		$messages = $this->store['ticket-1']['channelMetadata']['messages'];
		$this->assertCount(InboundTicketService::KEPT_MESSAGES, $messages);
		$this->assertSame('m' . (InboundTicketService::KEPT_MESSAGES + 5), end($messages)['text']);
		$this->assertSame(80, mb_strlen($this->store['ticket-1']['title']));
		$this->assertStringEndsWith('…', $this->store['ticket-1']['title']);
	}//end testMessagesAreCappedAndALongTextGetsAShortTitle()

	/**
	 * A mail subject is the title; a message without text or subject is titled by its channel.
	 *
	 * @return void
	 */
	public function testTheTitleIsTheSubjectOrTheChannel(): void {
		$service = $this->service();
		$service->receive(message: ['channel' => 'email', 'threadKey' => 'a', 'title' => 'Invoice 2026-114', 'text' => 'Dear team']);
		$service->receive(message: ['channel' => 'facebook', 'threadKey' => 'b']);

		$this->assertSame('Invoice 2026-114', $this->store['ticket-1']['title']);
		$this->assertSame('Message via facebook', $this->store['ticket-2']['title']);
	}//end testTheTitleIsTheSubjectOrTheChannel()

	/**
	 * Without a channel or a thread key nothing is looked up or written.
	 *
	 * @return void
	 */
	public function testAMessageNeedsAChannelAndAThread(): void {
		$service = $this->service();
		foreach ([['channel' => 'sms'], ['threadKey' => 'k'], ['channel' => ' ', 'threadKey' => 'k']] as $message) {
			try {
				$service->receive(message: $message);
				$this->fail('accepted ' . json_encode($message));
			} catch (InvalidArgumentException $e) {
				$this->assertSame('An inbound message needs a channel and a thread key.', $e->getMessage());
			}
		}

		$this->assertSame([], $this->lookups);
		$this->assertSame([], $this->saves);
	}//end testAMessageNeedsAChannelAndAThread()

	/**
	 * A failed lookup fails the intake rather than opening a second ticket; tryReceive logs it and answers ''.
	 *
	 * @return void
	 */
	public function testAFailedLookupOpensNoTicket(): void {
		$service = $this->service(lookupFails: true);
		$this->logger->expects($this->once())->method('error')->with(
			'InboundTicketService: the inbound message was stored but did not reach a ticket',
			$this->callback(static fn (array $context): bool => $context['channel'] === 'sms' && $context['threadKey'] === 'k' && str_contains($context['exception'], 'database gone'))
		);

		$this->assertSame('', $service->tryReceive(message: ['channel' => 'sms', 'threadKey' => 'k', 'text' => 'x']));
		$this->assertSame([], $this->saves);

		$this->expectException(RuntimeException::class);
		$service->receive(message: ['channel' => 'sms', 'threadKey' => 'k']);
	}//end testAFailedLookupOpensNoTicket()

	/**
	 * tryReceive answers the ticket a message landed on.
	 *
	 * @return void
	 */
	public function testTryReceiveAnswersTheTicket(): void {
		$this->assertSame('ticket-1', $this->service()->tryReceive(message: ['channel' => 'sms', 'threadKey' => 'k', 'text' => 'x']));
	}//end testTryReceiveAnswersTheTicket()
}//end class
