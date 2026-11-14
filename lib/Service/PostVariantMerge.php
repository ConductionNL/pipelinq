<?php

/**
 * Pipelinq PostVariantMerge.
 *
 * How one network's variant of a social post merges onto the post's own
 * values: a non-empty body or link replaces the post's, media only when the
 * variant carries any.
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
 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The variant merge of a social post; built by SocialPostService.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
final class PostVariantMerge {
	/**
	 * Merge a variant's non-empty body and link, and its media when it has any.
	 *
	 * @param array{body: string, link: string, media: array<int, array<string, mixed>>} $resolved The post's own values.
	 * @param array<string, mixed>                                                     $variant  The network's variant.
	 *
	 * @return array{body: string, link: string, media: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function merged(array $resolved, array $variant): array {
		foreach (['body', 'link'] as $field) {
			$value = trim((string)($variant[$field] ?? ''));
			if ($value !== '') {
				$resolved[$field] = $value;
			}
		}

		$variantMedia = ($variant['media'] ?? null);
		if (is_array($variantMedia) === true && $variantMedia !== []) {
			$resolved['media'] = $variantMedia;
		}

		return $resolved;
	}//end merged()


	/**
	 * The post's variant for one network, when it carries one.
	 *
	 * @param array<string, mixed> $post    The post.
	 * @param string               $network The network.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function variantFor(array $post, string $network): ?array {
		$variants = ($post['variants'] ?? []);
		if (is_array($variants) === false) {
			return null;
		}

		$variant = ($variants[$network] ?? null);
		if (is_array($variant) === false) {
			return null;
		}

		return $variant;
	}//end variantFor()
}//end class
