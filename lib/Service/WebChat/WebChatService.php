<?php

/**
 * A website chat: a visitor without an account, the assistant first, a colleague through the Queue.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebChat
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

namespace OCA\Pipelinq\Service\WebChat;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The visitor side (start, send, poll) runs for an anonymous caller: it
 * proves a chat by the secret only the visitor's window holds, stores a hash
 * of that secret and no address, and writes as the messaging service account.
 * The colleague side (view, take, reply, end) runs as the colleague, through
 * OpenRegister's checks: a conversation the colleague may not read is not there.
 *
 * Handover (D5) makes the chat a ticket through InboundTicketService, so the
 * ticket is in the Queue at once and the chatWaiting rule notifies the team.
 */
class WebChatService {

	/**
	 * The longest message a chat keeps.
	 *
	 * @var int
	 */
	public const TEXT_LIMIT = 2000;

	/**
	 * Answers in a row without a source after which the assistant hands over (D5).
	 *
	 * @var int
	 */
	public const UNSOURCED_LIMIT = 2;

	/**
	 * Seconds without a message after which a chat ends (D8).
	 *
	 * @var int
	 */
	public const IDLE_SECONDS = 1800;

	/**
	 * The default wait for a colleague, in seconds (D2).
	 *
	 * @var int
	 */
	public const DEFAULT_WAIT = 180;

	/**
	 * Constructor.
	 *
	 * @param WebChatStore            $store     Where the chat is kept.
	 * @param ChatAssistant           $assistant The assistant that may answer first.
	 * @param InboundTicketService    $inbound   The intake that makes a handed-over chat a ticket.
	 * @param TicketServiceInterface  $tickets   The ticket a colleague takes.
	 * @param RoutingService          $routing   Which agent profiles are available.
	 * @param MessagingServiceAccount $account   The account a visitor's writes run as.
	 * @param ISecureRandom           $random    The visitor's chat secret.
	 * @param ITimeFactory            $time      The time a message is recorded at.
	 * @param LoggerInterface         $logger    Where a failed write is logged.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly ChatAssistant $assistant,
		private readonly InboundTicketService $inbound,
		private readonly TicketServiceInterface $tickets,
		private readonly RoutingService $routing,
		private readonly MessagingServiceAccount $account,
		private readonly ISecureRandom $random,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Start a chat on an active channel.
	 *
	 * @param string $token The channel token.
	 *
	 * @return array{conversation: string, secret: string, mode: string, greeting: string, offlineText: string}
	 *
	 * @throws WebChatRefusal When the channel is not active or the chat cannot be stored.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function start(string $token): array {
		$channel = $this->channelOrRefuse(token: $token);
		$secret  = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$now     = $this->now();

		$conversation = $this->asVisitor(
			writes: fn (): array => $this->store->saveConversation(
				conversation: [
					'channel'           => 'chat',
					'status'            => 'open',
					'webChatChannel'    => $this->store->idOf(row: $channel),
					'visitorSecretHash' => self::hashOf(secret: $secret),
					'lastMessageAt'     => $now,
					'lastInboundAt'     => $now,
					'unsourcedAnswers'  => 0,
				]
			)
		);

		return [
			'conversation' => $this->store->idOf(row: $conversation),
			'secret'       => $secret,
			'mode'         => $this->startMode(channel: $channel),
			'greeting'     => (string)($channel['greeting'] ?? ''),
			'offlineText'  => (string)($channel['offlineText'] ?? ''),
		];
	}//end start()

	/**
	 * A visitor's message, a request for a person, or an address to be answered at.
	 *
	 * @param string               $token          The channel token.
	 * @param string               $conversationId The chat.
	 * @param string               $secret         The visitor's chat secret.
	 * @param array<string, mixed> $turn           `text` (the message, '' for none), `email` (an address to be
	 *                                             answered at by mail, '' for none), `handover` (whether the
	 *                                             visitor asks for a person) and `locale` (their language).
	 *
	 * @return array{state: string, messages: list<array<string, mixed>>, offlineText: string}
	 *
	 * @throws WebChatRefusal When the chat is unknown, the secret is wrong, or the message is empty or invalid.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.1
	 */
	public function send(string $token, string $conversationId, string $secret, array $turn): array {
		[$channel, $conversation] = $this->chatOrRefuse(token: $token, conversationId: $conversationId, secret: $secret);
		$text     = (string)($turn['text'] ?? '');
		$email    = (string)($turn['email'] ?? '');
		$handover = (($turn['handover'] ?? false) === true);
		$locale   = (string)($turn['locale'] ?? 'nl');
		if (($conversation['status'] ?? 'open') === 'closed') {
			throw new WebChatRefusal(message: 'This chat has ended.', status: 409);
		}

		$text  = mb_substr(trim($text), 0, self::TEXT_LIMIT, 'UTF-8');
		$email = trim($email);
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new WebChatRefusal(message: 'This email address is not valid.', status: 400);
		}

		if ($text === '' && $email === '' && $handover === false) {
			throw new WebChatRefusal(message: 'Type a message first.', status: 400);
		}

		$turn = ['text' => $text, 'email' => $email, 'handover' => $handover, 'locale' => $locale];
		$this->asVisitor(
			writes: function () use ($channel, $conversation, $conversationId, $turn): array {
				$this->visitorTurn(channel: $channel, conversation: $conversation, conversationId: $conversationId, turn: $turn);
				return [];
			}
		);

		return $this->view(channel: $channel, conversationId: $conversationId, since: '');
	}//end send()

	/**
	 * The messages after a time and the state of the chat, for the visitor's window.
	 *
	 * @param string $token          The channel token.
	 * @param string $conversationId The chat.
	 * @param string $secret         The visitor's chat secret.
	 * @param string $since          The time of the last message the window has, '' for all.
	 *
	 * @return array{state: string, messages: list<array<string, mixed>>, offlineText: string}
	 *
	 * @throws WebChatRefusal When the chat is unknown or the secret is wrong.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function poll(string $token, string $conversationId, string $secret, string $since=''): array {
		[$channel, $conversation] = $this->chatOrRefuse(token: $token, conversationId: $conversationId, secret: $secret);
		if (($conversation['status'] ?? 'open') !== 'closed' && $this->idle(conversation: $conversation) === true) {
			$conversation['status'] = 'closed';
			$this->asVisitor(writes: fn (): array => $this->store->saveConversation(conversation: $conversation, id: $conversationId));
		}

		return $this->view(channel: $channel, conversationId: $conversationId, since: $since, conversation: $conversation);
	}//end poll()

	/**
	 * A chat as the colleague on its ticket sees it.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array{conversation: string, status: string, assignedTo: string, ticket: string, messages: list<array<string, mixed>>}
	 *
	 * @throws WebChatRefusal When the colleague may not read this chat.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function agentView(string $conversationId): array {
		$conversation = $this->readableOrRefuse(conversationId: $conversationId);
		return [
			'conversation' => $conversationId,
			'status'       => (string)($conversation['status'] ?? 'open'),
			'assignedTo'   => (string)($conversation['assignedTo'] ?? ''),
			'ticket'       => (string)($conversation['ticket'] ?? ''),
			'messages'     => array_map(
				fn (array $message): array => $this->messageView(message: $message, showAgentName: true),
				$this->store->messages(conversationId: $conversationId)
			),
		];
	}//end agentView()

	/**
	 * The colleague takes the chat: the conversation and its ticket are theirs, and the ticket leaves the Queue.
	 *
	 * @param string $conversationId The chat.
	 * @param string $userId         The colleague.
	 *
	 * @return array{conversation: string, status: string, assignedTo: string, ticket: string, messages: list<array<string, mixed>>}
	 *
	 * @throws WebChatRefusal When the colleague may not read this chat or it has ended.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function take(string $conversationId, string $userId): array {
		$conversation = $this->openReadableOrRefuse(conversationId: $conversationId);
		$conversation['assignedTo'] = $userId;
		$conversation['status']     = 'assigned';
		$this->store->saveConversation(conversation: $conversation, id: $conversationId);

		$ticketId = (string)($conversation['ticket'] ?? '');
		if ($ticketId !== '') {
			$this->updateTicket(ticketId: $ticketId, rbac: true, change: static function (array $ticket) use ($userId): array {
				$ticket['assignee'] = $userId;
				$ticket['status']   = 'in_progress';
				return $ticket;
			});
		}

		return $this->agentView(conversationId: $conversationId);
	}//end take()

	/**
	 * The colleague's answer, shown in the visitor's window.
	 *
	 * @param string $conversationId The chat.
	 * @param string $userId         The colleague.
	 * @param string $displayName    The colleague's name; the window shows the first name when the channel says so.
	 * @param string $text           The answer.
	 *
	 * @return array{conversation: string, status: string, assignedTo: string, ticket: string, messages: list<array<string, mixed>>}
	 *
	 * @throws WebChatRefusal When the colleague may not read this chat, it has ended, or the answer is empty.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function reply(string $conversationId, string $userId, string $displayName, string $text): array {
		$conversation = $this->openReadableOrRefuse(conversationId: $conversationId);
		$text         = mb_substr(trim($text), 0, self::TEXT_LIMIT, 'UTF-8');
		if ($text === '') {
			throw new WebChatRefusal(message: 'Type a message first.', status: 400);
		}

		$firstName = trim((string)strtok(trim($displayName), ' '));
		$this->store->addMessage(
			message: $this->message(
				conversationId: $conversationId,
				direction: 'outbound',
				body: $text,
				metadata: ['author' => 'agent', 'agent' => $userId, 'agentFirstName' => $firstName]
			)
		);
		$conversation['lastMessageAt'] = $this->now();
		$this->store->saveConversation(conversation: $conversation, id: $conversationId);

		return $this->agentView(conversationId: $conversationId);
	}//end reply()

	/**
	 * The colleague ends the chat; the ticket stays as it is (D8).
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array{conversation: string, status: string, assignedTo: string, ticket: string, messages: list<array<string, mixed>>}
	 *
	 * @throws WebChatRefusal When the colleague may not read this chat.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function end(string $conversationId): array {
		$conversation = $this->readableOrRefuse(conversationId: $conversationId);
		if (($conversation['status'] ?? 'open') !== 'closed') {
			$conversation['status'] = 'closed';
			$this->store->saveConversation(conversation: $conversation, id: $conversationId);
		}

		return $this->agentView(conversationId: $conversationId);
	}//end end()

	/**
	 * The hash of a chat secret, the only form in which it is stored.
	 *
	 * @param string $secret The secret.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public static function hashOf(string $secret): string {
		return hash('sha256', $secret);
	}//end hashOf()

	/**
	 * One visitor turn: store the message, then hand over or let the assistant answer.
	 *
	 * @param array<string, mixed> $channel        The channel.
	 * @param array<string, mixed> $conversation   The conversation.
	 * @param string               $conversationId Its id.
	 * @param array<string, mixed> $turn           `text`, `email`, `handover` and `locale`.
	 *
	 * @return void
	 */
	private function visitorTurn(array $channel, array $conversation, string $conversationId, array $turn): void {
		$now = $this->now();
		if ($turn['text'] !== '') {
			$this->store->addMessage(
				message: $this->message(conversationId: $conversationId, direction: 'inbound', body: $turn['text'], metadata: ['author' => 'visitor'])
			);
			$conversation['lastInboundAt'] = $now;
		}

		$conversation['lastMessageAt'] = $now;
		$ticketId = (string)($conversation['ticket'] ?? '');
		if ($ticketId !== '') {
			$this->followUp(conversationId: $conversationId, ticketId: $ticketId, text: $turn['text'], email: $turn['email']);
			$this->store->saveConversation(conversation: $conversation, id: $conversationId);
			return;
		}

		$toPerson = $turn['handover'] === true || $turn['email'] !== '' || $this->assistant->answersFor(channel: $channel) === false;
		if ($toPerson === false && $turn['text'] !== '') {
			$conversation = $this->assistantTurn(
				channel: $channel,
				conversation: $conversation,
				conversationId: $conversationId,
				question: $turn['text'],
				locale: $turn['locale']
			);
			$toPerson     = (int)($conversation['unsourcedAnswers'] ?? 0) >= self::UNSOURCED_LIMIT || ($conversation['assistantFailed'] ?? false) === true;
			unset($conversation['assistantFailed']);
		}

		if ($toPerson === true) {
			$conversation = $this->handOver(conversation: $conversation, conversationId: $conversationId, email: $turn['email']);
		}

		$this->store->saveConversation(conversation: $conversation, id: $conversationId);
	}//end visitorTurn()

	/**
	 * Let the assistant answer; counts the answers in a row without a source.
	 *
	 * @param array<string, mixed> $channel        The channel.
	 * @param array<string, mixed> $conversation   The conversation.
	 * @param string               $conversationId Its id.
	 * @param string               $question       The visitor's message.
	 * @param string               $locale         The visitor's language.
	 *
	 * @return array<string, mixed> The conversation, with `assistantFailed` when there was no answer.
	 */
	private function assistantTurn(array $channel, array $conversation, string $conversationId, string $question, string $locale): array {
		$reply = $this->assistant->ask(
			channelId: $this->store->idOf(row: $channel),
			question: $question,
			locale: $locale,
			token: (string)($conversation['assistantToken'] ?? '')
		);
		if ($reply === null) {
			$conversation['assistantFailed'] = true;
			return $conversation;
		}

		$this->store->addMessage(
			message: $this->message(
				conversationId: $conversationId,
				direction: 'outbound',
				body: $reply['answer'],
				metadata: ['author' => 'assistant', 'sources' => $reply['sources'], 'disclosure' => $reply['disclosure']]
			)
		);
		$previous = (int)($conversation['unsourcedAnswers'] ?? 0);
		$conversation['assistantToken']   = $reply['token'];
		$conversation['unsourcedAnswers'] = 0;
		if ($reply['sources'] === []) {
			$conversation['unsourcedAnswers'] = ($previous + 1);
		}

		return $conversation;
	}//end assistantTurn()

	/**
	 * Hand the chat to a person: one request ticket with the transcript, in the Queue (D5).
	 *
	 * A chat that already has a ticket gets no second one.
	 *
	 * @param array<string, mixed> $conversation   The conversation.
	 * @param string               $conversationId Its id.
	 * @param string               $email          An address to be answered at, '' for none.
	 *
	 * @return array<string, mixed> The conversation with its ticket.
	 */
	private function handOver(array $conversation, string $conversationId, string $email): array {
		if ((string)($conversation['ticket'] ?? '') !== '') {
			return $conversation;
		}

		$messages = $this->store->messages(conversationId: $conversationId);
		$message  = [
			'channel'   => 'chat',
			'threadKey' => 'conversation:' . $conversationId,
			'title'     => $this->firstQuestion(messages: $messages),
			'text'      => $this->transcript(messages: $messages),
		];
		if ($email !== '') {
			$message['fromAddress'] = $email;
		}

		$ticket = $this->inbound->receive(message: $message);
		$conversation['ticket']       = $ticket['ticket'];
		$conversation['handedOverAt'] = $this->now();
		return $conversation;
	}//end handOver()

	/**
	 * A visitor's message on a chat that is already a ticket: it is added to the ticket.
	 *
	 * @param string $conversationId The chat.
	 * @param string $ticketId       Its ticket.
	 * @param string $text           The message, '' for none.
	 * @param string $email          An address to be answered at, '' for none.
	 *
	 * @return void
	 */
	private function followUp(string $conversationId, string $ticketId, string $text, string $email): void {
		$lines = [];
		if ($text !== '') {
			$lines[] = $text;
		}

		if ($email !== '') {
			$lines[] = 'Email address to answer at: ' . $email;
		}

		if ($lines === []) {
			return;
		}

		$message = ['channel' => 'chat', 'threadKey' => 'conversation:' . $conversationId, 'text' => implode("\n", $lines)];
		if ($email !== '') {
			$message['fromAddress'] = $email;
		}

		$this->inbound->receive(message: $message);
		if ($email !== '') {
			$this->updateTicket(ticketId: $ticketId, rbac: false, change: static function (array $ticket) use ($email): array {
				$metadata = [];
				if (is_array($ticket['channelMetadata'] ?? null) === true) {
					$metadata = $ticket['channelMetadata'];
				}

				$metadata['fromAddress']   = $email;
				$ticket['channelMetadata'] = $metadata;
				return $ticket;
			});
		}
	}//end followUp()

	/**
	 * Change a chat's ticket.
	 *
	 * @param string                                          $ticketId The ticket.
	 * @param bool                                            $rbac     Whether the read goes through OpenRegister's checks.
	 * @param callable(array<string, mixed>): array<string, mixed> $change   The change.
	 *
	 * @return void
	 *
	 * @throws WebChatRefusal When the ticket cannot be read or written.
	 */
	private function updateTicket(string $ticketId, bool $rbac, callable $change): void {
		try {
			$found = $this->tickets->getObjectService()->find(
				id: $ticketId,
				options: new ObjectFindOptions(register: $this->tickets->getRegisterId(), schema: $this->tickets->getSchemaId()),
				_rbac: $rbac,
				_multitenancy: $rbac
			);
			if ($found === null) {
				throw new WebChatRefusal(message: 'The ticket of this chat is not there.', status: 404);
			}

			$ticket = $change($found->getObject());
			unset($ticket['@self'], $ticket['id'], $ticket['uuid']);
			$this->tickets->save(ticketType: (string)($ticket['ticketType'] ?? 'request'), payload: $ticket, uuid: $ticketId);
		} catch (WebChatRefusal $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq web chat: the ticket of a chat could not be changed', ['ticket' => $ticketId, 'exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The ticket of this chat could not be changed.', status: 503);
		}
	}//end updateTicket()

	/**
	 * The window's view of the chat.
	 *
	 * @param array<string, mixed>      $channel        The channel.
	 * @param string                    $conversationId The chat.
	 * @param string                    $since          The time of the last message the window has, '' for all.
	 * @param array<string, mixed>|null $conversation   The conversation when already read.
	 *
	 * @return array{state: string, messages: list<array<string, mixed>>, offlineText: string}
	 */
	private function view(array $channel, string $conversationId, string $since, ?array $conversation=null): array {
		$conversation = ($conversation ?? ($this->store->conversation(id: $conversationId) ?? []));
		$showName     = ($channel['showAgentFirstName'] ?? false) === true;
		$messages     = [];
		foreach ($this->store->messages(conversationId: $conversationId) as $message) {
			if ($since === '' || strcmp((string)($message['sentAt'] ?? ''), $since) > 0) {
				$messages[] = $this->messageView(message: $message, showAgentName: $showName);
			}
		}

		return [
			'state'       => $this->state(channel: $channel, conversation: $conversation),
			'messages'    => $messages,
			'offlineText' => (string)($channel['offlineText'] ?? ''),
		];
	}//end view()

	/**
	 * Where the chat stands: closed, with a colleague, waiting for one, waited out, or with the assistant.
	 *
	 * @param array<string, mixed> $channel      The channel.
	 * @param array<string, mixed> $conversation The conversation.
	 *
	 * @return string One of closed, with-agent, waiting, offline, assistant, person.
	 */
	private function state(array $channel, array $conversation): string {
		$status = (string)($conversation['status'] ?? 'open');
		if ($status === 'closed') {
			return 'closed';
		}

		if ($status === 'assigned') {
			return 'with-agent';
		}

		if ((string)($conversation['ticket'] ?? '') !== '') {
			$wait   = (int)($channel['waitSeconds'] ?? self::DEFAULT_WAIT);
			$handed = $this->timestamp(value: ($conversation['handedOverAt'] ?? ''));
			if ($handed !== null && ($this->time->getTime() - $handed) >= $wait) {
				return 'offline';
			}

			return 'waiting';
		}

		if ($this->assistant->answersFor(channel: $channel) === true) {
			return 'assistant';
		}

		if ($this->agentsAvailable() === true) {
			return 'person';
		}

		return 'offline';
	}//end state()

	/**
	 * How the window opens: with the assistant, with a person, or asking for an address.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return string One of assistant, person, offline.
	 */
	private function startMode(array $channel): string {
		if ($this->assistant->answersFor(channel: $channel) === true) {
			return 'assistant';
		}

		if ($this->agentsAvailable() === true) {
			return 'person';
		}

		return 'offline';
	}//end startMode()

	/**
	 * Whether a colleague can pick up: no agent profiles kept, or one that is available (D7).
	 *
	 * @return bool
	 */
	private function agentsAvailable(): bool {
		$profiles = $this->store->agentProfiles();
		return $profiles === [] || $this->routing->filterByAvailability(profiles: $profiles) !== [];
	}//end agentsAvailable()

	/**
	 * A message as a window shows it.
	 *
	 * @param array<string, mixed> $message       The channelMessage row.
	 * @param bool                 $showAgentName Whether a colleague's first name is shown.
	 *
	 * @return array<string, mixed>
	 */
	private function messageView(array $message, bool $showAgentName): array {
		$metadata = ($message['metadata'] ?? []);
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$author = 'agent';
		if (($message['direction'] ?? '') === 'inbound') {
			$author = 'visitor';
		}

		$author = (string)($metadata['author'] ?? $author);
		$view   = [
			'id'     => $this->store->idOf(row: $message),
			'author' => $author,
			'body'   => (string)($message['body'] ?? ''),
			'at'     => (string)($message['sentAt'] ?? ''),
		];
		if ($author === 'assistant') {
			$view['sources']    = (array)($metadata['sources'] ?? []);
			$view['disclosure'] = (string)($metadata['disclosure'] ?? '');
		}

		if ($author === 'agent' && $showAgentName === true && (string)($metadata['agentFirstName'] ?? '') !== '') {
			$view['agentName'] = (string)$metadata['agentFirstName'];
		}

		return $view;
	}//end messageView()

	/**
	 * A new chat message row.
	 *
	 * @param string               $conversationId The chat.
	 * @param string               $direction      inbound or outbound.
	 * @param string               $body           The text.
	 * @param array<string, mixed> $metadata       Who said it, and the assistant's sources.
	 *
	 * @return array<string, mixed>
	 */
	private function message(string $conversationId, string $direction, string $body, array $metadata): array {
		return [
			'conversationId' => $conversationId,
			'channel'        => 'chat',
			'direction'      => $direction,
			'body'           => $body,
			'deliveryStatus' => 'delivered',
			'metadata'       => $metadata,
			'sentAt'         => $this->now(),
		];
	}//end message()

	/**
	 * The first thing the visitor asked, as the ticket title.
	 *
	 * @param list<array<string, mixed>> $messages The chat's messages.
	 *
	 * @return string
	 */
	private function firstQuestion(array $messages): string {
		foreach ($messages as $message) {
			if (($message['direction'] ?? '') === 'inbound' && trim((string)($message['body'] ?? '')) !== '') {
				return trim((string)$message['body']);
			}
		}

		return 'Website chat';
	}//end firstQuestion()

	/**
	 * The chat so far, one line per message, saying who said it.
	 *
	 * @param list<array<string, mixed>> $messages The chat's messages.
	 *
	 * @return string
	 */
	private function transcript(array $messages): string {
		$names = ['visitor' => 'Visitor', 'assistant' => 'Assistant', 'agent' => 'Colleague'];
		$lines = [];
		foreach ($messages as $message) {
			$author  = $this->messageView(message: $message, showAgentName: false)['author'];
			$lines[] = ($names[$author] ?? 'Visitor') . ': ' . trim((string)($message['body'] ?? ''));
		}

		return implode("\n", $lines);
	}//end transcript()

	/**
	 * Whether a chat had no message for the idle time (D8).
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 *
	 * @return bool
	 */
	private function idle(array $conversation): bool {
		$last = $this->timestamp(value: ($conversation['lastMessageAt'] ?? ''));
		return $last !== null && ($this->time->getTime() - $last) >= self::IDLE_SECONDS;
	}//end idle()

	/**
	 * The active channel with this token.
	 *
	 * @param string $token The channel token.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When there is none.
	 */
	private function channelOrRefuse(string $token): array {
		try {
			$channel = $this->store->activeChannel(token: $token);
		} catch (RuntimeException $e) {
			$this->logger->error('Pipelinq web chat: channels cannot be read', ['exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The chat is not available right now.', status: 503);
		}

		if ($channel === null) {
			throw new WebChatRefusal(message: 'This chat is not available.', status: 404);
		}

		return $channel;
	}//end channelOrRefuse()

	/**
	 * The channel and conversation of a chat whose secret is right.
	 *
	 * @param string $token          The channel token.
	 * @param string $conversationId The chat.
	 * @param string $secret         The visitor's chat secret.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 *
	 * @throws WebChatRefusal When the chat is not on this channel or the secret is wrong.
	 */
	private function chatOrRefuse(string $token, string $conversationId, string $secret): array {
		$channel      = $this->channelOrRefuse(token: $token);
		$conversation = null;
		if ($conversationId !== '') {
			$conversation = $this->store->conversation(id: $conversationId);
		}

		if ($conversation === null
			|| ($conversation['channel'] ?? null) !== 'chat'
			|| (string)($conversation['webChatChannel'] ?? '') !== $this->store->idOf(row: $channel)
		) {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 404);
		}

		$hash = (string)($conversation['visitorSecretHash'] ?? '');
		if ($secret === '' || $hash === '' || hash_equals($hash, self::hashOf(secret: $secret)) === false) {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 403);
		}

		return [$channel, $conversation];
	}//end chatOrRefuse()

	/**
	 * A chat conversation the current colleague may read.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When they may not, or it is not a chat.
	 */
	private function readableOrRefuse(string $conversationId): array {
		$conversation = $this->store->readableConversation(id: $conversationId);
		if ($conversation === null || ($conversation['channel'] ?? null) !== 'chat') {
			throw new WebChatRefusal(message: 'This chat is not known.', status: 404);
		}

		return $conversation;
	}//end readableOrRefuse()

	/**
	 * A chat conversation the current colleague may read and that has not ended.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When they may not, or it has ended.
	 */
	private function openReadableOrRefuse(string $conversationId): array {
		$conversation = $this->readableOrRefuse(conversationId: $conversationId);
		if (($conversation['status'] ?? 'open') === 'closed') {
			throw new WebChatRefusal(message: 'This chat has ended.', status: 409);
		}

		return $conversation;
	}//end openReadableOrRefuse()

	/**
	 * Run a visitor's writes as the messaging service account.
	 *
	 * @param callable(): array<string, mixed> $writes The writes.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WebChatRefusal When the account or OpenRegister is unavailable.
	 */
	private function asVisitor(callable $writes): array {
		try {
			return $this->account->runAs(operation: $writes);
		} catch (WebChatRefusal $e) {
			throw $e;
		} catch (ServiceAccountUnavailableException | RuntimeException $e) {
			$this->logger->error('Pipelinq web chat: a visitor write failed', ['exception' => $e->getMessage()]);
			throw new WebChatRefusal(message: 'The chat is not available right now.', status: 503);
		}
	}//end asVisitor()

	/**
	 * A time as a Unix timestamp, or null when it cannot be read.
	 *
	 * @param mixed $value An ISO 8601 time.
	 *
	 * @return int|null
	 */
	private function timestamp(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
		if ($parsed === false) {
			return null;
		}

		return $parsed->getTimestamp();
	}//end timestamp()

	/**
	 * Now, as ISO 8601.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
