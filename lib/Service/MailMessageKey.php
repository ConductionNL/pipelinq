<?php

/**
 * Pipelinq MailMessageKey.
 *
 * Identifies one Mail app message for email matching.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A Mail message by its Mail account id and message id.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class MailMessageKey {
	/**
	 * Constructor.
	 *
	 * @param int $mailAccountId The Mail account id.
	 * @param int $mailMessageId The Mail message id.
	 */
	public function __construct(
		public readonly int $mailAccountId,
		public readonly int $mailMessageId,
	) {
	}//end __construct()
}//end class
