<?php

/**
 * Pipelinq PropertyEndpoint.
 *
 * The Search Analytics query endpoint of one property and the HTTP client
 * that calls it; it answers one page of rows per request.
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
use RuntimeException;

/**
 * The query endpoint of one property; built by SearchConsoleImportService per property.
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

	/**
	 * POST one page request to the property and answer its raw rows.
	 *
	 * @param array<string, mixed> $options The request.
	 *
	 * @return array<int|string, mixed> The rows of the page (empty when it carries none).
	 *
	 * @throws RuntimeException When the API answers with an error or not with JSON.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function page(array $options): array {
		$response = $this->client->post($this->url, $options);

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false) {
			throw new RuntimeException('Search Console: the API answered with something that is not JSON');
		}

		if (isset($decoded['error']) === true) {
			throw new RuntimeException('Search Console: ' . $this->errorMessage(error: $decoded['error']));
		}

		$page = ($decoded['rows'] ?? []);
		if (is_array($page) === false) {
			return [];
		}

		return $page;
	}//end page()

	/**
	 * The message of an API error object, or the error itself as a string.
	 *
	 * @param mixed $error The `error` member of the API answer.
	 *
	 * @return string
	 */
	private function errorMessage(mixed $error): string {
		if (is_array($error) === true) {
			return (string)($error['message'] ?? 'error');
		}

		return (string)$error;
	}//end errorMessage()
}//end class
