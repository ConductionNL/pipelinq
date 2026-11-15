<?php

/**
 * Pipelinq SocialAdapterRegistry.
 *
 * Which adapter answers for which network, and how ready each one is.
 *
 * The registry is the reason no caller writes `if ($network === 'x')`. A
 * publishing job asks for the adapter and gets one; a network the schema names
 * but nobody implemented would be a missing adapter here, which is why
 * {@see missingNetworks()} exists and is asserted rather than left to be found
 * by a post that quietly went nowhere.
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
 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * The network-to-adapter map, and the readiness of each network.
 *
 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */
class SocialAdapterRegistry {
	/**
	 * Every network the `socialAccount.network` enum declares, in the order it
	 * declares them. Restated here so a schema value with no adapter is a
	 * failing test rather than a post that went nowhere.
	 *
	 * @var array<int, string>
	 */
	public const NETWORKS = ['mastodon', 'bluesky', 'linkedin', 'x', 'facebook', 'instagram', 'threads'];

	/**
	 * The adapters, keyed by network.
	 *
	 * @var array<string, SocialNetworkAdapter>
	 */
	private array $adapters = [];

	/**
	 * Constructor.
	 *
	 * Every adapter is injected rather than constructed here, so a test can
	 * hand in a fake gateway and the container owns the wiring.
	 *
	 * @param SocialBrokerGateway        $gateway     The brokered egress seam.
	 * @param IndependentNetworkAdapters $independent Mastodon, Bluesky, LinkedIn and X.
	 * @param MetaNetworkAdapters        $meta        Facebook, Instagram and Threads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SocialBrokerGateway $gateway,
		IndependentNetworkAdapters $independent,
		MetaNetworkAdapters $meta,
	) {
		foreach ([...$independent->all(), ...$meta->all()] as $adapter) {
			$this->adapters[$adapter->network()] = $adapter;
		}
	}//end __construct()

	/**
	 * The adapter for one network, or null when the network is unknown.
	 *
	 * @param string $network The network name.
	 *
	 * @return SocialNetworkAdapter|null The adapter, or null.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function forNetwork(string $network): ?SocialNetworkAdapter {
		return ($this->adapters[$network] ?? null);
	}//end forNetwork()

	/**
	 * Every network the enum names that has no adapter. Empty is the only
	 * acceptable answer, and a test says so.
	 *
	 * @return array<int, string> The unimplemented networks.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function missingNetworks(): array {
		return array_values(array_diff(self::NETWORKS, array_keys($this->adapters)));
	}//end missingNetworks()

	/**
	 * How ready every network is, keyed by network, for the accounts page.
	 *
	 * @return array<string, array{state: string, reason: string}> The readiness per network.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function readiness(): array {
		$out = [];
		foreach (self::NETWORKS as $network) {
			$out[$network] = $this->readinessFor(network: $network);
		}

		return $out;
	}//end readiness()

	/**
	 * How ready one network is, and why.
	 *
	 * @param string $network The network name.
	 *
	 * @return array{state: string, reason: string} The readiness and its reason.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
	 */
	public function readinessFor(string $network): array {
		$adapter = $this->forNetwork(network: $network);
		if ($adapter === null) {
			return [
				'state' => SocialBrokerGateway::NOT_CONFIGURED,
				'reason' => 'Pipelinq does not know this network.',
			];
		}

		return $this->gateway->readiness(brokerProvider: $adapter->brokerProvider());
	}//end readinessFor()

	/**
	 * The broker provider a network connects through, for the connect flow.
	 *
	 * @param string $network The network name.
	 *
	 * @return string The provider identifier, or an empty string.
	 *
	 * @spec openspec/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
	 */
	public function providerFor(string $network): string {
		return ($this->forNetwork(network: $network)?->brokerProvider() ?? '');
	}//end providerFor()
}//end class
