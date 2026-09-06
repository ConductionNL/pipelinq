<?php

/**
 * Pipelinq DeliverabilityCheckService.
 *
 * Checks a sender domain's SPF, DKIM and DMARC DNS records and caches the
 * verdict onto the `mailTransport` row, so the deliverability panel does not
 * re-query DNS on every page load. No fleet precedent caches
 * `dns_get_record`; the overridable-wrapper shape (`dnsGetRecord()`,
 * `protected` so tests can stub it) mirrors hermiq's
 * `WebResearchEgressGuard::dnsGetRecord()`. A DNS failure — timeout, `false`
 * return, a thrown warning — degrades to `dmarcStatus = 'unknown'`, never
 * thrown; the panel itself always renders.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DeliverabilityCheckService: SPF/DKIM/DMARC lookup, cached per sender domain.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */
class DeliverabilityCheckService {
	/**
	 * Default register slug used when no `register` app config value is set.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default MailTransport schema slug used when no `mailTransport_schema`
	 * app config value is set.
	 */
	private const DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG = 'mailTransport';

	/**
	 * How long a cached verdict stays fresh before a reopened panel
	 * re-queries DNS.
	 */
	private const CACHE_TTL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load one `mailTransport` by id and return its (possibly refreshed)
	 * deliverability verdict. Controller-facing entry point so
	 * `MailTransportController` never touches `ObjectService` directly.
	 *
	 * @param string $id MailTransport UUID or slug.
	 * @param bool $forceRefresh Re-query DNS even when the cache is fresh.
	 *
	 * @return array{dkimVerified: bool, dmarcStatus: string, checkedAt: string}|null
	 *               The verdict, or null when the transport does not exist.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forceRefresh is the documented
	 *  cache-bypass request, not a behaviour switch.
	 */
	public function checkTransportById(string $id, bool $forceRefresh = false): ?array {
		$transport = $this->loadOne(id: $id);
		if ($transport === null) {
			return null;
		}

		return $this->checkTransport(transport: $transport, forceRefresh: $forceRefresh);
	}//end checkTransportById()

	/**
	 * Load one `mailTransport` by id.
	 *
	 * @param string $id MailTransport UUID or slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadOne(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.loadOne: not found', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		if (is_array($found) === true) {
			return $found;
		}

		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			return (array)$found->jsonSerialize();
		}

		return null;
	}//end loadOne()

	/**
	 * Return the cached verdict for a transport's `senderDomain`, refreshing
	 * it first when the cache is stale (older than {@see CACHE_TTL_SECONDS})
	 * or `$forceRefresh` is true.
	 *
	 * @param array<string, mixed> $transport The `mailTransport` row.
	 * @param bool $forceRefresh Re-query DNS even when the cache is fresh.
	 *
	 * @return array{dkimVerified: bool, dmarcStatus: string, checkedAt: string} The verdict.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forceRefresh is the documented
	 *  cache-bypass request, not a behaviour switch.
	 */
	public function checkTransport(array $transport, bool $forceRefresh = false): array {
		$domain = (string)($transport['senderDomain'] ?? '');
		if ($domain === '') {
			return ['dkimVerified' => false, 'dmarcStatus' => 'unknown', 'checkedAt' => ''];
		}

		if ($forceRefresh === false && $this->cacheIsFresh(transport: $transport) === true) {
			return [
				'dkimVerified' => (bool)($transport['dkimVerified'] ?? false),
				'dmarcStatus' => (string)($transport['dmarcStatus'] ?? 'unknown'),
				'checkedAt' => (string)($transport['deliverabilityCheckedAt'] ?? ''),
			];
		}

		$verdict = $this->lookupDomain(domain: $domain);
		$this->persistVerdict(transport: $transport, verdict: $verdict);
		return $verdict;
	}//end checkTransport()

	/**
	 * Whether the transport's cached verdict is still within
	 * {@see CACHE_TTL_SECONDS}.
	 *
	 * @param array<string, mixed> $transport The transport row.
	 *
	 * @return bool
	 */
	private function cacheIsFresh(array $transport): bool {
		$checkedAt = (string)($transport['deliverabilityCheckedAt'] ?? '');
		if ($checkedAt === '') {
			return false;
		}

		$checkedAtTimestamp = strtotime($checkedAt);
		if ($checkedAtTimestamp === false) {
			return false;
		}

		return ((time() - $checkedAtTimestamp) < self::CACHE_TTL_SECONDS);
	}//end cacheIsFresh()

	/**
	 * Look up SPF (informational — folded into the DMARC verdict text),
	 * DKIM and DMARC for a domain. Never throws: any DNS failure yields
	 * `dmarcStatus = 'unknown'`.
	 *
	 * @param string $domain The sender domain.
	 *
	 * @return array{dkimVerified: bool, dmarcStatus: string, checkedAt: string}
	 */
	private function lookupDomain(string $domain): array {
		$dmarcStatus = 'unknown';
		try {
			$dmarcRecords = $this->dnsGetRecord(hostname: ('_dmarc.' . $domain), type: DNS_TXT);
			$dmarcStatus = $this->classifyDmarc(records: $dmarcRecords);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.lookupDomain: DMARC lookup failed', ['domain' => $domain, 'exception' => $e->getMessage()]);
		}

		$dkimVerified = false;
		try {
			// DKIM has no fixed selector; the default selector convention
			// ('default._domainkey') covers the common case without
			// requiring per-tenant selector configuration in phase 1.
			$dkimRecords = $this->dnsGetRecord(hostname: ('default._domainkey.' . $domain), type: DNS_TXT);
			$dkimVerified = ($dkimRecords !== false && $dkimRecords !== []);
		} catch (Throwable $e) {
			$this->logger->info('DeliverabilityCheckService.lookupDomain: DKIM lookup failed', ['domain' => $domain, 'exception' => $e->getMessage()]);
		}

		return ['dkimVerified' => $dkimVerified, 'dmarcStatus' => $dmarcStatus, 'checkedAt' => $this->nowIso()];
	}//end lookupDomain()

	/**
	 * Classify a `_dmarc` TXT lookup result into `found`/`invalid`/`missing`.
	 *
	 * @param array<int, array<string, mixed>>|false $records `dns_get_record()`'s result.
	 *
	 * @return string One of `found`, `invalid`, `missing`.
	 */
	private function classifyDmarc(array|false $records): string {
		if ($records === false || $records === []) {
			return 'missing';
		}

		foreach ($records as $record) {
			$txt = (string)($record['txt'] ?? '');
			if (str_starts_with($txt, 'v=DMARC1') === true) {
				return 'found';
			}
		}

		return 'invalid';
	}//end classifyDmarc()

	/**
	 * Thin, separately-overridable wrapper around the built-in
	 * `dns_get_record()`, mirroring hermiq's `WebResearchEgressGuard`
	 * pattern so tests can stub DNS without a real lookup.
	 *
	 * @param string $hostname The hostname to query.
	 * @param int $type One of the `DNS_*` type constants.
	 *
	 * @return array<int, array<string, mixed>>|false
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
	 */
	protected function dnsGetRecord(string $hostname, int $type): array|false {
		return dns_get_record($hostname, $type);
	}//end dnsGetRecord()

	/**
	 * Persist a verdict onto the transport row.
	 *
	 * @param array<string, mixed> $transport The transport row.
	 * @param array{dkimVerified: bool, dmarcStatus: string, checkedAt: string} $verdict The verdict.
	 *
	 * @return void
	 */
	private function persistVerdict(array $transport, array $verdict): void {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			return;
		}

		$payload = $transport;
		$payload['dkimVerified'] = $verdict['dkimVerified'];
		$payload['dmarcStatus'] = $verdict['dmarcStatus'];
		$payload['deliverabilityCheckedAt'] = $verdict['checkedAt'];

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('DeliverabilityCheckService.persistVerdict: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}//end persistVerdict()

	/**
	 * Resolve OpenRegister's `ObjectService` from the DI container.
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('DeliverabilityCheckService.getObjectService: unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()

	/**
	 * The configured `pipelinq` register slug.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * The configured `mailTransport` schema slug.
	 *
	 * @return string
	 */
	private function getMailTransportSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailTransport_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG;
	}//end getMailTransportSchemaSlug()

	/**
	 * Extract a UUID/id/slug from an OpenRegister payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string The id, or '' when none is present.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($self[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Current UTC instant, ISO 8601.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
