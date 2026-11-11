<?php

/**
 * Pipelinq BsnLookup.
 *
 * One BRP lookup: the BSN asked for and its masked form for logging.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The BSN a BRP lookup asks for, and the masked form that is the only one
 * ever logged.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 */
final class BsnLookup {
	/**
	 * Constructor.
	 *
	 * @param string $bsn       Raw 9-digit BSN; never logged.
	 * @param string $maskedBsn The masked BSN used for any logging.
	 */
	public function __construct(
		#[\SensitiveParameter]
		public readonly string $bsn,
		public readonly string $maskedBsn,
	) {
	}//end __construct()
}//end class
