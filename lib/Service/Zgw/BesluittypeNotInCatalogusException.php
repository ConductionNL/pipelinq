<?php

/**
 * Pipelinq BesluittypeNotInCatalogusException.
 *
 * Raised by `ZtcClient::resolveBesluittype()` when the ZTC returns either
 * an empty result set or a 404 for the requested besluittype omschrijving.
 * Carries the omschrijving so the gemeente beheerder can correct the
 * catalogus entry without grepping logs.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * Besluittype omschrijving not found in ZTC catalogus.
 *
 * @spec exclude exception type: carries a failure mode, not a behaviour a requirement
 *   can specify
 */
class BesluittypeNotInCatalogusException extends ZgwException {
	/**
	 * Constructor.
	 *
	 * @param string $omschrijving The besluittype omschrijving we attempted to resolve.
	 */
	public function __construct(
		public readonly string $omschrijving,
	) {
		parent::__construct(
			message: sprintf(
				'ZGW: besluittype with omschrijving "%s" not found in catalogus',
				$omschrijving
			)
		);
	}//end __construct()
}//end class
