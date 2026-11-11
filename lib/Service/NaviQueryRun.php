<?php

/**
 * Pipelinq NaviQueryRun.
 *
 * Run object for one Navi question: the asker, the question as typed and
 * trimmed, and what the conversation store resolved for it (the stored
 * history and the carried-forward intent).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * State of one Navi question while it is answered.
 *
 * @spec openspec/specs/dashboard/spec.md#conversational-follow-up
 */
final class NaviQueryRun {
	/**
	 * The question with surrounding whitespace removed.
	 *
	 * @var string
	 */
	public readonly string $query;

	/**
	 * Conversation the question belongs to, if any.
	 *
	 * @var string|null
	 */
	public ?string $conversationId = null;

	/**
	 * Stored turns of the conversation.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $history = [];

	/**
	 * Intent the question is answered with.
	 *
	 * @var string
	 */
	public string $intent = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string $asked  The question as typed.
	 * @param string $userId The asking user.
	 */
	public function __construct(
		public readonly string $asked,
		public readonly string $userId,
	) {
		$this->query = trim($asked);
	}//end __construct()
}//end class
