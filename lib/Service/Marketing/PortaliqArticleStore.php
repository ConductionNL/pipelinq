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

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
		return $this->query(config: ['limit' => 1], level: 'info', message: 'Pipelinq: portaliq article schema is not available') !== null;
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
		if ($identity === '') {
			return null;
		}

		$byId = $this->findById(identity: $identity);
		if ($byId !== null) {
			return $byId;
		}

		foreach ($this->findAll() as $article) {
			if (in_array($identity, $this->namesOf(article: $article), true) === true) {
				return $article;
			}
		}

		return null;
	}//end find()

	/**
	 * One article by its id.
	 *
	 * @param string $identity The article's id, or another identity that is not an id.
	 *
	 * @return array<string, mixed>|null The article, or null.
	 */
	private function findById(string $identity): ?array {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		$options = new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA);
		try {
			$entity = $service->find(id: $identity, options: $options, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Not an id: the caller falls through to the slug and legacyRef lookup.
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end findById()

	/**
	 * The names an article answers to besides its id: its slug, its object
	 * slug, its legacyRef and, for a pipelinq legacyRef, the old pipelinq id.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return array<int, string> The names, possibly with empty strings.
	 */
	private function namesOf(array $article): array {
		$self = ($article['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$legacy = (string)($article['legacyRef'] ?? '');
		$names  = [(string)($article['slug'] ?? ''), (string)($self['slug'] ?? ''), $legacy];
		if (str_starts_with($legacy, 'pipelinq:') === true) {
			$names[] = substr($legacy, strlen('pipelinq:'));
		}

		return $names;
	}//end namesOf()

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
		$rows = $this->query(config: ['filters' => $filters], level: 'warning', message: 'Pipelinq: portaliq articles could not be read');
		$out  = [];
		foreach (($rows ?? []) as $row) {
			$payload = $this->toArray(value: $row);
			if ($payload !== [] && $this->matches(payload: $payload, filters: $filters) === true) {
				$out[] = $payload;
			}
		}

		return $out;
	}//end findAll()

	/**
	 * Query portaliq's article schema, logging and swallowing a failure.
	 *
	 * @param array<string, mixed> $config  The query config; register and schema are added to its filters.
	 * @param string               $level   The log level of a failure.
	 * @param string               $message The log message of a failure.
	 *
	 * @return array<int, mixed>|null The rows, or null when portaliq is missing or the query failed.
	 */
	private function query(array $config, string $level, string $message): ?array {
		$service = $this->objectService();
		if ($service === null) {
			return null;
		}

		$config['filters'] = array_merge(($config['filters'] ?? []), ['register' => self::REGISTER, 'schema' => self::SCHEMA]);
		try {
			$rows = $service->findAll(config: $config, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->log($level, $message, ['reason' => $e->getMessage()]);
			return null;
		}

		return (array)($rows ?? []);
	}//end query()

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

		$target = new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA);
		try {
			$saved = $service->saveObject(object: $payload, options: new ObjectSaveOptions(target: $target), _rbac: false, _multitenancy: false);
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
