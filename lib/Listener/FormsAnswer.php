<?php

/**
 * Pipelinq FormsAnswer.
 *
 * The ids of one Nextcloud Forms answer.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

/**
 * The Forms form an answer was given on, and the submission that holds it.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-nextcloud-forms-form-feeds-pipelinq-only-through-a-checked-binding
 */
final class FormsAnswer {
	/**
	 * Constructor.
	 *
	 * @param int $formId       The Forms form id.
	 * @param int $submissionId The Forms submission id.
	 */
	public function __construct(
		public readonly int $formId,
		public readonly int $submissionId,
	) {
	}//end __construct()
}//end class
