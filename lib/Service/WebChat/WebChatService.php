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

use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCA\Pipelinq\Service\ServiceAccountUnavailableException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The visitor side (start, send, poll) runs for an anonymous caller: it
 * proves a chat by the secret only the visitor's window holds, stores a hash
 * of that secret and no address, and writes as the messaging service account.
 * The colleague side (view, take, reply, end) runs as the colleague, through
 * OpenRegister's checks: a conversation the colleague may not read is not there.
 *
 * Who may reach a chat is WebChatAccess, what the windows show is
 * WebChatWindow, the message rows are WebChatMessages, and the handover to a
 * ticket in the Queue (D5) is WebChatHandover. This class runs the turns.
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
	 * Constructor.
	 *
	 * @param WebChatStore            $store     Where the chat is kept.
	 * @param ChatAssistant           $assistant The assistant that may answer first.
	 * @param MessagingServiceAccount $account   The account a visitor's writes run as.
	 * @param ISecureRandom           $random    The visitor's chat secret.
	 * @param WebChatAccess           $access    Who may reach a chat.
	 * @param WebChatMessages         $messages  The message rows and the times.
	 * @param WebChatWindow           $window    What the windows show.
	 * @param WebChatHandover         $handover  The ticket a chat becomes.
	 * @param LoggerInterface         $logger    Where a failed write is logged.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly ChatAssistant $assistant,
		private readonly MessagingServiceAccount $account,
		private readonly ISecureRandom $random,
		private readonly WebChatAccess $access,
		private readonly WebChatMessages $messages,
		private readonly WebChatWindow $window,
		private readonly WebChatHandover $handover,
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
		$channel = $this->access->channelOrRefuse(token: $token);
		$secret  = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$now     = $this->messages->now();

		$conversation = $this->asVisitor(
			writes: fn (): array => $this->store->saveConversation(
				conversation: [
					'channel'           => 'chat',
					'status'            => 'open',
					'webChatChannel'    => $this->store->idOf(row: $channel),
					'visitorSecretHash' => $this->access->hashOf(secret: $secret),
					'lastMessageAt'     => $now,
					'lastInboundAt'     => $now,
					'unsourcedAnswers'  => 0,
				]
			)
		);

		return [
			'conversation' => $this->store->idOf(row: $conversation),
			'secret'       => $secret,
			'mode'         => $this->window->startMode(channel: $channel),
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
		[$channel, $conversation] = $this->access->chatOrRefuse(token: $token, conversationId: $conversationId, secret: $secret);
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

		return $this->window->visitorView(channel: $channel, conversationId: $conversationId, since: '');
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
		[$channel, $conversation] = $this->access->chatOrRefuse(token: $token, conversationId: $conversationId, secret: $secret);
		if (($conversation['status'] ?? 'open') !== 'closed' && $this->messages->idle(conversation: $conversation) === true) {
			$conversation['status'] = 'closed';
			$this->asVisitor(writes: fn (): array => $this->store->saveConversation(conversation: $conversation, id: $conversationId));
		}

		return $this->window->visitorView(channel: $channel, conversationId: $conversationId, since: $since, conversation: $conversation);
	}//end poll()

	/**
	 * Refuse unless the current colleague may read this chat: the per-object check of every colleague route.
	 *
	 * @param string $conversationId The chat.
	 *
	 * @return void
	 *
	 * @throws WebChatRefusal When they may not, or it is not a chat.
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function requireReadableChat(string $conversationId): void {
		$this->access->readableOrRefuse(conversationId: $conversationId);
	}//end requireReadableChat()

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
		return $this->window->agentView(conversationId: $conversationId, conversation: $this->access->readableOrRefuse(conversationId: $conversationId));
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
		$conversation = $this->access->openReadableOrRefuse(conversationId: $conversationId);
		$conversation['assignedTo'] = $userId;
		$conversation['status']     = 'assigned';
		$this->store->saveConversation(conversation: $conversation, id: $conversationId);

		$ticketId = (string)($conversation['ticket'] ?? '');
		if ($ticketId !== '') {
			$this->handover->assignTicket(ticketId: $ticketId, userId: $userId);
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
		$conversation = $this->access->openReadableOrRefuse(conversationId: $conversationId);
		$text         = mb_substr(trim($text), 0, self::TEXT_LIMIT, 'UTF-8');
		if ($text === '') {
			throw new WebChatRefusal(message: 'Type a message first.', status: 400);
		}

		$firstName = trim((string)strtok(trim($displayName), ' '));
		$this->store->addMessage(
			message: $this->messages->row(
				conversationId: $conversationId,
				direction: 'outbound',
				body: $text,
				metadata: ['author' => 'agent', 'agent' => $userId, 'agentFirstName' => $firstName]
			)
		);
		$conversation['lastMessageAt'] = $this->messages->now();
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
		$conversation = $this->access->readableOrRefuse(conversationId: $conversationId);
		if (($conversation['status'] ?? 'open') !== 'closed') {
			$conversation['status'] = 'closed';
			$this->store->saveConversation(conversation: $conversation, id: $conversationId);
		}

		return $this->agentView(conversationId: $conversationId);
	}//end end()

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
		$now = $this->messages->now();
		if ($turn['text'] !== '') {
			$this->store->addMessage(
				message: $this->messages->row(conversationId: $conversationId, direction: 'inbound', body: $turn['text'], metadata: ['author' => 'visitor'])
			);
			$conversation['lastInboundAt'] = $now;
		}

		$conversation['lastMessageAt'] = $now;
		$ticketId = (string)($conversation['ticket'] ?? '');
		if ($ticketId !== '') {
			$this->handover->followUp(conversationId: $conversationId, ticketId: $ticketId, text: $turn['text'], email: $turn['email']);
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
			$conversation = $this->handover->handOver(conversation: $conversation, conversationId: $conversationId, email: $turn['email']);
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
			message: $this->messages->row(
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
}//end class
