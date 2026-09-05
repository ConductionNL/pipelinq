<?php

/**
 * Pipelinq SocialPublicationStore.
 *
 * The per-account result rows: created before anything is sent, updated when
 * it lands, and read by the metrics pull and the ranking.
 *
 * Creating the row FIRST is the point. A publication that only exists after a
 * successful send has nowhere to record a failure, so a failure becomes a log
 * line nobody reads and a post that quietly went to four accounts instead of
 * five. Every account a post names gets a row in `pending`, and the row is
 * what changes.
 *
 * It is a separate class from both the publishing service and the metrics
 * service because both write these rows. Duplicating the shape in two places
 * is exactly the duplication ADR-012 exists to stop.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * The per-account publication rows.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialPublicationStore {
	/**
	 * The publication schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	public const SCHEMA = 'socialPublication';

	/**
	 * App-config key that may override the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'social_publication_schema';

	/**
	 * Created, nothing sent yet.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * The network accepted it.
	 *
	 * @var string
	 */
	public const PUBLISHED = 'published';

	/**
	 * It did not go out, and `failureCode` says why.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * No application may post here; the owner has been asked to.
	 *
	 * @var string
	 */
	public const AWAITING_SHARE = 'awaiting_share';

	/**
	 * The owner confirmed they posted it themselves.
	 *
	 * @var string
	 */
	public const SHARED = 'shared';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store The register-scoped object plumbing.
	 *
	 * @return void
	 */
	public function __construct(private readonly ListObjectStore $store) {
	}//end __construct()

	/**
	 * The schema slug, honouring an app-config override.
	 *
	 * @return string The schema slug.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}//end schema()

	/**
	 * Every publication for one post.
	 *
	 * @param string $postId The post.
	 *
	 * @return array<int, array<string, mixed>> The publications.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function forPost(string $postId): array {
		return $this->store->findAll(schemaSlug: $this->schema(), filters: ['postId' => $postId]);
	}//end forPost()

	/**
	 * Every publication, for the metrics pull and the ranking.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The publications.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-every-publications-numbers-are-pulled-daily-and-normalised
	 */
	public function findAll(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schema(), filters: $filters);
	}//end findAll()

	/**
	 * One publication, or null.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return array<string, mixed>|null The publication, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function find(string $publicationId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $publicationId);
	}//end find()

	/**
	 * The pending row for one post and one account, creating it when the post
	 * has not been attempted for that account before.
	 *
	 * A retry reuses the row rather than adding a second one, so `attempts`
	 * counts attempts and the list does not grow a line per try.
	 *
	 * @param string $postId The post.
	 * @param string $accountId The account.
	 * @param string $network The account's network, copied on so a ranking can group without a join.
	 *
	 * @return array<string, mixed>|null The row, or null when it could not be written.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function open(string $postId, string $accountId, string $network): ?array {
		foreach ($this->forPost(postId: $postId) as $row) {
			if ((string)($row['accountId'] ?? '') === $accountId) {
				return $row;
			}
		}

		return $this->store->save(
			schemaSlug: $this->schema(),
			payload: [
				'postId' => $postId,
				'accountId' => $accountId,
				'network' => $network,
				'status' => self::PENDING,
				'metrics' => ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0],
				'cost' => 0,
				'attempts' => 0,
				'failureCode' => '',
				'failureReason' => '',
			],
		);
	}//end open()

	/**
	 * Persist a changed publication row.
	 *
	 * @param array<string, mixed> $publication The row, with its id.
	 *
	 * @return array<string, mixed>|null The saved row, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function save(array $publication): ?array {
		return $this->store->save(
			schemaSlug: $this->schema(),
			payload: $publication,
			id: $this->store->idOf(payload: $publication),
		);
	}//end save()

	/**
	 * The canonical id of a publication row.
	 *
	 * @param array<string, mixed>|null $publication The row.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function idOf(?array $publication): string {
		return $this->store->idOf(payload: $publication);
	}//end idOf()

	/**
	 * Record what one publish attempt did.
	 *
	 * @param array<string, mixed> $publication The row as read.
	 * @param SocialPublishOutcome $outcome What the network said.
	 *
	 * @return array<string, mixed>|null The saved row, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function record(array $publication, SocialPublishOutcome $outcome): ?array {
		$publication['attempts'] = ((int)($publication['attempts'] ?? 0) + 1);
		$publication['cost'] = ((float)($publication['cost'] ?? 0) + $outcome->cost);

		if ($outcome->accepted === false) {
			$publication['status'] = self::FAILED;
			$publication['failureCode'] = $outcome->failureCode;
			$publication['failureReason'] = $outcome->failureReason;

			return $this->save(publication: $publication);
		}

		$publication['status'] = self::PUBLISHED;
		$publication['externalId'] = $outcome->externalId;
		$publication['url'] = $outcome->url;
		$publication['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$publication['failureCode'] = '';
		$publication['failureReason'] = '';

		return $this->save(publication: $publication);
	}//end record()
}//end class
