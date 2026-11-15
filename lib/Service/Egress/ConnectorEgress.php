<?php

/**
 * Pipelinq ConnectorEgress.
 *
 * The single outbound seam of marketing-search-intelligence. This phase makes
 * four kinds of outward read (crawl our own pages, Matomo's Reporting API, a
 * competitor's feed or sitemap or page, a public fediverse timeline) and each
 * of them would otherwise grow its own HTTP client. Rule 3 of the marketing
 * architecture and ADR-067 say otherwise: every call to a network leaves
 * through an OpenConnector source, and Pipelinq writes adapters that shape
 * requests rather than clients that make them.
 *
 * So this class resolves the OpenConnector `Source` object named by an
 * app-config key and hands the request to `CallService::call()`, which is what
 * `ConnectorSourceTransport` already does for bulk mail. Nothing here
 * constructs an `IClientService`, and nothing here reads a credential: the
 * source owns that.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Egress
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
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ConnectorEgressInterface;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Support\FleetAppId;
use OCA\Pipelinq\Util\SerialisedToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One outbound read, through one OpenConnector source.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The named constructors of the
 *  immutable result type this class returns (EgressResult). A value
 *  object's own factory is not the hidden dependency StaticAccess exists
 *  to catch: there is nothing here that could be injected, and the
 *  alternative is a constructor call whose argument order says less than
 *  the method name does.
 */
class ConnectorEgress implements ConnectorEgressInterface {
	use SerialisedToArray;

	/**
	 * The canonical slug of the register Integriq's sources live in.
	 *
	 * This used to read `openconnector` and to carry the comment "frozen even
	 * where the app id moved". It was not frozen: Integriq's repair step renames
	 * the register row per instance, so the slug to READ with is whatever
	 * {@see ConnectorSourceRegister} finds. This constant is only the name asked
	 * about, and it is here so the value has one home.
	 *
	 * @var string
	 */
	public const SOURCE_REGISTER_CANONICAL = ConnectorSourceRegister::CANONICAL_REGISTER;

	/**
	 * The `Source` schema within that register.
	 *
	 * @var string
	 */
	public const SOURCE_SCHEMA = 'source';

	/**
	 * OpenRegister's object service, resolved by name at run time.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the two lazy resolutions.
	 * @param IAppConfig $appConfig Pipelinq app config, holding the source ids.
	 * @param ConnectorSourceRegister $connectorRegister Which slug the source register answers to here.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ConnectorSourceRegister $connectorRegister,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a source is configured for this capability.
	 *
	 * @param string $configKey The app-config key holding the source id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function isConfigured(string $configKey): bool {
		return ($this->sourceId(configKey: $configKey) !== '');
	}//end isConfigured()

	/**
	 * Read one URL through the source named by an app-config key.
	 *
	 * @param string $configKey The app-config key holding the source id.
	 * @param string $endpoint The endpoint or absolute URL to read.
	 * @param array<string, mixed> $config Extra call configuration, such as `query` or `headers`.
	 * @param string $method The HTTP method; a read in this change is always GET.
	 *
	 * @return EgressResult The body, or the reason there is none.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function read(string $configKey, string $endpoint, array $config = [], string $method = 'GET'): EgressResult {
		return $this->readConfigured(configKey: $configKey, call: ['endpoint' => $endpoint, 'config' => $config, 'method' => $method]);
	}//end read()

	/**
	 * Read one endpoint through the source the key names, or say why there is no source.
	 *
	 * @param string                                                         $configKey The app-config key holding the source id.
	 * @param array{endpoint: string, config: array<string, mixed>, method: string} $call      The endpoint, the extra call
	 *                                                                                         configuration and the method.
	 *
	 * @return EgressResult The body, or the reason there is none.
	 */
	private function readConfigured(string $configKey, array $call): EgressResult {
		$sourceId = $this->sourceId(configKey: $configKey);
		$source = $this->sourceOrFailure(configKey: $configKey, sourceId: $sourceId);
		if ($source instanceof EgressResult) {
			return $source;
		}

		return $this->readThrough(
			read: new ConnectorRead(
				source: $source,
				sourceId: $sourceId,
				endpoint: $call['endpoint'],
				config: $call['config'],
				method: $call['method']
			)
		);
	}//end readConfigured()

	/**
	 * The resolved source, or why there is none: no id configured, or an id that does not resolve.
	 *
	 * @param string $configKey The app-config key naming the source, for the message.
	 * @param string $sourceId  The source id, or ''.
	 *
	 * @return object|array<string, mixed>|EgressResult The source, or the failure.
	 */
	private function sourceOrFailure(string $configKey, string $sourceId): object|array {
		if ($sourceId === '') {
			return EgressResult::failed(
				failure: EgressResult::NOT_CONFIGURED,
				reason: 'No OpenConnector source is configured under ' . $configKey . '.'
			);
		}

		$source = $this->resolveSource(sourceId: $sourceId);
		if ($source === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The OpenConnector source ' . $sourceId . ' could not be resolved.'
			);
		}

		return $source;
	}//end sourceOrFailure()

	/**
	 * Read one absolute URL through a source, resolving the URL against the
	 * source's own location.
	 *
	 * A source is a per-origin allow-list, and that is the property worth
	 * keeping: `CallService` builds its request URL as location plus endpoint,
	 * so a source whose location is `https://example.org` can only ever reach
	 * that origin. This method preserves it by REFUSING a target outside the
	 * location rather than by quietly concatenating a second scheme onto the
	 * first, which is what passing an absolute URL as an endpoint would do.
	 * A source with an empty location is the deliberate gateway shape: the
	 * whole target becomes the endpoint.
	 *
	 * @param string $configKey The app-config key holding the fallback source id.
	 * @param string $url The absolute URL to read.
	 * @param array<string, mixed> $config Extra call configuration.
	 * @param string $sourceId An explicit source id, overriding the configured one.
	 *
	 * @return EgressResult The body, or the reason there is none.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function readUrl(string $configKey, string $url, array $config = [], string $sourceId = ''): EgressResult {
		$resolvedId = $this->resolvedSourceId(configKey: $configKey, sourceId: $sourceId);
		$source = $this->sourceOrFailure(configKey: $configKey, sourceId: $resolvedId);
		if ($source instanceof EgressResult) {
			return $source;
		}

		return $this->readWithin(source: $source, request: ['sourceId' => $resolvedId, 'url' => $url, 'config' => $config]);
	}//end readUrl()

	/**
	 * Read an absolute URL through a resolved source, refusing a URL outside its location.
	 *
	 * @param object|array<string, mixed>                                      $source  The resolved source.
	 * @param array{sourceId: string, url: string, config: array<string, mixed>} $request The source id, the URL and the
	 *                                                                                   extra call configuration.
	 *
	 * @return EgressResult The body, or the reason there is none.
	 */
	private function readWithin(object|array $source, array $request): EgressResult {
		$endpoint = $this->endpointWithinSource(source: $source, url: $request['url']);
		if ($endpoint instanceof EgressResult) {
			return $endpoint;
		}

		return $this->readThrough(
			read: new ConnectorRead(
				source: $source,
				sourceId: $request['sourceId'],
				endpoint: $endpoint,
				config: $request['config'],
				method: 'GET'
			)
		);
	}//end readWithin()

	/**
	 * The explicit source id, else the one configured under the key.
	 *
	 * @param string $configKey The app-config key naming the source.
	 * @param string $sourceId The explicit source id, or ''.
	 *
	 * @return string The source id ('' when none is configured).
	 */
	private function resolvedSourceId(string $configKey, string $sourceId): string {
		$resolvedId = trim($sourceId);
		if ($resolvedId === '') {
			$resolvedId = $this->sourceId(configKey: $configKey);
		}

		return $resolvedId;
	}//end resolvedSourceId()

	/**
	 * The URL relative to the source location, or a refusal when it lies outside the source.
	 *
	 * @param mixed $source The OpenConnector source.
	 * @param string $url The absolute URL to read.
	 *
	 * @return string|EgressResult The endpoint, or the refusal.
	 */
	private function endpointWithinSource($source, string $url): string|EgressResult {
		$location = rtrim((string)($this->toArray(value: $source)['location'] ?? ''), '/');
		$endpoint = trim($url);
		if ($location !== '') {
			if (str_starts_with($endpoint, ($location . '/')) === false && $endpoint !== $location) {
				return EgressResult::failed(
					failure: EgressResult::REFUSED,
					reason: 'The source only reaches ' . $location . ', so ' . $url . ' was not requested.'
				);
			}

			$endpoint = substr($endpoint, strlen($location));
		}

		return $endpoint;
	}//end endpointWithinSource()

	/**
	 * Perform the call once the source is resolved.
	 *
	 * @param ConnectorRead $read The source, endpoint, method and configuration.
	 *
	 * @return EgressResult
	 */
	private function readThrough(ConnectorRead $read): EgressResult {
		$callService = $this->resolveCallService();
		if ($callService === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'OpenConnector is not available on this instance.'
			);
		}

		try {
			$callLog = $callService->call(
				source: $read->source,
				endpoint: $read->endpoint,
				method: $read->method,
				config: $read->config,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorEgress.read: the call failed',
				['sourceId' => $read->sourceId, 'endpoint' => $read->endpoint, 'exception' => $e->getMessage()]
			);
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The call through OpenConnector failed.'
			);
		}

		return $this->fromCallLog(callLog: $callLog, endpoint: $read->endpoint);
	}//end readThrough()

	/**
	 * Turn OpenConnector's call log into a result.
	 *
	 * @param mixed $callLog The call log entity or array.
	 * @param string $endpoint What was read, for the message.
	 *
	 * @return EgressResult
	 */
	private function fromCallLog(mixed $callLog, string $endpoint): EgressResult {
		$data = $this->toArray(value: $callLog);
		$status = (int)($data['statusCode'] ?? 0);
		$response = ($data['response'] ?? null);
		if (is_array($response) === true) {
			$status = (int)($response['statusCode'] ?? $status);
		}

		if ($status < 200 || $status >= 300) {
			return EgressResult::failed(
				failure: EgressResult::REFUSED,
				reason: 'Reading ' . $endpoint . ' answered with status ' . $status . '.',
				status: $status
			);
		}

		$body = '';
		if (is_array($response) === true && is_string($response['body'] ?? null) === true) {
			$body = (string)$response['body'];
		}

		return EgressResult::success(body: $body, status: $status);
	}//end fromCallLog()

	/**
	 * The configured source id, trimmed.
	 *
	 * @param string $configKey The app-config key.
	 *
	 * @return string The id, or an empty string.
	 */
	private function sourceId(string $configKey): string {
		return trim($this->appConfig->getValueString(AppIdentity::APP_ID, $configKey, ''));
	}//end sourceId()

	/**
	 * Resolve the OpenConnector `Source` object.
	 *
	 * @param string $sourceId The source UUID or slug.
	 *
	 * @return object|array<string, mixed>|null The source, or null.
	 */
	private function resolveSource(string $sourceId): object|array|null {
		// Branch on the resolution, never fall back to the canonical slug. A read
		// against a slug this instance does not carry returns zero rows, which is
		// what a correct read of an empty register returns, so the caller would
		// record "the source is not configured" for a register that is simply not
		// there. The null here is announced by ConnectorSourceRegister itself.
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'ConnectorEgress.resolveSource',
			sourceId: $sourceId
		);
		if ($registerSlug === null) {
			return null;
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$source = $objectService->find(
				id: $sourceId,
				register: $registerSlug,
				schema: self::SOURCE_SCHEMA,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorEgress.resolveSource: lookup failed',
				['sourceId' => $sourceId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($source) === false && is_array($source) === false) {
			return null;
		}

		return $source;
	}//end resolveSource()

	/**
	 * Resolve OpenConnector's call service.
	 *
	 * @return object|null The service, or null when OpenConnector is absent.
	 */
	private function resolveCallService(): ?object {
		// Asked for by CANONICAL app name, never by a literal FQCN. The
		// connector app renamed its PSR-4 root from `OCA\OpenConnector` to
		// `OCA\Integriq` with no compatibility alias, and both roots are in
		// the field. A container lookup on the wrong one throws into a catch
		// whose whole purpose is "the optional app is absent" — so a migrated
		// instance read as an uninstalled one and every outbound read through
		// this class stopped, logging `unavailable` and nothing else.
		$callService = FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: 'Service\CallService'
		);
		if ($callService === null) {
			$this->logger->info('ConnectorEgress.resolveCallService: unavailable');
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			return null;
		}

		return $callService;
	}//end resolveCallService()
}//end class
