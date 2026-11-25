<?php

/**
 * The colleague's side of a website chat, on its ticket: the transcript, take, reply and end.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\WebChat\WebChatRefusal;
use OCA\Pipelinq\Service\WebChat\WebChatService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every route first checks that the colleague may read the chat through
 * OpenRegister's checks (requireReadableChat); a chat they may not read
 * answers 404, as if it were not there.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */
class WebChatAgentController extends Controller {

	/**
	 * How a website chat ticket's thread key starts.
	 *
	 * @var string
	 */
	public const THREAD_PREFIX = 'conversation:';

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param WebChatService  $chat        The chat.
	 * @param IUserSession    $userSession The colleague.
	 * @param LoggerInterface $logger      Where an unexpected failure is logged.
	 */
	public function __construct(
		IRequest $request,
		private readonly WebChatService $chat,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The conversation id from a route id: the id itself, or the ticket's thread key
	 * (`conversation:<id>`), which the ticket page's widget and actions pass.
	 *
	 * @param string $id The id in the route.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public static function conversationIdOf(string $id): string {
		if (str_starts_with($id, self::THREAD_PREFIX) === true) {
			return substr($id, strlen(self::THREAD_PREFIX));
		}

		return $id;
	}//end conversationIdOf()

	/**
	 * The chat's transcript and state.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$id = self::conversationIdOf(id: $id);
		return $this->answer(
			call: function () use ($id): array {
				$this->chat->requireReadableChat(conversationId: $id);
				return $this->chat->agentView(conversationId: $id);
			}
		);
	}//end show()

	/**
	 * Take this chat.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function take(string $id): JSONResponse {
		$id = self::conversationIdOf(id: $id);
		return $this->answer(
			call: function () use ($id): array {
				$this->chat->requireReadableChat(conversationId: $id);
				return $this->chat->take(conversationId: $id, userId: $this->userId());
			}
		);
	}//end take()

	/**
	 * Answer the visitor.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function reply(string $id): JSONResponse {
		$id   = self::conversationIdOf(id: $id);
		$text = $this->request->getParam('text', '');
		if (is_string($text) === false) {
			$text = '';
		}

		return $this->answer(
			call: function () use ($id, $text): array {
				$this->chat->requireReadableChat(conversationId: $id);
				$user = $this->userSession->getUser();
				return $this->chat->reply(
					conversationId: $id,
					userId: $this->userId(),
					displayName: (string)$user?->getDisplayName(),
					text: $text
				);
			}
		);
	}//end reply()

	/**
	 * End the chat; the ticket stays as it is.
	 *
	 * @param string $id The conversation id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function end(string $id): JSONResponse {
		$id = self::conversationIdOf(id: $id);
		return $this->answer(
			call: function () use ($id): array {
				$this->chat->requireReadableChat(conversationId: $id);
				return $this->chat->end(conversationId: $id);
			}
		);
	}//end end()

	/**
	 * The colleague's user id.
	 *
	 * @return string
	 */
	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end userId()

	/**
	 * Run a call and answer it, or its refusal.
	 *
	 * @param callable(): array<string, mixed> $call The call.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call): JSONResponse {
		try {
			return new JSONResponse($call());
		} catch (WebChatRefusal $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq web chat: a colleague request failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The chat could not be changed.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()
}//end class
