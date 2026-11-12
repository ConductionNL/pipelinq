<?php

/**
 * Pipelinq LandingPageOrder.
 *
 * A campaign a landing page is ordered for: its id and its row.
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
 * A campaign whose landing page is being created.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */
final class LandingPageOrder {
	/**
	 * Constructor.
	 *
	 * @param string               $campaignId The campaign id.
	 * @param array<string, mixed> $campaign   The campaign row.
	 */
	public function __construct(
		public readonly string $campaignId,
		public readonly array $campaign,
	) {
	}//end __construct()
}//end class
