<?php

/**
 * Pipelinq CompanyRegistryHttp.
 *
 * The two GETs the company-registry clients make: a JSON GET to the registry's
 * own API, and a GET to OpenRegister's integration leaf that returns its rows.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-06-21-pipelinq-lookups-via-or-leaf/specs/company-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP plumbing shared by KvkApiClient and OpenCorporatesApiClient.
 *
 * Built by the client that owns the HTTP client factory and logger.
 *
 * @spec openspec/changes/archive/2026-06-21-pipelinq-lookups-via-or-leaf/specs/company-lookup/spec.md
 */
final class CompanyRegistryHttp {
	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService The HTTP client service.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * GET a registry API URL as JSON; a body that is not a JSON array reads as [].
	 *
	 * Transport errors propagate to the caller, which logs them per search term.
	 *
	 * @param string $url The full URL.
	 *
	 * @return array<mixed> The decoded body.
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-kvk-api-integration
	 */
	public function getJson(string $url): array {
		$client = $this->clientService->newClient();
		$response = $client->get(
			uri: $url,
			options: [
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 15,
			]
		);

		$decoded = json_decode(json: $response->getBody(), associative: true);

		if (is_array(value: $decoded) === true) {
			return $decoded;
		}

		return [];
	}//end getJson()

	/**
	 * GET an OpenRegister integration leaf (internal, OCS-APIREQUEST, allow_local_address)
	 * and return the array rows under its `results`.
	 *
	 * Returns null when the leaf is not usable — any exception (a 503 from an unusable
	 * source surfaces as one, as do connection refused and an absent OpenRegister,
	 * logged at debug with the given message), a status other than 200, or a body
	 * without a `results` list — so the caller falls back to the registry's own API.
	 *
	 * @param string $url         The absolute leaf URL.
	 * @param string $unavailable The debug line when the leaf cannot be reached.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null to fall back.
	 *
	 * @spec openspec/changes/archive/2026-06-21-pipelinq-lookups-via-or-leaf/specs/company-lookup/spec.md
	 */
	public function leafRows(string $url, string $unavailable): ?array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 15,
					'connect_timeout' => 3,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug($unavailable, ['exception' => $e]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		return $this->resultRows(body: (string)$response->getBody());
	}//end leafRows()

	/**
	 * The array entries of a leaf body's `results` list, or null when it has none.
	 *
	 * @param string $body The raw body.
	 *
	 * @return array<int, array<string, mixed>>|null The rows.
	 */
	private function resultRows(string $body): ?array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
			return null;
		}

		return array_values(array_filter($decoded['results'], static fn ($row): bool => is_array($row) === true));
	}//end resultRows()
}//end class
