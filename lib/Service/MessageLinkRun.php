<?php

/**
 * Pipelinq MessageLinkRun.
 *
 * One run of linking a Mail message to its matched CRM entities.
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
 * The email leaf and the message one matching run links every entity with.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class MessageLinkRun {
	/**
	 * Constructor.
	 *
	 * @param EmailLeaf          $leaf    The email leaf and its register.
	 * @param FetchedMailMessage $message The message being linked.
	 */
	public function __construct(
		public readonly EmailLeaf $leaf,
		public readonly FetchedMailMessage $message,
	) {
	}//end __construct()
}//end class
