<?php

/**
 * Pipelinq LandingSubmission
 *
 * One landing page submission with the campaign it belongs to.
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

use OCA\Pipelinq\Event\LandingPageFormSubmittedEvent;

/**
 * One landing page submission with the campaign it belongs to.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
final class LandingSubmission {

	/**
	 * Constructor.
	 *
	 * @param LandingPageFormSubmittedEvent $event    The submission.
	 * @param SubmissionCampaign            $campaign Its campaign.
	 */
	public function __construct(
		public readonly LandingPageFormSubmittedEvent $event,
		public readonly SubmissionCampaign $campaign,
	) {
	}//end __construct()
}//end class
