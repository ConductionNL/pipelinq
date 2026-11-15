<?php

/**
 * Behavioural tests for the PostMetricsCall trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social\Support;

use OCA\Pipelinq\Service\Social\SocialAdapterCall;
use OCA\Pipelinq\Service\Social\Support\PostMetricsCall;
use PHPUnit\Framework\TestCase;

/**
 * A GET for an identified post, nothing for a blank id.
 *
 * @covers \OCA\Pipelinq\Service\Social\Support\PostMetricsCall
 */
class PostMetricsCallTest extends TestCase {

	/**
	 * @return void
	 */
	public function testPostMetricsGet(): void {
		$host = new class {
			use PostMetricsCall;

			/**
			 * @param string $externalId The post id.
			 * @param string $path       The path.
			 * @return SocialAdapterCall|null
			 */
			public function call(string $externalId, string $path): ?SocialAdapterCall {
				return $this->postMetricsGet(externalId: $externalId, path: $path);
			}
		};

		$this->assertNull($host->call('  ', '/x'));
		$call = $host->call('p1', '/posts/p1');
		$this->assertEquals(new SocialAdapterCall(method: 'GET', path: '/posts/p1'), $call);
	}//end testPostMetricsGet()
}//end class
