<?php

/**
 * Pipelinq SearchConsoleRead.
 *
 * One read of Search Console data: the bearer token for the read-only scope
 * and the window to read.
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
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

/**
 * A token and a window to read.
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
final class SearchConsoleRead {
	/**
	 * Constructor.
	 *
	 * @param string       $token  A bearer token for the read-only scope.
	 * @param ImportWindow $window The days to read.
	 */
	public function __construct(
		public readonly string $token,
		public readonly ImportWindow $window,
	) {
	}//end __construct()
}//end class
