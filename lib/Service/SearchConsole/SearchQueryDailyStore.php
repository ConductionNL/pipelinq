<?php

/**
 * Pipelinq SearchQueryDailyStore.
 *
 * The write side of the Search Console import: one `searchQueryDaily`
 * object per (property, date, query, page), created on first sight and
 * updated afterwards, through OpenRegister's object service with RBAC and
 * multitenancy off because the importer runs from cron or occ, where there
 * is no session.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SearchQueryDailyStore: idempotent upsert of imported rows.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */
class SearchQueryDailyStore {

	/**
	 * The `searchQueryDaily` schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'searchQueryDaily';

	/**
	 * Default register slug used when no `register` app config value is set.
	 *
	 * @var string
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy object service.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create or update the object for (property, date, query, page).
	 *
	 * @param string $property The property.
	 * @param array<string, mixed> $row A normalised row: date, query, page, clicks, impressions, ctr, position.
	 * @param string $importedAt When this import ran, ISO 8601.
	 *
	 * @return bool True when saved.
	 *
	 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
	 */
	public function upsert(string $property, array $row, string $importedAt): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', self::DEFAULT_REGISTER_SLUG);
		$schema = $this->appConfig->getValueString(Application::APP_ID, (self::SCHEMA . '_schema'), self::SCHEMA);
		$identity = ['property' => $property, 'date' => $row['date'], 'query' => $row['query'], 'page' => $row['page']];
		$existingId = $this->findExistingId(objectService: $objectService, register: $register, schema: $schema, identity: $identity);
		$payload = ($identity + $row + ['source' => 'gsc', 'importedAt' => $importedAt]);

		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryDailyStore: save failed', ['exception' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end upsert()

	/**
	 * The id of the object already holding this identity, or null.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $identity property, date, query, page.
	 *
	 * @return string|null The uuid, or null when absent or unreadable.
	 */
	private function findExistingId(object $objectService, string $register, string $schema, array $identity): ?string {
		try {
			$found = $objectService->findAll(
				config: ['filters' => (['register' => $register, 'schema' => $schema] + $identity), 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('SearchQueryDailyStore: lookup failed, creating', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_iterable($found) === false) {
			return null;
		}

		foreach ($found as $existing) {
			return $this->idOf(value: $existing);
		}

		return null;
	}//end findExistingId()

	/**
	 * The id of an OpenRegister entity or array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return string|null The uuid/id, or null.
	 */
	private function idOf(mixed $value): ?string {
		$row = $value;
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$row = $value->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		foreach (['uuid', 'id'] as $key) {
			$candidate = ($row[$key] ?? ($row['@self'][$key] ?? null));
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end idOf()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryDailyStore: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
