<?php

/**
 * Pipelinq JourneyStep.
 *
 * The step a journey runs for each of its contacts: the journey id and row,
 * with its action block and audience segment read off the row.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * One journey's step.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
final class JourneyStep {
	/**
	 * Constructor.
	 *
	 * @param string               $journeyId The journey.
	 * @param array<string, mixed> $journey   The journey row.
	 */
	public function __construct(
		public readonly string $journeyId,
		public readonly array $journey,
	) {
	}//end __construct()

	/**
	 * The journey's action block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function action(): array {
		return (array)($this->journey['action'] ?? []);
	}//end action()

	/**
	 * The journey's audience segment, for the delivery ledger ('' when none).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function segmentId(): string {
		return trim((string)($this->journey['audienceSegment'] ?? ''));
	}//end segmentId()

	/**
	 * The template the step sends.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function templateId(): string {
		return (string)($this->action()['templateId'] ?? '');
	}//end templateId()
}//end class
