<?php

/**
 * Pipelinq ArticleMoveService.
 *
 * Moves pipelinq's marketing articles into portaliq once
 * (marketing-articles-move-to-portaliq, design section 4). Every pipelinq
 * `article` is copied into portaliq's `article` schema with every property
 * intact and `legacyRef = pipelinq:<uuid>`; the campaign templates and social
 * posts that named it are rewritten to the new id; the pipelinq article is
 * marked `migratedTo` and left in place. A second run finds every article by
 * its `legacyRef` and copies nothing again.
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
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use Psr\Log\LoggerInterface;

/**
 * Copies pipelinq articles into portaliq and rewrites the references to them.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
 */
class ArticleMoveService {

	/**
	 * The prefix of a moved article's legacyRef.
	 *
	 * @var string
	 */
	public const LEGACY_PREFIX = 'pipelinq:';

	/**
	 * The suffix a colliding slug gets in portaliq.
	 *
	 * @var string
	 */
	public const SLUG_SUFFIX = '-pipelinq';

	/**
	 * The properties copied, as design section 4 lists them.
	 *
	 * @var array<int, string>
	 */
	private const COPIED = [
		'title',
		'slug',
		'summary',
		'body',
		'heroImage',
		'links',
		'tags',
		'language',
		'status',
		'author',
		'publishedAt',
		'portalPageRef',
		'agentAuthored',
		'agentAuthoredBy',
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore      $store    pipelinq's register.
	 * @param PortaliqArticleStore $portaliq portaliq's articles.
	 * @param LoggerInterface      $logger   The logger.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function __construct(
		private ListObjectStore $store,
		private PortaliqArticleStore $portaliq,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the move.
	 *
	 * @return array{status: string, moved: int, alreadyMoved: int, failed: int, references: int, renamedSlugs: array<int, string>}
	 *   `status` is `portaliq-missing`, `done` or `failures`.
	 *
	 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once
	 */
	public function run(): array {
		$result = ['status' => 'portaliq-missing', 'moved' => 0, 'alreadyMoved' => 0, 'failed' => 0, 'references' => 0, 'renamedSlugs' => []];
		if ($this->portaliq->isReady() === false) {
			$this->logger->info('Pipelinq article move: portaliq or its article schema is not available, nothing moved');
			return $result;
		}

		$existing = $this->portaliq->findAll();
		$byLegacy = [];
		$slugs    = [];
		foreach ($existing as $article) {
			$legacy = (string)($article['legacyRef'] ?? '');
			if (str_starts_with($legacy, self::LEGACY_PREFIX) === true) {
				$byLegacy[$legacy] = $article;
			}

			$slugs[(string)($article['slug'] ?? '')] = true;
		}

		$renames = [];
		foreach ($this->store->findAll(schemaSlug: $this->articleSchema()) as $article) {
			$oldId = $this->store->idOf(payload: $article);
			if ($oldId === '') {
				continue;
			}

			$legacy = self::LEGACY_PREFIX.$oldId;
			$target = ($byLegacy[$legacy] ?? null);
			if ($target !== null) {
				$result['alreadyMoved']++;
			} else {
				$target = $this->copy(article: $article, legacyRef: $legacy, slugs: $slugs, result: $result);
				if ($target === null) {
					$result['failed']++;
					continue;
				}

				$result['moved']++;
			}

			$newId = $this->portaliq->idOf(payload: $target);
			foreach ($this->identitiesOf(object: $article) as $identity) {
				$renames[$identity] = $newId;
			}

			$this->markMigrated(article: $article, oldId: $oldId, newId: $newId);
		}//end foreach

		$result['references'] = $this->rewriteReferences(renames: $renames);
		$result['status']     = 'done';
		if ($result['failed'] > 0) {
			$result['status'] = 'failures';
		}

		$this->logger->info('Pipelinq article move into portaliq', $result);
		return $result;
	}//end run()

	/**
	 * Copy one article into portaliq.
	 *
	 * @param array<string, mixed> $article   The pipelinq article.
	 * @param string               $legacyRef Its legacy reference.
	 * @param array<string, bool>  $slugs     The slugs portaliq holds, updated.
	 * @param array<string, mixed> $result    The report, its renamed slugs updated.
	 *
	 * @return array<string, mixed>|null The portaliq article, or null on failure.
	 */
	private function copy(array $article, string $legacyRef, array &$slugs, array &$result): ?array {
		$payload = [];
		foreach (self::COPIED as $key) {
			if (array_key_exists($key, $article) === true && $article[$key] !== null) {
				$payload[$key] = $article[$key];
			}
		}

		$payload['legacyRef'] = $legacyRef;
		$slug = (string)($payload['slug'] ?? '');
		if ($slug !== '' && isset($slugs[$slug]) === true) {
			$payload['slug']          = $slug.self::SLUG_SUFFIX;
			$result['renamedSlugs'][] = $slug;
		}

		$saved = $this->portaliq->create(payload: $payload);
		if ($saved === null || $this->portaliq->idOf(payload: $saved) === '') {
			$this->logger->warning('Pipelinq article move: an article could not be copied into portaliq', ['legacyRef' => $legacyRef]);
			return null;
		}

		$slugs[(string)($payload['slug'] ?? '')] = true;
		return $saved;
	}//end copy()

	/**
	 * Mark a pipelinq article with the id it moved to, once.
	 *
	 * @param array<string, mixed> $article The pipelinq article.
	 * @param string               $oldId   Its id.
	 * @param string               $newId   The portaliq id.
	 *
	 * @return void
	 */
	private function markMigrated(array $article, string $oldId, string $newId): void {
		if ((string)($article['migratedTo'] ?? '') === $newId) {
			return;
		}

		$article['migratedTo'] = $newId;
		unset($article['@self']);
		$this->store->save(schemaSlug: $this->articleSchema(), payload: $article, id: $oldId);
	}//end markMigrated()

	/**
	 * Rewrite `campaignTemplate.articleIds` and `socialPost.articleId`.
	 *
	 * @param array<string, string> $renames Old identity => portaliq id.
	 *
	 * @return int How many objects were rewritten.
	 */
	private function rewriteReferences(array $renames): int {
		if ($renames === []) {
			return 0;
		}

		$count  = 0;
		$schema = $this->store->schemaSlug(configKey: 'campaignTemplate_schema', default: 'campaignTemplate');
		foreach ($this->store->findAll(schemaSlug: $schema) as $template) {
			$ids = ($template['articleIds'] ?? null);
			if (is_array($ids) === false) {
				continue;
			}

			$rewritten = [];
			foreach ($ids as $id) {
				if (is_scalar($id) === true && isset($renames[(string)$id]) === true) {
					$id = $renames[(string)$id];
				}

				$rewritten[] = $id;
			}

			if ($rewritten !== array_values($ids)) {
				$count += $this->saveRewritten(schema: $schema, object: $template, field: 'articleIds', value: $rewritten);
			}
		}

		$schema = $this->store->schemaSlug(configKey: 'social_post_schema', default: 'socialPost');
		foreach ($this->store->findAll(schemaSlug: $schema) as $post) {
			$id = ($post['articleId'] ?? null);
			if (is_scalar($id) === true && isset($renames[(string)$id]) === true && $renames[(string)$id] !== (string)$id) {
				$count += $this->saveRewritten(schema: $schema, object: $post, field: 'articleId', value: $renames[(string)$id]);
			}
		}

		return $count;
	}//end rewriteReferences()

	/**
	 * Save one rewritten reference.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $object The object.
	 * @param string               $field  The reference field.
	 * @param mixed                $value  Its new value.
	 *
	 * @return int 1 when saved, else 0.
	 */
	private function saveRewritten(string $schema, array $object, string $field, mixed $value): int {
		$id = $this->store->idOf(payload: $object);
		$object[$field] = $value;
		unset($object['@self']);
		if ($this->store->save(schemaSlug: $schema, payload: $object, id: $id) === null) {
			$this->logger->warning('Pipelinq article move: a reference could not be rewritten', ['schema' => $schema, 'id' => $id]);
			return 0;
		}

		return 1;
	}//end saveRewritten()

	/**
	 * Every identity an object answers to: uuid, id and slug, at the top level and under `@self`.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<int, string> Non-empty identities.
	 */
	private function identitiesOf(array $object): array {
		$out  = [];
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach (['uuid', 'id', 'slug'] as $key) {
			foreach ([$object, $self] as $source) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					$out[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($out));
	}//end identitiesOf()

	/**
	 * pipelinq's article schema slug.
	 *
	 * @return string The slug.
	 */
	private function articleSchema(): string {
		return $this->store->schemaSlug(configKey: 'article_schema', default: 'article');
	}//end articleSchema()
}//end class
