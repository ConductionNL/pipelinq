<?php

/**
 * Pipelinq InsufficientScopeException.
 *
 * Raised by the pre-flight scope-cache guards on write operations (createZaak,
 * createBesluit, createEnkelvoudigInformatieobject) when the configured
 * `ZgwClient` does not hold the required scope on the target zaaktype /
 * informatieobjecttype / besluittype as reported by the AC.
 *
 * The message lists the missing scope by name and the target resource type
 * URL so the gemeente beheerder can grant the right autorisatie without
 * having to read the bridge logs.
 *
 * Per REQ-ZGW-006 no HTTP call to the underlying component MUST be issued
 * once this exception has been raised.
 *
 * @category Exception
 * @package  OCA\Pipelinq\Service\Zgw
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * AC scope-missing pre-flight error.
 */
class InsufficientScopeException extends ZgwException {
	/**
	 * Constructor.
	 *
	 * @param string $scope Missing scope name (e.g. "zaken.aanmaken").
	 * @param string $zaaktypeUrl Target zaaktype/besluittype/informatieobjecttype URL.
	 * @param string $additionalInfo Optional extra context for the operator.
	 */
	public function __construct(
		public readonly string $scope,
		public readonly string $zaaktypeUrl,
		string $additionalInfo = '',
	) {
		$suffix = '';
		if ($additionalInfo !== '') {
			$suffix = ' ' . $additionalInfo;
		}

		$msg = sprintf(
			'ZGW: missing scope "%s" on resource "%s".%s',
			$scope,
			$zaaktypeUrl,
			$suffix
		);
		parent::__construct(message: $msg);
	}//end __construct()
}//end class
