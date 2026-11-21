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
use OCP\Http\Client\IResponse;
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
			$response = $this->leafGet(url: $url, options: []);
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
	 * GET an OpenRegister integration leaf and say what it answered: the HTTP status
	 * and the rows under `results`.
	 *
	 * Unlike leafRows() a 404 is not folded into "unusable": a check has to tell a
	 * number the register does not know (404, or 200 without it) from a register it
	 * cannot reach (a 5xx, or no answer at all, which reads as status 0).
	 *
	 * @param string $url The absolute leaf URL.
	 *
	 * @return array{status: int, rows: array<int, array<string, mixed>>|null} The status and the rows.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	public function leafAnswer(string $url): array {
		try {
			$response = $this->leafGet(url: $url, options: ['http_errors' => false]);
		} catch (Throwable $e) {
			$this->logger->debug('Registry leaf did not answer', ['exception' => $e]);
			return ['status' => self::statusOf(exception: $e), 'rows' => null];
		}

		return ['status' => $response->getStatusCode(), 'rows' => $this->resultRows(body: (string)$response->getBody())];
	}//end leafAnswer()

	/**
	 * GET an OpenRegister integration leaf: internal, OCS-APIREQUEST, allow_local_address.
	 *
	 * @param string               $url     The absolute leaf URL.
	 * @param array<string, mixed> $options Request options on top of the leaf's own.
	 *
	 * @return IResponse The answer.
	 *
	 * @throws Throwable When the leaf does not answer.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.2
	 */
	private function leafGet(string $url, array $options): IResponse {
		return $this->clientService->newClient()->get(
			$url,
			array_merge(
				[
					'timeout' => 15,
					'connect_timeout' => 3,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				],
				$options
			)
		);
	}//end leafGet()

	/**
	 * The HTTP status a transport exception carries, or 0 when it carries none.
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return int The status.
	 */
	private static function statusOf(Throwable $exception): int {
		if (method_exists($exception, 'getResponse') === false) {
			return 0;
		}

		$response = $exception->getResponse();
		if (is_object($response) === false || method_exists($response, 'getStatusCode') === false) {
			return 0;
		}

		return (int)$response->getStatusCode();
	}//end statusOf()

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
