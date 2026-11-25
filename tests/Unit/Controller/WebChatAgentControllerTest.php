<?php

/**
 * The colleague's web chat routes: each checks first that the colleague may read the chat.
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
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\WebChatAgentController;
use OCA\Pipelinq\Service\WebChat\WebChatRefusal;
use OCA\Pipelinq\Service\WebChat\WebChatService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-WCH-004 on the colleague's routes.
 *
 * @covers \OCA\Pipelinq\Controller\WebChatAgentController
 * @uses   \OCA\Pipelinq\Service\WebChat\WebChatRefusal
 */
class WebChatAgentControllerTest extends TestCase {

	/**
	 * The controller for colleague Anna de Vries, over this chat service and body.
	 *
	 * @param WebChatService       $chat   The chat service.
	 * @param array<string, mixed> $params The body.
	 *
	 * @return WebChatAgentController
	 */
	private function controller(WebChatService $chat, array $params=[]): WebChatAgentController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anna');
		$user->method('getDisplayName')->willReturn('Anna de Vries');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new WebChatAgentController(
			request: $request,
			chat: $chat,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The ticket page passes the ticket's thread key; every route reads the chat it names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
	 */
	public function testTheTicketsThreadKeyNamesTheChat(): void {
		$view = ['conversation' => 'c-1', 'status' => 'open', 'assignedTo' => '', 'ticket' => 't-1', 'messages' => []];
		$chat = $this->createMock(originalClassName: WebChatService::class);
		$chat->expects($this->exactly(count: 4))->method('requireReadableChat')->with('c-1');
		$chat->expects($this->once())->method('agentView')->with('c-1')->willReturn($view);
		$chat->expects($this->once())->method('take')->with('c-1', 'anna')->willReturn($view);
		$chat->expects($this->once())->method('reply')->with('c-1', 'anna', 'Anna de Vries', 'Hoi')->willReturn($view);
		$chat->expects($this->once())->method('end')->with('c-1')->willReturn($view);
		$controller = $this->controller(chat: $chat, params: ['text' => 'Hoi']);

		foreach (['show', 'take', 'reply', 'end'] as $route) {
			$this->assertSame(expected: 200, actual: $controller->$route(id: 'conversation:c-1')->getStatus(), message: $route);
		}

		$this->assertSame(expected: 'c-1', actual: WebChatAgentController::conversationIdOf(id: 'c-1'));
		$this->assertSame(expected: 'c-1', actual: WebChatAgentController::conversationIdOf(id: 'conversation:c-1'));
	}//end testTheTicketsThreadKeyNamesTheChat()

	/**
	 * A chat the colleague may not read answers 404, and nothing else is called.
	 *
	 * @return void
	 */
	public function testAChatTheColleagueMayNotReadIsNotThere(): void {
		$chat = $this->createMock(originalClassName: WebChatService::class);
		$chat->method('requireReadableChat')->willThrowException(new WebChatRefusal(message: 'This chat is not known.', status: 404));
		$chat->expects($this->never())->method('take');
		$chat->expects($this->never())->method('reply');
		$chat->expects($this->never())->method('end');
		$chat->expects($this->never())->method('agentView');
		$controller = $this->controller(chat: $chat, params: ['text' => 'Hoi']);

		foreach (['show', 'take', 'reply', 'end'] as $route) {
			$response = $controller->$route(id: 'c-1');
			$this->assertSame(expected: 404, actual: $response->getStatus(), message: $route);
		}
	}//end testAChatTheColleagueMayNotReadIsNotThere()

	/**
	 * Take and reply act as the signed-in colleague, after the check.
	 *
	 * @return void
	 */
	public function testTakeAndReplyActAsTheColleague(): void {
		$order = [];
		$chat  = $this->createMock(originalClassName: WebChatService::class);
		$chat->method('requireReadableChat')->willReturnCallback(
			static function (string $id) use (&$order): void {
				$order[] = 'check:' . $id;
			}
		);
		$chat->expects($this->once())->method('take')->with('c-1', 'anna')->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'take';
				return ['status' => 'assigned'];
			}
		);
		$chat->expects($this->once())->method('reply')->with('c-1', 'anna', 'Anna de Vries', 'Ik kijk het na')->willReturn(['status' => 'assigned']);

		$take  = $this->controller(chat: $chat)->take(id: 'c-1');
		$reply = $this->controller(chat: $chat, params: ['text' => 'Ik kijk het na'])->reply(id: 'c-1');

		$this->assertSame(expected: ['check:c-1', 'take', 'check:c-1'], actual: $order);
		$this->assertSame(expected: [200, ['status' => 'assigned']], actual: [$take->getStatus(), $take->getData()]);
		$this->assertSame(expected: 200, actual: $reply->getStatus());
	}//end testTakeAndReplyActAsTheColleague()
}//end class
