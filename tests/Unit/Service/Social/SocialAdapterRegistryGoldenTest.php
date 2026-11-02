<?php

/**
 * Golden test for the social network adapter registry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Social
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

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\FacebookPageAdapter;
use OCA\Pipelinq\Service\Social\IndependentNetworkAdapters;
use OCA\Pipelinq\Service\Social\InstagramBusinessAdapter;
use OCA\Pipelinq\Service\Social\LinkedInAdapter;
use OCA\Pipelinq\Service\Social\MastodonAdapter;
use OCA\Pipelinq\Service\Social\MetaNetworkAdapters;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\ThreadsAdapter;
use OCA\Pipelinq\Service\Social\XAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Asks the registry about every network it knows and one it does not, and
 * compares the answers with SocialAdapterRegistryGolden.json. Set
 * SOCIAL_REGISTRY_GOLDEN_RECORD=1 to rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\Service\Social\SocialAdapterRegistry
 * @covers \OCA\Pipelinq\Service\Social\IndependentNetworkAdapters
 * @covers \OCA\Pipelinq\Service\Social\MetaNetworkAdapters
 * @uses   \OCA\Pipelinq\Service\Social\BlueskyAdapter
 * @uses   \OCA\Pipelinq\Service\Social\FacebookPageAdapter
 * @uses   \OCA\Pipelinq\Service\Social\InstagramBusinessAdapter
 * @uses   \OCA\Pipelinq\Service\Social\LinkedInAdapter
 * @uses   \OCA\Pipelinq\Service\Social\MastodonAdapter
 * @uses   \OCA\Pipelinq\Service\Social\ThreadsAdapter
 * @uses   \OCA\Pipelinq\Service\Social\XAdapter
 */
class SocialAdapterRegistryGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/SocialAdapterRegistryGolden.json';

	/**
	 * Build the registry over a gateway that answers readiness per provider.
	 *
	 * @return SocialAdapterRegistry
	 */
	private function registry(): SocialAdapterRegistry {
		$gateway = $this->createMock(originalClassName: SocialBrokerGateway::class);
		$gateway->method('readiness')->willReturnCallback(
			static fn (string $brokerProvider): array => ['state' => 'state-of-' . $brokerProvider, 'reason' => 'reason-of-' . $brokerProvider]
		);

		return new SocialAdapterRegistry(
			gateway: $gateway,
			independent: new IndependentNetworkAdapters(
				mastodon: new MastodonAdapter(gateway: $gateway),
				bluesky: new BlueskyAdapter(gateway: $gateway),
				linkedin: new LinkedInAdapter(gateway: $gateway),
				xNetwork: new XAdapter(gateway: $gateway),
			),
			meta: new MetaNetworkAdapters(
				facebook: new FacebookPageAdapter(gateway: $gateway),
				instagram: new InstagramBusinessAdapter(gateway: $gateway),
				threads: new ThreadsAdapter(gateway: $gateway),
			),
		);
	}//end registry()

	/**
	 * The registry answers as recorded.
	 *
	 * @return void
	 */
	public function testTheRegistryMatchesTheGolden(): void {
		$registry = $this->registry();
		$actual   = ['missing' => $registry->missingNetworks(), 'readiness' => $registry->readiness(), 'networks' => []];
		foreach ([...SocialAdapterRegistry::NETWORKS, 'myspace', ''] as $network) {
			$adapter = $registry->forNetwork(network: $network);
			$class   = null;
			if ($adapter !== null) {
				$class = get_class($adapter);
			}

			$actual['networks'][$network . '|'] = [
				'adapter'   => $class,
				'provider'  => $registry->providerFor(network: $network),
				'readiness' => $registry->readinessFor(network: $network),
			];
		}

		if (getenv('SOCIAL_REGISTRY_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $golden, actual: json_decode((string) json_encode($actual), true));
	}//end testTheRegistryMatchesTheGolden()
}//end class
