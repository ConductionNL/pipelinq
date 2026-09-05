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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One outbound read, through one OpenConnector source.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
 */
class ConnectorEgress {

	/**
	 * OpenConnector's own register slug. Frozen even where the app id moved.
	 *
	 * @var string
	 */
	public const SOURCE_REGISTER = 'openconnector';

	/**
	 * The `Source` schema within that register.
	 *
	 * @var string
	 */
	public const SOURCE_SCHEMA = 'source';

	/**
	 * OpenConnector's call service, resolved by name at run time.
	 *
	 * @var string
	 */
	private const CALL_SERVICE = 'OCA\\OpenConnector\\Service\\CallService';

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
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
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
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
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
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function read(string $configKey, string $endpoint, array $config = [], string $method = 'GET'): EgressResult {
		$sourceId = $this->sourceId(configKey: $configKey);
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

		return $this->readThrough(source: $source, sourceId: $sourceId, endpoint: $endpoint, config: $config, method: $method);
	}//end read()

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
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source
	 */
	public function readUrl(string $configKey, string $url, array $config = [], string $sourceId = ''): EgressResult {
		$resolvedId = trim($sourceId);
		if ($resolvedId === '') {
			$resolvedId = $this->sourceId(configKey: $configKey);
		}

		if ($resolvedId === '') {
			return EgressResult::failed(
				failure: EgressResult::NOT_CONFIGURED,
				reason: 'No OpenConnector source is configured under ' . $configKey . '.'
			);
		}

		$source = $this->resolveSource(sourceId: $resolvedId);
		if ($source === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The OpenConnector source ' . $resolvedId . ' could not be resolved.'
			);
		}

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

		return $this->readThrough(source: $source, sourceId: $resolvedId, endpoint: $endpoint, config: $config, method: 'GET');
	}//end readUrl()

	/**
	 * Perform the call once the source is resolved.
	 *
	 * @param object|array<string, mixed> $source The resolved OpenConnector source.
	 * @param string $sourceId The source id, for the log.
	 * @param string $endpoint The endpoint, relative to the source location.
	 * @param array<string, mixed> $config Extra call configuration.
	 * @param string $method The HTTP method.
	 *
	 * @return EgressResult
	 */
	private function readThrough(object|array $source, string $sourceId, string $endpoint, array $config, string $method): EgressResult {
		$callService = $this->resolveCallService();
		if ($callService === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'OpenConnector is not available on this instance.'
			);
		}

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $method,
				config: $config,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorEgress.read: the call failed',
				['sourceId' => $sourceId, 'endpoint' => $endpoint, 'exception' => $e->getMessage()]
			);
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The call through OpenConnector failed.'
			);
		}

		return $this->fromCallLog(callLog: $callLog, endpoint: $endpoint);
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
		return trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
	}//end sourceId()

	/**
	 * Resolve the OpenConnector `Source` object.
	 *
	 * @param string $sourceId The source UUID or slug.
	 *
	 * @return object|array<string, mixed>|null The source, or null.
	 */
	private function resolveSource(string $sourceId): object|array|null {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$source = $objectService->find(
				id: $sourceId,
				register: self::SOURCE_REGISTER,
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
		try {
			$callService = $this->container->get(self::CALL_SERVICE);
		} catch (Throwable $e) {
			$this->logger->info(
				'ConnectorEgress.resolveCallService: unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			return null;
		}

		return $callService;
	}//end resolveCallService()

	/**
	 * Normalise an entity or array to a plain array.
	 *
	 * @param mixed $value The entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
