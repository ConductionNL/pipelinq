<?php

/**
 * Pipelinq UplListImporter.
 *
 * Imports the national list of government products (UPL) into pipelinq's
 * `uplProduct` lookup, which the product form offers when a service's
 * `uplReference` is filled in. One object per UPL URI: created on first
 * sight, updated when its name or audiences changed, left alone otherwise,
 * so a re-run writes nothing. An entry that leaves the published list is
 * kept, because a product may still point at it.
 *
 * Writes go through OpenRegister's object service with RBAC and
 * multitenancy off: the import runs from occ, where there is no session.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Upl;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Downloads and imports the UPL list.
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */
class UplListImporter {

	/**
	 * The lookup schema's slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'uplProduct';

	/**
	 * Where the national source publishes the current list.
	 *
	 * @var string
	 */
	public const DEFAULT_URL = 'https://standaarden.overheid.nl/owms/oquery/UPL-actueel.csv';

	/**
	 * The register the lookup lives in when none is configured.
	 *
	 * @var string
	 */
	private const DEFAULT_REGISTER = 'pipelinq';

	/**
	 * How many stored entries one read fetches; the list holds a few thousand.
	 *
	 * @var integer
	 */
	private const READ_LIMIT = 20000;

	/**
	 * Constructor.
	 *
	 * @param UplListParser      $parser        Reads the CSV.
	 * @param IClientService     $clientService Downloads it.
	 * @param IAppConfig         $appConfig     The configured register, schema and source URL.
	 * @param ContainerInterface $container     Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger        Logs failed writes.
	 */
	public function __construct(
		private readonly UplListParser $parser,
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The URL the list is downloaded from: the app setting `upl_list_url`, else the national source.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function sourceUrl(): string {
		$url = trim($this->appConfig->getValueString(Application::APP_ID, 'upl_list_url', ''));
		if ($url === '') {
			return self::DEFAULT_URL;
		}

		return $url;
	}//end sourceUrl()

	/**
	 * Download the list.
	 *
	 * @param string $url Where from.
	 *
	 * @return string The CSV.
	 *
	 * @throws RuntimeException When the download fails or returns nothing.
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function download(string $url): string {
		try {
			$response = $this->clientService->newClient()->get($url, ['timeout' => 60]);
		} catch (Throwable $e) {
			throw new RuntimeException('The UPL list could not be downloaded from ' . $url . ': ' . $e->getMessage(), 0, $e);
		}

		$body = (string)$response->getBody();
		if (trim($body) === '') {
			throw new RuntimeException('The UPL list at ' . $url . ' is empty.');
		}

		return $body;
	}//end download()

	/**
	 * Import a list into the lookup.
	 *
	 * @param string $csv The list as published.
	 *
	 * @return array{read: int, created: int, updated: int, unchanged: int, failed: int} What the run did.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 * @throws \InvalidArgumentException When the list has no name or URI column.
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function import(string $csv): array {
		$rows = $this->parser->parse(csv: $csv);
		$objectService = $this->uplObjectService();

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', self::DEFAULT_REGISTER);
		$schema = $this->appConfig->getValueString(Application::APP_ID, (self::SCHEMA . '_schema'), self::SCHEMA);
		$stored = $this->storedByUri(objectService: $objectService, register: $register, schema: $schema);
		$target = [$objectService, $register, $schema];

		$result = ['read' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
		foreach ($rows as $row) {
			$existing = ($stored[$row['uri']] ?? null);
			$result[$this->importRow(target: $target, row: $row, existing: $existing)]++;
		}

		return $result;
	}//end import()

	/**
	 * Resolve OpenRegister's ObjectService.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function uplObjectService(): object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister is not available: ' . $e->getMessage(), 0, $e);
		}
	}//end uplObjectService()

	/**
	 * Import one row of the list.
	 *
	 * @param array{0: object, 1: string, 2: string} $target   The ObjectService, register and schema.
	 * @param array<string, mixed>                   $row      The parsed row.
	 * @param array<string, mixed>|null              $existing The stored entry with the same URI, or null.
	 *
	 * @return string 'unchanged', 'failed', 'created' or 'updated'.
	 */
	private function importRow(array $target, array $row, array|null $existing): string {
		if ($this->isUnchanged(existing: $existing, row: $row) === true) {
			return 'unchanged';
		}

		if ($this->saveUplRow(target: $target, row: $row, existing: $existing) === false) {
			return 'failed';
		}

		$outcome = 'updated';
		if ($existing === null) {
			$outcome = 'created';
		}

		return $outcome;
	}//end importRow()

	/**
	 * Whether the stored entry already carries the row's name and audience.
	 *
	 * @param array<string, mixed>|null $existing The stored entry, or null.
	 * @param array<string, mixed>      $row      The parsed row.
	 *
	 * @return bool
	 */
	private function isUnchanged(array|null $existing, array $row): bool {
		return ($existing !== null && $existing['name'] === $row['name'] && $existing['audience'] === $row['audience']);
	}//end isUnchanged()

	/**
	 * Save one row, logging a failed save.
	 *
	 * @param array{0: object, 1: string, 2: string} $target   The ObjectService, register and schema.
	 * @param array<string, mixed>                   $row      The parsed row.
	 * @param array<string, mixed>|null              $existing The stored entry with the same URI, or null.
	 *
	 * @return bool Whether the save landed.
	 */
	private function saveUplRow(array $target, array $row, array|null $existing): bool {
		[$objectService, $register, $schema] = $target;
		try {
			$objectService->saveObject(
				object: $row,
				register: $register,
				schema: $schema,
				uuid: ($existing['id'] ?? null),
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('UplListImporter: could not save ' . $row['uri'], ['exception' => $e->getMessage()]);
			return false;
		}
	}//end saveUplRow()

	/**
	 * The stored entries by URI.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $schema        The schema.
	 *
	 * @return array<string, array{id: string|null, name: string, audience: array<int, string>}>
	 *
	 * @throws RuntimeException When the stored entries cannot be read, so the run does not duplicate them.
	 */
	private function storedByUri(object $objectService, string $register, string $schema): array {
		try {
			$found = $objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::READ_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The stored UPL entries could not be read: ' . $e->getMessage(), 0, $e);
		}

		$stored = [];
		foreach ((array)$found as $entry) {
			$data = $this->dataOf(entry: $entry);
			$uri = trim((string)($data['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$stored[$uri] = [
				'id' => $this->idOf(data: $data),
				'name' => (string)($data['name'] ?? ''),
				'audience' => array_values(array_map('strval', (array)($data['audience'] ?? []))),
			];
		}

		return $stored;
	}//end storedByUri()

	/**
	 * A stored entry as an array.
	 *
	 * @param mixed $entry An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function dataOf(mixed $entry): array {
		if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
			$entry = $entry->jsonSerialize();
		}

		if (is_array($entry) === false) {
			return [];
		}

		return $entry;
	}//end dataOf()

	/**
	 * The stored entry's id.
	 *
	 * @param array<string, mixed> $data The entry.
	 *
	 * @return string|null
	 */
	private function idOf(array $data): ?string {
		foreach ([($data['id'] ?? null), ($data['uuid'] ?? null), ($data['@self']['id'] ?? null)] as $candidate) {
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end idOf()
}//end class
