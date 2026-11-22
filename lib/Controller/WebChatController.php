<?php

/**
 * The public side of a website chat: the window in its frame, and start, send and poll.
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
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\WebChat\WebChatRefusal;
use OCA\Pipelinq\Service\WebChat\WebChatService;
use OCA\Pipelinq\Service\WebChat\WebChatFrame;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every route here runs for an anonymous visitor (D3): no account, no
 * session, no cookie. A chat is proven by the secret its window holds, which
 * travels in the body. The window is a pipelinq page that only the channel's
 * own websites may frame.
 */
class WebChatController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request The request.
	 * @param WebChatService  $chat    The chat.
	 * @param WebChatFrame    $frame   The channel the window is for, and who may frame it.
	 * @param IURLGenerator   $urls    The routes the window calls.
	 * @param IInitialState   $state   What the window starts from.
	 * @param LoggerInterface $logger  Where an unexpected failure is logged.
	 */
	public function __construct(
		IRequest $request,
		private readonly WebChatService $chat,
		private readonly WebChatFrame $frame,
		private readonly IURLGenerator $urls,
		private readonly IInitialState $state,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The chat window, framed only by the channel's own websites.
	 *
	 * @param string $channelToken The channel token.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function window(string $channelToken): TemplateResponse {
		$channel = $this->frame->channel(token: $channelToken);

		$token = '';
		if ($channel !== null) {
			$token = $channelToken;
		}

		$this->state->provideInitialState(
			'web-chat',
			[
				'available' => $channel !== null,
				'name'      => (string)($channel['name'] ?? ''),
				'startUrl'  => $this->urls->linkToRoute('pipelinq.webChat.start', ['channelToken' => $token]),
				'sendUrl'   => $this->urls->linkToRoute('pipelinq.webChat.send', ['channelToken' => $token]),
				'pollUrl'   => $this->urls->linkToRoute('pipelinq.webChat.poll', ['channelToken' => $token]),
			]
		);
		$response = new TemplateResponse(AppIdentity::APP_ID, 'web-chat', ['available' => $channel !== null], TemplateResponse::RENDER_AS_BASE);
		$response->setContentSecurityPolicy($this->frame->policy(channel: $channel));
		if ($channel === null) {
			$response->setStatus(Http::STATUS_NOT_FOUND);
		}

		return $response;
	}//end window()

	/**
	 * Start a chat.
	 *
	 * @param string $channelToken The channel token.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function start(string $channelToken): JSONResponse {
		return $this->answer(call: fn (): array => $this->chat->start(token: $channelToken), status: Http::STATUS_CREATED);
	}//end start()

	/**
	 * A visitor's message, a request for a person, or an address.
	 *
	 * @param string $channelToken The channel token.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function send(string $channelToken): JSONResponse {
		return $this->answer(
			call: fn (): array => $this->chat->send(
				token: $channelToken,
				conversationId: $this->param(key: 'conversation'),
				secret: $this->param(key: 'secret'),
				turn: [
					'text'     => $this->param(key: 'text'),
					'email'    => $this->param(key: 'email'),
					'handover' => in_array($this->request->getParam('handover'), [true, 'true', '1', 1], true),
					'locale'   => $this->locale(),
				]
			)
		);
	}//end send()

	/**
	 * The messages after a time, and where the chat stands.
	 *
	 * @param string $channelToken The channel token.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function poll(string $channelToken): JSONResponse {
		return $this->answer(
			call: fn (): array => $this->chat->poll(
				token: $channelToken,
				conversationId: $this->param(key: 'conversation'),
				secret: $this->param(key: 'secret'),
				since: $this->param(key: 'since')
			)
		);
	}//end poll()

	/**
	 * Run a call and answer it, or its refusal; never a stack trace to an anonymous caller.
	 *
	 * @param callable(): array<string, mixed> $call   The call.
	 * @param int                              $status The status of a success.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call, int $status=Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($call(), $status);
		} catch (WebChatRefusal $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq web chat: a visitor request failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The chat is not available right now.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()

	/**
	 * A string parameter from the body, '' when absent or not a string.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string
	 */
	private function param(string $key): string {
		$value = $this->request->getParam($key, '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end param()

	/**
	 * The visitor's language: nl or en, from the window's own say.
	 *
	 * @return string
	 */
	private function locale(): string {
		$locale = strtolower(substr($this->param(key: 'locale'), 0, 2));
		if (in_array($locale, ['nl', 'en'], true) === true) {
			return $locale;
		}

		return 'nl';
	}//end locale()
}//end class
