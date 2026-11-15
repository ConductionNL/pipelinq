<?php

/**
 * A website form submit that did not create its destination.
 *
 * Carries the HTTP status the caller answers with and, for a refused payload,
 * the per-property findings OpenRegister returned. Pipelinq's own type, so a
 * controller never names a class from another app (see EnquiryController's
 * old warning: the router reflects every controller while matching a route).
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use RuntimeException;
use Throwable;

/**
 * A refused or failed submit, with its status and findings.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */
class WebFormSubmitException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                           $message  What the submitter may read.
	 * @param int                              $status   The HTTP status: 400 refused, 422 payload refused, 503 try again later.
	 * @param array<int, array<string, mixed>> $findings Per-property findings, for a 422.
	 * @param Throwable|null                   $previous The cause, when there is one.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function __construct(
		string $message,
		private readonly int $status,
		private readonly array $findings = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, previous: $previous);

	}//end __construct()

	/**
	 * The HTTP status the caller answers with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function getStatus(): int {
		return $this->status;

	}//end getStatus()

	/**
	 * The per-property findings of a refused payload.
	 *
	 * @return array<int, array<string, mixed>> The findings; empty for other refusals.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function getFindings(): array {
		return $this->findings;

	}//end getFindings()
}//end class
