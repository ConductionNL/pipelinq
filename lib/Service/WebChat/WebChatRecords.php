<?php

/**
 * What every part of a website chat reads and writes through: the rows, the messages, who may reach a chat and what a window shows.
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
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

/**
 * The four collaborators the visitor's and the colleague's side of a chat share,
 * held together so each side takes them as one.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
final class WebChatRecords {

	/**
	 * Constructor.
	 *
	 * @param WebChatStore    $store    Where the chat is kept.
	 * @param WebChatMessages $messages The message rows and the times.
	 * @param WebChatAccess   $access   Who may reach a chat.
	 * @param WebChatWindow   $window   What the windows show.
	 */
	public function __construct(
		public readonly WebChatStore $store,
		public readonly WebChatMessages $messages,
		public readonly WebChatAccess $access,
		public readonly WebChatWindow $window,
	) {
	}//end __construct()
}//end class
