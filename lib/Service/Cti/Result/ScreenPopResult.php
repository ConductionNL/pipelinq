<?php

/**
 * Pipelinq ScreenPopResult.
 *
 * Value object returned by CtiService::initiateScreenPop describing what the
 * frontend should do after a phone number lookup.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Result
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

/**
 * Screen-pop outcome.
 *
 * - action=navigate: a single match was found; the frontend routes to the contact view.
 * - action=chooser : multiple matches; the frontend opens the chooser modal.
 * - action=intake  : no match; the frontend opens the new-contact intake form.
 *
 * @spec exclude value object returned by the CTI adapters; the behaviour is specified on
 *   the adapter, not on the carrier
 */
final class ScreenPopResult {
	public const ACTION_NAVIGATE = 'navigate';
	public const ACTION_CHOOSER = 'chooser';
	public const ACTION_INTAKE = 'intake';

	/**
	 * Constructor.
	 *
	 * @param string $action One of self::ACTION_*.
	 * @param array<int,array<string,mixed>> $matches Matched contact / client objects (max 3).
	 * @param string|null $e164 Normalised E.164 phone number.
	 * @param string|null $rawNumber Raw number as received.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $matches = [],
		public readonly ?string $e164 = null,
		public readonly ?string $rawNumber = null,
	) {
	}//end __construct()

	/**
	 * Convert to an array suitable for a JSONResponse.
	 *
	 * @return array<string,mixed>
	 * @spec exclude value object returned by the CTI adapters; the behaviour is specified on
	 *   the adapter, not on the carrier
	 */
	public function toArray(): array {
		return [
			'action' => $this->action,
			'matches' => $this->matches,
			'e164' => $this->e164,
			'rawNumber' => $this->rawNumber,
		];
	}//end toArray()
}//end class
