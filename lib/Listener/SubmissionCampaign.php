<?php

/**
 * Pipelinq SubmissionCampaign
 *
 * The campaign a landing page submission belongs to.
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
 * The campaign a landing page submission belongs to.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
final class SubmissionCampaign {

	/**
	 * Constructor.
	 *
	 * @param string               $id  The campaign id, or an empty string.
	 * @param array<string, mixed> $row The campaign row, empty when not found.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $row,
	) {
	}//end __construct()
}//end class
