<?php

/**
 * Pipelinq LandingPlacement.
 *
 * Where a campaign landing page is placed: the portal and the in-portal route.
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
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The portal and route of a landing page.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */
final class LandingPlacement {
	/**
	 * Constructor.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route  The in-portal route.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $route,
	) {
	}//end __construct()
}//end class
