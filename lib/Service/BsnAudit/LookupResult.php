<?php

/**
 * Pipelinq LookupResult.
 *
 * What a BSN lookup came to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\BsnAudit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\BsnAudit;

/**
 * The outcome of a BSN lookup as the audit record states it.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class LookupResult {
	/**
	 * Constructor.
	 *
	 * @param string  $outcome       Outcome enum value (see schema).
	 * @param string  $action        Action enum value.
	 * @param ?int    $responseCode  HTTP status from the BRP source (200, 404, 503).
	 * @param ?string $correlationId Correlation ID of the BRP call, for tracing.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $action = 'brp-lookup-executed',
		public readonly ?int $responseCode = null,
		public readonly ?string $correlationId = null,
	) {
	}//end __construct()
}//end class
