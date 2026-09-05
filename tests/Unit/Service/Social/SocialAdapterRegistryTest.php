<?php

/**
 * Unit tests for SocialAdapterRegistry.
 *
 * The first test is the one that matters: every network the `socialAccount`
 * schema names must have an adapter. A schema value with no adapter is a post
 * that goes nowhere and reports nothing, and it would be found by a marketer
 * rather than by a test.
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
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\Pipelinq\Service\Social\BlueskyAdapter;
use OCA\Pipelinq\Service\Social\FacebookPageAdapter;
use OCA\Pipelinq\Service\Social\InstagramBusinessAdapter;
use OCA\Pipelinq\Service\Social\LinkedInAdapter;
use OCA\Pipelinq\Service\Social\MastodonAdapter;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\ThreadsAdapter;
use OCA\Pipelinq\Service\Social\XAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The network-to-adapter map and the per-network readiness.
 *
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */
class SocialAdapterRegistryTest extends TestCase {
	/**
	 * A registry over a gateway whose catalogue answers like OpenRegister's
	 * real one: the five social providers are filed and Bluesky is a preview.
	 *
	 * @return SocialAdapterRegistry The registry.
	 */
	private function registry(): SocialAdapterRegistry {
		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturnCallback(
			static function (string $providerId): ?array {
				$filed = ['mastodon', 'linkedin', 'x', 'meta-graph'];
				if ($providerId === 'bluesky') {
					return ['identifier' => 'bluesky', 'preview' => true];
				}

				if (in_array($providerId, $filed, true) === true) {
					return ['identifier' => $providerId];
				}

				return null;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($catalogue): object {
				if ($id === SocialBrokerGateway::CATALOGUE_CLASS) {
					return $catalogue;
				}

				throw new RuntimeException(message: 'not bound: ' . $id);
			}
		);

		$gateway = new SocialBrokerGateway($container, $this->createMock(LoggerInterface::class));

		return new SocialAdapterRegistry(
			$gateway,
			new MastodonAdapter($gateway),
			new BlueskyAdapter($gateway),
			new LinkedInAdapter($gateway),
			new XAdapter($gateway),
			new FacebookPageAdapter($gateway),
			new InstagramBusinessAdapter($gateway),
			new ThreadsAdapter($gateway),
		);
	}

	/**
	 * Every network the schema enum names has an adapter.
	 *
	 * @return void
	 */
	public function testEveryNetworkTheSchemaNamesHasAnAdapter(): void {
		$registry = $this->registry();

		$this->assertSame([], $registry->missingNetworks());
		foreach (SocialAdapterRegistry::NETWORKS as $network) {
			$this->assertNotNull($registry->forNetwork(network: $network), $network . ' has no adapter');
			$this->assertSame($network, $registry->forNetwork(network: $network)->network());
		}
	}

	/**
	 * A network nobody implemented answers null rather than throwing.
	 *
	 * @return void
	 */
	public function testAnUnknownNetworkAnswersNull(): void {
		$this->assertNull($this->registry()->forNetwork(network: 'friendica'));
	}

	/**
	 * A network with no developer application filed reports `not_configured`
	 * with a reason, rather than looking connectable.
	 *
	 * @return void
	 */
	public function testAnUnfiledNetworkReportsNotConfiguredWithAReason(): void {
		$readiness = $this->registry()->readinessFor(network: 'threads');

		$this->assertSame(SocialBrokerGateway::NOT_CONFIGURED, $readiness['state']);
		$this->assertNotSame('', $readiness['reason']);
	}

	/**
	 * Bluesky reports `preview`, because the broker ships it flagged that way
	 * until DPoP lands. It is NOT `not_configured`: nothing is missing on the
	 * Pipelinq side.
	 *
	 * @return void
	 */
	public function testBlueskyReportsPreviewRatherThanNotConfigured(): void {
		$readiness = $this->registry()->readinessFor(network: 'bluesky');

		$this->assertSame(SocialBrokerGateway::PREVIEW, $readiness['state']);
	}

	/**
	 * Mastodon is ready today: it needs nobody's approval, because an
	 * application is registered at the account's own server at connect time.
	 *
	 * @return void
	 */
	public function testMastodonIsReadyToday(): void {
		$this->assertSame(SocialBrokerGateway::READY, $this->registry()->readinessFor(network: 'mastodon')['state']);
	}

	/**
	 * Facebook and Instagram share the Meta Graph provider.
	 *
	 * @return void
	 */
	public function testFacebookAndInstagramShareTheMetaProvider(): void {
		$registry = $this->registry();

		$this->assertSame('meta-graph', $registry->providerFor(network: 'facebook'));
		$this->assertSame('meta-graph', $registry->providerFor(network: 'instagram'));
		$this->assertSame('', $registry->providerFor(network: 'threads'));
	}

	/**
	 * The readiness map covers every network, so the accounts page can render
	 * a reason for each without asking again.
	 *
	 * @return void
	 */
	public function testTheReadinessMapCoversEveryNetwork(): void {
		$readiness = $this->registry()->readiness();

		$this->assertSame(SocialAdapterRegistry::NETWORKS, array_keys($readiness));
	}
}//end class
