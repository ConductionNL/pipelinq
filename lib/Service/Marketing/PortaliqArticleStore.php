<?php

/**
 * Pipelinq PortaliqArticleStore.
 *
 * Marketing articles live in portaliq (marketing-articles-move-to-portaliq).
 * This store is the one place pipelinq reads and, for the move, writes them:
 * register `portaliq`, schema `article`, through OpenRegister's ObjectService.
 * Every call first checks that portaliq is installed, so pipelinq without
 * portaliq reads no articles and never errors.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Support\FleetAppId;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes articles in portaliq's register.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
 */
class PortaliqArticleStore {
	use PayloadToArray;

	/**
	 * The app that owns marketing articles.
	 *
	 * @var string
	 */
	public const APP = 'portaliq';

	/**
	 * portaliq's register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'portaliq';

	/**
	 * portaliq's article schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'article';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  For OpenRegister's ObjectService, resolved on use.
	 * @param IAppManager        $appManager Tells whether portaliq is installed.
	 * @param LoggerInterface    $logger     The logger.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether portaliq is installed.
	 *
	 * @return bool True when articles can be read at all.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function isAvailable(): bool {
		try {
			return FleetAppId::isInstalled(appManager: $this->appManager, canonical: self::APP);
		} catch (Throwable $e) {
			return false;
		}
	}//end isAvailable()

	/**
	 * Whether portaliq is installed AND its article schema answers a query.
	 *
	 * @return bool True when articles can be written.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function isReady(): bool {
		$service = $this->objectService();
		if ($service === null) {
			return false;
		}

		try {
			$service->findAll(
				config: ['limit' => 1, 'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: portaliq article schema is not available', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end isReady()

	/**
	 * One article by its id, its slug or its legacy reference.
	 *
	 * A template names an article by whichever identity the marketer's client
	 * had to hand, and a moved template may still carry the old pipelinq id
	 * until the move has rewritten it, so all three are tried.
	 *
	 * @param string $identity The article's id, slug or legacyRef.
	 *
	 * @return array<string, mixed>|null The article, or null.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function find(string $identity): ?array {
		$identity = trim($identity);
		$service  = $this->objectService();
		if ($identity === '' || $service === null) {
			return null;
		}

		try {
			$entity = $service->find(
				id: $identity,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
			);
			if ($entity !== null) {
				return $this->toArray(value: $entity);
			}
		} catch (Throwable $e) {
			// Not an id: fall through to the slug and legacyRef lookup.
		}

		foreach ($this->findAll() as $article) {
			$self = ($article['@self'] ?? []);
			if ((string)($article['slug'] ?? '') === $identity
				|| (is_array($self) === true && (string)($self['slug'] ?? '') === $identity)
				|| (string)($article['legacyRef'] ?? '') === $identity
				|| (string)($article['legacyRef'] ?? '') === 'pipelinq:'.$identity
			) {
				return $article;
			}
		}

		return null;
	}//end find()

	/**
	 * Every article, optionally narrowed by exact field values.
	 *
	 * @param array<string, scalar> $filters Field => value.
	 *
	 * @return array<int, array<string, mixed>> The articles.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq
	 */
	public function findAll(array $filters = []): array {
		$service = $this->objectService();
		if ($service === null) {
			return [];
		}

		try {
			$rows = $service->findAll(
				config: ['filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => self::SCHEMA])],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: portaliq articles could not be read', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)($rows ?? []) as $row) {
			$payload = $this->toArray(value: $row);
			if ($payload === [] || $this->matches(payload: $payload, filters: $filters) === false) {
				continue;
			}

			$out[] = $payload;
		}

		return $out;
	}//end findAll()

	/**
	 * The published articles, for a picker.
	 *
	 * @return array<int, array<string, mixed>> The published articles.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
	 */
	public function published(): array {
		return $this->findAll(filters: ['status' => 'published']);
	}//end published()

	/**
	 * Create an article in portaliq.
	 *
	 * @param array<string, mixed> $payload The article.
	 *
	 * @return array<string, mixed>|null The saved article, or null on failure.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function create(array $payload): ?array {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		try {
			$saved = $service->saveObject(
				object: $payload,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: an article could not be written to portaliq', ['reason' => $e->getMessage()]);
			return null;
		}

		$out = $this->toArray(value: $saved);
		if ($out === []) {
			return null;
		}

		return $out;
	}//end create()

	/**
	 * An article's id: its uuid or id, at the top level or under `@self`.
	 *
	 * @param array<string, mixed>|null $payload The article.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function idOf(?array $payload): string {
		$self = ($payload['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($payload ?? []), $self] as $source) {
			foreach (['uuid', 'id'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * OpenRegister's ObjectService when portaliq is installed, else null.
	 *
	 * @return object|null The service.
	 */
	private function objectService(): ?object {
		if ($this->isAvailable() === false) {
			$this->logger->debug('Pipelinq: portaliq is not installed, no marketing articles are read');
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: OpenRegister is unavailable for portaliq articles', ['reason' => $e->getMessage()]);
			return null;
		}
	}//end objectService()

	/**
	 * Whether a payload carries every filter value.
	 *
	 * @param array<string, mixed>  $payload The article.
	 * @param array<string, scalar> $filters Field => value.
	 *
	 * @return bool True on a match.
	 */
	private function matches(array $payload, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ((string)($payload[$field] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
