<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\ProviderCatalogue.
 *
 * The gateway asks this for one provider entry to decide readiness. The stub
 * answers nothing; the tests hand in a double that answers a real catalogue
 * entry, including the `preview` flag Bluesky carries upstream.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The read-only provider catalogue.
 */
class ProviderCatalogue {
	/**
	 * One catalogue entry, or null when the provider is unknown.
	 *
	 * @param string $providerId The provider identifier.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	public function get(string $providerId): ?array {
		return null;
	}//end get()
}//end class
