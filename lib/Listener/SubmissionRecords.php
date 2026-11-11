<?php

/**
 * Pipelinq SubmissionRecords
 *
 * The contact and lead a landing page submission produced.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

/**
 * The contact and lead a landing page submission produced.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
final class SubmissionRecords {

	/**
	 * Constructor.
	 *
	 * @param string $contactId The contact id, or an empty string.
	 * @param string $leadId    The lead id, or an empty string.
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $leadId,
	) {
	}//end __construct()
}//end class
