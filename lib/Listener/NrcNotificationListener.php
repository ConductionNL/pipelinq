<?php

/**
 * Pipelinq NrcNotificationListener.
 *
 * Per-kanaal dispatcher for inbound NRC notifications POSTed to
 * `POST /api/zgw/notificaties/inbox`. The controller authenticates the
 * Bearer header against the matching `NrcAbonnement.callbackAuth`, then
 * hands the parsed payload (kanaal, resource, actie, resourceUrl, hoofdObject,
 * abonnement) to `dispatch()`. The listener:
 *
 *   1. Updates `NrcAbonnement.laatstOntvangenOp` to now.
 *   2. Routes to the per-kanaal handler:
 *      - "zaken" + resource="zaak"   + actie="create" → record/no-op zaak mapping.
 *      - "zaken" + resource="status" + actie="create" → GET status, resolve
 *        statustype omschrijving from ZTC, update Request.status, log
 *        elapsed ms (5s budget per REQ-ZGW-007).
 *      - "besluiten" + resource="besluit" + actie="create" → no-op (mapping
 *        is already persisted by createBesluit on the outbound path).
 *      - "catalogi"  any                                 → invalidate ZTC cache.
 *   3. Logs but never re-throws — NRC will retry the callback otherwise.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\Contracts\NrcNotificationListenerInterface;
use OCA\Pipelinq\Kernel\Contracts\ZgwRegisterAccessInterface;
use OCA\Pipelinq\Kernel\Contracts\ZrcClientInterface;
use OCA\Pipelinq\Kernel\Contracts\ZtcClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * NRC notification dispatcher.
 *
 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
 */
class NrcNotificationListener implements NrcNotificationListenerInterface {
	/**
	 * Notifications MUST complete within this many milliseconds (REQ-ZGW-007).
	 */
	public const TARGET_DISPATCH_MS = 5000;

	/**
	 * Constructor.
	 *
	 * @param ZgwRegisterAccessInterface $registers Register facade.
	 * @param ZrcClientInterface $zrc ZRC client (status fetches).
	 * @param ZtcClientInterface $ztc ZTC client (omschrijving resolution + cache invalidation).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ZgwRegisterAccessInterface $registers,
		private ZrcClientInterface $zrc,
		private ZtcClientInterface $ztc,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Dispatch a single inbound notification.
	 *
	 * @param array<string, mixed> $abonnement Matching NrcAbonnement record.
	 * @param array<string, mixed> $notification Parsed inbound JSON payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
	 */
	public function dispatch(array $abonnement, array $notification): void {
		$start = microtime(true);

		$endpoint = $this->endpointFor(abonnement: $abonnement);
		if ($endpoint === null) {
			return;
		}

		$this->markReceived(abonnement: $abonnement);
		$this->runHandler(endpoint: $endpoint, notification: $notification);
		$this->warnWhenOverBudget(start: $start, notification: $notification);
	}//end dispatch()

	/**
	 * The endpoint a subscription belongs to; an unknown one is logged.
	 *
	 * @param array<string, mixed> $abonnement NrcAbonnement record.
	 *
	 * @return array<string, mixed>|null The endpoint, or null when unknown.
	 */
	private function endpointFor(array $abonnement): ?array {
		$endpointId = (string)($abonnement['endpointId'] ?? '');
		$endpoint = null;
		if ($endpointId !== '') {
			$endpoint = $this->registers->find(ZgwRegisterAccessInterface::SCHEMA_ENDPOINT, $endpointId);
		}

		if ($endpoint === null) {
			$this->logger->warning(
				'ZGW NRC: notification received for unknown endpoint',
				['endpoint' => $endpointId]
			);
		}

		return $endpoint;
	}//end endpointFor()

	/**
	 * Run the handler of the notification's route; a failing handler is
	 * logged, never rethrown (REQ-ZGW-007 — no retries).
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 */
	private function runHandler(array $endpoint, array $notification): void {
		$route = self::routeOf(notification: $notification);
		$handler = $this->handlerFor(route: $route);
		try {
			if ($handler !== null) {
				$handler($endpoint, $notification);
			}
		} catch (Throwable $e) {
			$this->logger->error('ZGW NRC: dispatch handler threw', $route + ['err' => $e->getMessage()]);
		}
	}//end runHandler()

	/**
	 * The channel, resource and action of a notification.
	 *
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return array{channel: string, resource: string, action: string} The route.
	 */
	private static function routeOf(array $notification): array {
		return [
			'channel' => (string)($notification['channel'] ?? ''),
			'resource' => (string)($notification['resource'] ?? ''),
			'action' => (string)($notification['action'] ?? ''),
		];
	}//end routeOf()

	/**
	 * The handler of a route: every catalogi change, else the handler of the
	 * exact channel, resource and action.
	 *
	 * @param array{channel: string, resource: string, action: string} $route The route.
	 *
	 * @return (Closure(array<string, mixed>, array<string, mixed>): void)|null The handler, null when the route has none.
	 */
	private function handlerFor(array $route): ?Closure {
		if ($route['channel'] === 'catalogi') {
			return $this->onCatalogiChange(...);
		}

		$handlers = [
			'zaken' => [
				'zaak' => ['create' => $this->onCaseCreated(...)],
				'status' => ['create' => $this->onStatusCreated(...)],
			],
			'besluiten' => [
				'besluit' => ['create' => $this->onDecisionCreated(...)],
			],
		];

		return ($handlers[$route['channel']][$route['resource']][$route['action']] ?? null);
	}//end handlerFor()

	/**
	 * Log a dispatch that took longer than the budget.
	 *
	 * @param float                $start        The microtime the dispatch started.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 */
	private function warnWhenOverBudget(float $start, array $notification): void {
		$elapsedMs = (int)round((microtime(true) - $start) * 1000);
		if ($elapsedMs > self::TARGET_DISPATCH_MS) {
			$this->logger->warning(
				'ZGW NRC: dispatch exceeded 5s budget',
				self::routeOf(notification: $notification) + ['ms' => $elapsedMs]
			);
		}
	}//end warnWhenOverBudget()

	/**
	 * Update `laatstOntvangenOp` on the matching NrcAbonnement.
	 *
	 * @param array<string, mixed> $abonnement NrcAbonnement payload.
	 *
	 * @return void
	 */
	private function markReceived(array $abonnement): void {
		$uuid = (string)($abonnement['@self']['uuid'] ?? $abonnement['id'] ?? '');
		$abonnement['latestReceivedOn'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
		$saveUuid = null;
		if ($uuid !== '') {
			$saveUuid = $uuid;
		}

		$this->registers->save(
			ZgwRegisterAccessInterface::SCHEMA_ABONN,
			$abonnement,
			$saveUuid
		);
	}//end markReceived()

	/**
	 * Handle zaak.create — record mapping if it isn't already persisted.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $endpoint keeps the uniform
	 *  per-kanaal handler signature invoked from the dispatch() routing table.
	 */
	private function onCaseCreated(array $endpoint, array $notification): void {
		$caseUrl = (string)($notification['hoofdObject'] ?? $notification['resourceUrl'] ?? '');
		if ($caseUrl === '') {
			return;
		}

		$existing = $this->registers->findAll(
			ZgwRegisterAccessInterface::SCHEMA_MAPPING,
			['zgwUrl' => $caseUrl]
		);
		if ($existing !== []) {
			return;
		}

		$this->logger->info('ZGW NRC: observed externally-created zaak', ['zaak' => $caseUrl]);
	}//end onZaakCreated()

	/**
	 * Handle status.create — fetch status, resolve omschrijving, update Request.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 */
	private function onStatusCreated(array $endpoint, array $notification): void {
		$statusUrl = (string)($notification['resourceUrl'] ?? '');
		$caseUrl = (string)($notification['hoofdObject'] ?? '');
		if ($statusUrl === '' || $caseUrl === '') {
			return;
		}

		$mappings = $this->registers->findAll(
			ZgwRegisterAccessInterface::SCHEMA_MAPPING,
			['zgwUrl' => $caseUrl]
		);
		if ($mappings === []) {
			return;
		}

		$omsch = $this->statusDescription(endpoint: $endpoint, statusUrl: $statusUrl);
		$this->applyRequestStatus(requestId: (string)($mappings[0]['pipelinqId'] ?? ''), omsch: $omsch);
	}//end onStatusCreated()

	/**
	 * The omschrijving of a status's statustype, fetched from the ZRC and ZTC.
	 *
	 * @param array<string, mixed> $endpoint  Endpoint payload.
	 * @param string               $statusUrl The status URL.
	 *
	 * @return string|null The omschrijving, null when the status cannot be
	 *                     fetched (logged), has no statustype or none resolves.
	 */
	private function statusDescription(array $endpoint, string $statusUrl): ?string {
		try {
			$status = $this->zrc->getStatus($endpoint, $statusUrl);
		} catch (Throwable $e) {
			$this->logger->warning('ZGW NRC: failed to GET status', ['url' => $statusUrl, 'err' => $e->getMessage()]);
			return null;
		}

		$statustypeUrl = (string)($status['statustype'] ?? '');
		if ($statustypeUrl === '') {
			return null;
		}

		return $this->ztc->resolveOmschrijvingFromUrl($endpoint, $statustypeUrl);
	}//end statusDescription()

	/**
	 * Write a status omschrijving onto a pipelinq Request; nothing happens
	 * without a Request id or an omschrijving.
	 *
	 * @param string      $requestId The pipelinq Request id, '' when the mapping has none.
	 * @param string|null $omsch     The status omschrijving, null when unresolved.
	 *
	 * @return void
	 */
	private function applyRequestStatus(string $requestId, ?string $omsch): void {
		if ($requestId === '' || $omsch === null) {
			return;
		}

		$request = $this->registers->find('request', $requestId);
		if ($request === null) {
			$this->logger->info('ZGW NRC: status for unknown pipelinq Request', ['id' => $requestId]);
			return;
		}

		$request['status'] = $omsch;
		$this->registers->save('request', $request, $requestId);
	}//end applyRequestStatus()

	/**
	 * Handle besluit.create — observability log, mapping already persisted on outbound.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $endpoint keeps the uniform
	 *  per-kanaal handler signature invoked from the dispatch() routing table.
	 */
	private function onDecisionCreated(array $endpoint, array $notification): void {
		$url = (string)($notification['resourceUrl'] ?? $notification['hoofdObject'] ?? '');
		if ($url === '') {
			return;
		}

		$existing = $this->registers->findAll(
			ZgwRegisterAccessInterface::SCHEMA_MAPPING,
			['zgwUrl' => $url]
		);
		if ($existing !== []) {
			return;
		}

		$this->logger->info('ZGW NRC: observed externally-created besluit', ['besluit' => $url]);
	}//end onBesluitCreated()

	/**
	 * Handle catalogi changes — invalidate the ZTC cache.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param array<string, mixed> $notification Inbound payload.
	 *
	 * @return void
	 */
	private function onCatalogiChange(array $endpoint, array $notification): void {
		$resource = (string)($notification['resource'] ?? '*');
		$resourceMap = [
			'caseType' => ZtcClientInterface::RESOURCE_ZAAKTYPE,
			'statustype' => ZtcClientInterface::RESOURCE_STATUSTYPE,
			'roltype' => ZtcClientInterface::RESOURCE_ROLTYPE,
			'resultaattype' => ZtcClientInterface::RESOURCE_RESULTAATTYPE,
			'besluittype' => ZtcClientInterface::RESOURCE_BESLUITTYPE,
		];
		$bucket = $resourceMap[$resource] ?? '*';
		$this->ztc->invalidateCache($endpoint, $bucket);
	}//end onCatalogiChange()
}//end class
