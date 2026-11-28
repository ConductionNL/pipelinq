<?php

/**
 * Checks a website form against the destination it names, when the form is made.
 *
 * Decision 179: every form knows its destination and must be valid against
 * that object's schema, checked when the form is saved and again on submit.
 * Decision 181, Q9: a finding refuses the save from the first release, with
 * no report mode. The judging itself is OpenRegister's
 * FormDestinationValidator, so a pipelinq form and a dossiq form are held to
 * one set of rules; this class only resolves what it is handed.
 *
 * For a form bound to Nextcloud Forms (Q6), each field is a question id, and
 * the field's type and options come from the question as it is now, not from
 * what the binding claims, so a binding is checked against what the form can
 * really produce.
 *
 * Every submit is written by pipelinq's own system account (see
 * WebFormIntakeService), a signed-in subject, so the destination is checked
 * for the `authenticated` audience whatever the form's own audience is.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

/**
 * The shared context of one form's write checks; built by WebFormBindingCheck.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
 */
final class WriteCheck {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>>|null $questions The bound form's questions, or null.
	 * @param bool                                     $named     Whether to name the write in each finding.
	 */
	public function __construct(
		public readonly ?array $questions,
		public readonly bool $named,
	) {
	}//end __construct()
}//end class
