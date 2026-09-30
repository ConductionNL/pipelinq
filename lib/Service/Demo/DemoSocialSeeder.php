<?php

/**
 * Pipelinq DemoSocialSeeder.
 *
 * Seeds and removes the demo social accounts, published posts and their
 * publications, for DemoSeedService.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Service\SocialPostService;

/**
 * Demo social data: the aftermath of publishing, written straight to the store.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoSocialSeeder {
	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Session-free object access.
	 * @param DemoSeedValues $values Placeholder resolution.
	 */
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly DemoSeedValues $values,
	) {
	}//end __construct()

	/**
	 * Seed the demo social accounts, published posts and their publications.
	 *
	 * Written straight through the object store rather than the publishing
	 * path, because what is seeded is the aftermath of publishing: the posts
	 * are already out and the publications already carry the numbers the daily
	 * pull would have stored. Nothing here touches a network.
	 *
	 * A post that already exists is skipped with its publications, which keeps
	 * a re-run from doubling the ranking.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $social The seed file's `social` section.
	 *
	 * @return array{accounts: int, posts: int, publications: int, skipped: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(array $social): array {
		$counts = ['accounts' => 0, 'posts' => 0, 'publications' => 0, 'skipped' => 0];
		if ($social === []) {
			return $counts;
		}

		[$accountSchema, $postSchema, $publicationSchema] = $this->schemas();

		$accounts = $this->seedAccounts(definitions: ($social['accounts'] ?? []), schemaSlug: $accountSchema);
		$posts = $this->seedPosts(definitions: ($social['posts'] ?? []), schemaSlug: $postSchema, accountIds: $accounts['ids']);

		$counts['accounts'] = $accounts['created'];
		$counts['posts'] = $posts['created'];
		$counts['skipped'] = ($accounts['skipped'] + $posts['skipped']);
		$counts['publications'] = $this->seedPublications(
			definitions: ($social['publications'] ?? []),
			schemaSlug: $publicationSchema,
			postIds: $posts['ids'],
			accountIds: $accounts['ids'],
			networks: $accounts['networks'],
		);

		return $counts;
	}//end seed()

	/**
	 * Remove the demo publications, posts and accounts, in that order.
	 *
	 * Only rows whose name carries the demo marker are touched, and only the
	 * publications of those demo posts.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $social The seed file's `social` section.
	 *
	 * @return array{accounts: int, posts: int, publications: int} Counts.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(array $social): array {
		$counts = ['accounts' => 0, 'posts' => 0, 'publications' => 0];
		if ($social === []) {
			return $counts;
		}

		[$accountSchema, $postSchema, $publicationSchema] = $this->schemas();

		$postIds = $this->demoIds(
			definitions: ($social['posts'] ?? []),
			schemaSlug: $postSchema,
			field: 'title',
		);

		foreach ($this->store->findAll(schemaSlug: $publicationSchema) as $publication) {
			if (in_array((string)($publication['postId'] ?? ''), $postIds, true) === true
				&& $this->store->delete(schemaSlug: $publicationSchema, id: $this->store->idOf(payload: $publication)) === true
			) {
				$counts['publications']++;
			}
		}

		$counts['posts'] = $this->deleteAll(schemaSlug: $postSchema, ids: $postIds);
		$counts['accounts'] = $this->deleteAll(
			schemaSlug: $accountSchema,
			ids: $this->demoIds(definitions: ($social['accounts'] ?? []), schemaSlug: $accountSchema, field: 'displayName'),
		);

		return $counts;
	}//end remove()

	/**
	 * Seed the demo accounts, reusing an account that already exists.
	 *
	 * @param array<int, array<string, mixed>> $definitions The `social.accounts` definitions.
	 * @param string $schemaSlug The account schema.
	 *
	 * @return array{created: int, skipped: int, ids: array<string, string>, networks: array<string, string>}
	 *         Counts, seed key => account id, and seed key => network.
	 */
	private function seedAccounts(array $definitions, string $schemaSlug): array {
		$result = ['created' => 0, 'skipped' => 0, 'ids' => [], 'networks' => []];
		$existing = $this->idsByField(schemaSlug: $schemaSlug, field: 'displayName');

		foreach ($definitions as $definition) {
			$data = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			$result['networks'][$definition['key']] = (string)($data['network'] ?? '');

			$id = ($existing[(string)($data['displayName'] ?? '')] ?? '');
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['skipped']++;
				continue;
			}

			$id = $this->store->idOf(payload: $this->store->save(schemaSlug: $schemaSlug, payload: $data));
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['created']++;
			}
		}

		return $result;
	}//end seedAccounts()

	/**
	 * Seed the demo posts, linked to their accounts. A post that exists is skipped.
	 *
	 * @param array<int, array<string, mixed>> $definitions The `social.posts` definitions.
	 * @param string $schemaSlug The post schema.
	 * @param array<string, string> $accountIds Account seed key => account id.
	 *
	 * @return array{created: int, skipped: int, ids: array<string, string>} Counts and seed key => id of each NEW post.
	 */
	private function seedPosts(array $definitions, string $schemaSlug, array $accountIds): array {
		$result = ['created' => 0, 'skipped' => 0, 'ids' => []];
		$existing = $this->idsByField(schemaSlug: $schemaSlug, field: 'title');

		foreach ($definitions as $definition) {
			$data = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			if (($existing[(string)($data['title'] ?? '')] ?? '') !== '') {
				$result['skipped']++;
				continue;
			}

			$data['accountIds'] = array_values(
				array_filter(
					array_map(
						static fn (string $key): string => ($accountIds[$key] ?? ''),
						($definition['accountKeys'] ?? [])
					)
				)
			);

			$id = $this->store->idOf(payload: $this->store->save(schemaSlug: $schemaSlug, payload: $data));
			if ($id !== '') {
				$result['ids'][$definition['key']] = $id;
				$result['created']++;
			}
		}

		return $result;
	}//end seedPosts()

	/**
	 * Seed the publications of the posts seeded in this run.
	 *
	 * @param array<int, array<string, mixed>> $definitions The `social.publications` definitions.
	 * @param string $schemaSlug The publication schema.
	 * @param array<string, string> $postIds Post seed key => id of each new post.
	 * @param array<string, string> $accountIds Account seed key => account id.
	 * @param array<string, string> $networks Account seed key => network.
	 *
	 * @return int How many publications were saved.
	 */
	private function seedPublications(array $definitions, string $schemaSlug, array $postIds, array $accountIds, array $networks): int {
		$saved = 0;
		foreach ($definitions as $definition) {
			$postId = ($postIds[$definition['postKey'] ?? ''] ?? '');
			$accountId = ($accountIds[$definition['accountKey'] ?? ''] ?? '');
			if ($postId === '' || $accountId === '') {
				continue;
			}

			$payload = $this->values->resolvePlaceholders(data: ($definition['data'] ?? []));
			$payload['postId'] = $postId;
			$payload['accountId'] = $accountId;
			$payload['network'] = ($networks[$definition['accountKey']] ?? '');

			if ($this->store->save(schemaSlug: $schemaSlug, payload: $payload) !== null) {
				$saved++;
			}
		}

		return $saved;
	}//end seedPublications()

	/**
	 * The ids of the existing rows a seed section names, demo-marked ones only.
	 *
	 * @param array<int, array<string, mixed>> $definitions The seed section.
	 * @param string $schemaSlug The schema the rows live in.
	 * @param string $field The field that carries the demo marker.
	 *
	 * @return array<int, string> The row ids.
	 */
	private function demoIds(array $definitions, string $schemaSlug, string $field): array {
		$rows = $this->idsByField(schemaSlug: $schemaSlug, field: $field);
		$ids = [];
		foreach ($definitions as $definition) {
			$value = (string)($definition['data'][$field] ?? '');
			if (str_starts_with($value, DemoSeedService::DEMO_PREFIX) === true && ($rows[$value] ?? '') !== '') {
				$ids[] = $rows[$value];
			}
		}

		return $ids;
	}//end demoIds()

	/**
	 * Delete rows by id.
	 *
	 * @param string $schemaSlug The schema the rows live in.
	 * @param array<int, string> $ids The row ids.
	 *
	 * @return int How many were deleted.
	 */
	private function deleteAll(string $schemaSlug, array $ids): int {
		$deleted = 0;
		foreach ($ids as $id) {
			if ($this->store->delete(schemaSlug: $schemaSlug, id: $id) === true) {
				$deleted++;
			}
		}

		return $deleted;
	}//end deleteAll()

	/**
	 * The account, post and publication schema slugs, as their services resolve them.
	 *
	 * @return array{0: string, 1: string, 2: string} The three slugs.
	 */
	private function schemas(): array {
		return [
			$this->store->schemaSlug(SocialAccountService::SCHEMA_CONFIG_KEY, SocialAccountService::SCHEMA),
			$this->store->schemaSlug(SocialPostService::SCHEMA_CONFIG_KEY, SocialPostService::SCHEMA),
			$this->store->schemaSlug(SocialPublicationStore::SCHEMA_CONFIG_KEY, SocialPublicationStore::SCHEMA),
		];
	}//end schemas()

	/**
	 * Every row of a schema, indexed by one field's value.
	 *
	 * @param string $schemaSlug The schema to read.
	 * @param string $field The field to index by.
	 *
	 * @return array<string, string> Field value => the first row id carrying it.
	 */
	private function idsByField(string $schemaSlug, string $field): array {
		$index = [];
		foreach ($this->store->findAll(schemaSlug: $schemaSlug) as $row) {
			$value = (string)($row[$field] ?? '');
			if ($value !== '' && isset($index[$value]) === false) {
				$index[$value] = $this->store->idOf(payload: $row);
			}
		}

		return $index;
	}//end idsByField()
}//end class
