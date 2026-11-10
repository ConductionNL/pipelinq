<?php

/**
 * Pipelinq PropertyEndpoint.
 *
 * The Search Analytics query endpoint of one property and the HTTP client that calls it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use OCP\Http\Client\IClient;

/**
 * The query endpoint of one property.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
final class PropertyEndpoint {
	/**
	 * Constructor.
	 *
	 * @param IClient $client The HTTP client.
	 * @param string  $url    The Search Analytics query URL of the property.
	 */
	public function __construct(
		public readonly IClient $client,
		public readonly string $url,
	) {
	}//end __construct()
}//end class
