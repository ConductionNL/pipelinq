<?php

/**
 * Unit tests for PostVariantMerge.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\PostVariantMerge;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PostVariantMerge: overlaying a per-network variant onto a resolved post, and picking the variant for a network.
 *
 * @covers \OCA\Pipelinq\Service\PostVariantMerge
 */
class PostVariantMergeTest extends TestCase {

	/**
	 * A non-blank variant body and link (trimmed) and non-empty media replace the resolved values.
	 *
	 * @return void
	 */
	public function testMergedOverlaysFilledVariantFields(): void {
		$resolved = ['body' => 'base', 'link' => 'https://a', 'media' => ['a.png'], 'title' => 'T'];

		self::assertSame(
			expected: ['body' => 'short', 'link' => 'https://b', 'media' => ['b.png'], 'title' => 'T'],
			actual: (new PostVariantMerge())->merged(
				resolved: $resolved,
				variant: ['body' => ' short ', 'link' => 'https://b', 'media' => ['b.png'], 'title' => 'ignored']
			)
		);
	}//end testMergedOverlaysFilledVariantFields()

	/**
	 * Blank text, empty media and non-array media leave the resolved post as it is.
	 *
	 * @return void
	 */
	public function testMergedKeepsResolvedValuesForBlankVariantFields(): void {
		$resolved = ['body' => 'base', 'link' => 'https://a', 'media' => ['a.png']];
		$merge    = new PostVariantMerge();

		self::assertSame(expected: $resolved, actual: $merge->merged(resolved: $resolved, variant: ['body' => '   ', 'link' => '', 'media' => []]));
		self::assertSame(expected: $resolved, actual: $merge->merged(resolved: $resolved, variant: ['media' => 'b.png']));
		self::assertSame(expected: $resolved, actual: $merge->merged(resolved: $resolved, variant: []));
	}//end testMergedKeepsResolvedValuesForBlankVariantFields()

	/**
	 * The variant for a network is returned only when the variants and the entry are arrays.
	 *
	 * @return void
	 */
	public function testVariantFor(): void {
		$merge = new PostVariantMerge();
		$post  = ['variants' => ['x' => ['body' => 'X'], 'linkedin' => 'text']];

		self::assertSame(expected: ['body' => 'X'], actual: $merge->variantFor(post: $post, network: 'x'));
		self::assertNull(actual: $merge->variantFor(post: $post, network: 'linkedin'));
		self::assertNull(actual: $merge->variantFor(post: $post, network: 'mastodon'));
		self::assertNull(actual: $merge->variantFor(post: ['variants' => 'none'], network: 'x'));
		self::assertNull(actual: $merge->variantFor(post: [], network: 'x'));
	}//end testVariantFor()
}//end class
