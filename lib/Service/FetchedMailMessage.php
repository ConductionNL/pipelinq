<?php

/**
 * Pipelinq FetchedMailMessage.
 *
 * A Mail message whose IMAP uid has been read.
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
 * A Mail message and the uid the email leaf links it by.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class FetchedMailMessage {
	/**
	 * Constructor.
	 *
	 * @param MailMessageKey $key The Mail message.
	 * @param string         $uid The message uid from the Mail database.
	 */
	public function __construct(
		public readonly MailMessageKey $key,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
