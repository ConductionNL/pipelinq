<?php

/**
 * A website chat request that is refused, with the HTTP status the window gets.
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
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use RuntimeException;

/**
 * Carries a message the window may show and the status it is answered with.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 */
class WebChatRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The message the window may show.
	 * @param int    $status  The HTTP status.
	 */
	public function __construct(string $message, private readonly int $status=400) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.1
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
