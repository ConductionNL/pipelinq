<?php

/**
 * Pipelinq PostSettlement.
 *
 * The status a social post settles on from its publications: failed when
 * any failed, published (stamped once) when all are done, scheduled otherwise.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Social\SocialPublicationStore;

/**
 * The settled status of a social post; built by SocialPostService.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
final class PostSettlement {
	/**
	 * The post with the status its publications give it: failed when any
	 * failed, published when all are done, scheduled otherwise.
	 *
	 * @param array<string, mixed>             $post The post as read.
	 * @param array<int, array<string, mixed>> $rows Its publications.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function settled(array $post, array $rows): array {
		[$failures, $done] = $this->tallyPublications(rows: $rows);

		$post['status'] = SocialPostService::STATUS_SCHEDULED;
		$post['failureReason'] = '';
		if ($failures !== []) {
			$post['status'] = SocialPostService::STATUS_FAILED;
			$post['failureReason'] = implode(' ', $failures);
		} elseif ($rows !== [] && $done === count($rows)) {
			$post = $this->markedPublished(post: $post);
		}

		return $post;
	}//end settled()


	/**
	 * The failure lines (`network: reason`) and the number of settled publications.
	 *
	 * @param array<int, array<string, mixed>> $rows The publications.
	 *
	 * @return array{0: array<int, string>, 1: int}
	 */
	private function tallyPublications(array $rows): array {
		$failures = [];
		$done = 0;
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if ($status === SocialPublicationStore::FAILED) {
				$failures[] = (string)($row['network'] ?? '') . ': ' . (string)($row['failureReason'] ?? '');
				continue;
			}

			if (in_array($status, SocialPostService::SETTLED_STATUSES, true) === true) {
				$done++;
			}
		}

		return [$failures, $done];
	}//end tallyPublications()


	/**
	 * The post marked published, stamped now unless it already carries a moment.
	 *
	 * @param array<string, mixed> $post The post.
	 *
	 * @return array<string, mixed>
	 */
	private function markedPublished(array $post): array {
		$post['status'] = SocialPostService::STATUS_PUBLISHED;
		if (trim((string)($post['publishedAt'] ?? '')) === '') {
			$post['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		}

		return $post;
	}//end markedPublished()
}//end class
