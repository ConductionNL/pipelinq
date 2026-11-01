<?php

/**
 * Golden test for PortalContributionProvider's manifests.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
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
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * The full manifest for every audience, and the audience list, compared with
 * PortalContributionGolden.json: every scope, filter, field projection, create
 * whitelist and page block exactly as it is served. Set
 * PORTAL_CONTRIBUTION_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/PortalContributionGolden.json';

	/**
	 * Compare every manifest with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$provider = new PortalContributionProvider();
		$actual = [
			'audiences' => $provider->getAudiences(),
			'audience' => $provider->getAudience(),
		];
		foreach (['client', 'customer', 'citizen', 'employee', '', 'CLIENT'] as $audience) {
			$actual['contribution: ' . ($audience === '' ? '(empty)' : $audience)] = $provider->getContribution(['audience' => $audience, 'claims' => ['pipelinq' => ['clientId' => 'forged']]]);
		}

		$actual['contribution: no audience'] = $provider->getContribution([]);

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('PORTAL_CONTRIBUTION_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()
}//end class
