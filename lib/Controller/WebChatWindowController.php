<?php

/**
 * The website chat's window, in a frame only the channel's own websites may draw.
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
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\WebChat\WebChatFrame;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * The chat window: a pipelinq page for an anonymous visitor that only the
 * channel's own websites may frame (D3). It hands the window the three
 * public routes of WebChatController.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-an-administrator-puts-a-chat-on-the-website-req-wch-001
 */
class WebChatWindowController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request The request.
	 * @param WebChatFrame  $frame   The channel the window is for, and who may frame it.
	 * @param IURLGenerator $urls    The routes the window calls.
	 * @param IInitialState $state   What the window starts from.
	 */
	public function __construct(
		IRequest $request,
		private readonly WebChatFrame $frame,
		private readonly IURLGenerator $urls,
		private readonly IInitialState $state,
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
	 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $channelToken): TemplateResponse {
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
	}//end show()
}//end class
