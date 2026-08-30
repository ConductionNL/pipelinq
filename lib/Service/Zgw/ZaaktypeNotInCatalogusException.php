<?php

/**
 * Pipelinq ZaaktypeNotInCatalogusException.
 *
 * Raised by `ZtcClient::resolveZaaktype()` when the ZTC returns either an
 * empty result set or a 404 for the requested omschrijving. Carries the
 * omschrijving (and optionally the geldigheidsdatum window) so the
 * gemeente beheerder can correct the catalogus entry without grepping
 * logs.
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * Zaaktype omschrijving not found in ZTC catalogus.
 */
class ZaaktypeNotInCatalogusException extends ZgwException {
	/**
	 * Constructor.
	 *
	 * @param string $omschrijving The zaaktype omschrijving we attempted to resolve.
	 */
	public function __construct(
		public readonly string $omschrijving,
	) {
		parent::__construct(
			message: sprintf(
				'ZGW: zaaktype with omschrijving "%s" not found in catalogus',
				$omschrijving
			)
		);
	}//end __construct()
}//end class
