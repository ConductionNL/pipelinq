<?php

/**
 * Pipelinq AvailabilityCacheInvalidationListener.
 *
 * Expires the cached availability of a resource when the resource or a
 * service it serves changes, for every writer of those objects.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Expire cached availability when a resource or a service changes.
 *
 * A resource's working hours, vacations or type decide its free slots, and a
 * service's required resource types decide which resources it books. Either
 * change makes the cached days of the affected resources wrong. The detail
 * pages used to delete those rows from the browser, row by row and ignoring
 * failures, so an API write, an import or a sync left stale slots behind.
 *
 * ADR-078: the write is already stored when this runs, so the deletion is
 * deferred to {@see DeferredObjectListenerJob}. The deletion touches only the
 * availability-cache schema, which this listener ignores, so it cannot
 * re-enter itself.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
 */
class AvailabilityCacheInvalidationListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'availability-cache-invalidation';

	/**
	 * Constructor.
	 *
	 * @param AvailabilityService $availability The availability engine that owns the cache.
	 * @param IAppConfig $appConfig The app configuration (schema ids).
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 */
	public function __construct(
		private AvailabilityService $availability,
		private IAppConfig $appConfig,
		private ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the expiry for a created, updated or deleted resource or service.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function handle(Event $event): void {
		[$entity, $previous] = $this->entitiesOf(event: $event);
		if ($entity === null) {
			return;
		}

		$kind = $this->kindOf(schemaId: (string)$entity->getSchema());
		$uuid = (string)$entity->getUuid();
		if ($kind === null || $uuid === '') {
			return;
		}

		$entry = ['handler' => self::HANDLER_KEY, 'uuid' => $uuid, 'kind' => $kind];
		if ($kind === 'service') {
			$before = null;
			if ($previous !== null) {
				$before = $this->resourceTypes(data: (array)$previous->getObject());
			}

			$entry['resourceTypes'] = $this->affectedTypes(
				current: $this->resourceTypes(data: (array)$entity->getObject()),
				previous: $before
			);
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: $entry,
			dedupeKey: self::HANDLER_KEY . '|' . $uuid
		);
	}//end handle()

	/**
	 * Delete the cached availability the change made wrong.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		if (($entry['kind'] ?? '') === 'resource') {
			$this->availability->invalidateResource(resourceId: $uuid);
			return;
		}

		if (($entry['kind'] ?? '') === 'service') {
			$types = ($entry['resourceTypes'] ?? []);
			if (is_array($types) === false) {
				$types = [];
			}

			$this->availability->invalidateForResourceTypes(resourceTypes: $types);
		}
	}//end runDeferredWork()

	/**
	 * The object an event is about, and its previous state on an update.
	 *
	 * @param Event $event The event.
	 *
	 * @return array{0: object|null, 1: object|null}
	 */
	private function entitiesOf(Event $event): array {
		if ($event instanceof ObjectUpdatedEvent) {
			return [$event->getNewObject(), $event->getOldObject()];
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectDeletedEvent) {
			return [$event->getObject(), null];
		}

		return [null, null];
	}//end entitiesOf()

	/**
	 * Whether a schema is the resource or the service schema.
	 *
	 * @param string $schemaId The object's schema id.
	 *
	 * @return string|null `resource`, `service`, or null for any other schema.
	 */
	private function kindOf(string $schemaId): ?string {
		if ($schemaId === '') {
			return null;
		}

		$kinds = [
			'resource' => AvailabilityService::RESOURCE_SCHEMA_KEY,
			'service' => AvailabilityService::SERVICE_SCHEMA_KEY,
		];
		foreach ($kinds as $kind => $key) {
			if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === $schemaId) {
				return $kind;
			}
		}

		return null;
	}//end kindOf()

	/**
	 * The resource types whose cache a service change made wrong. The old and
	 * the new types both count: a resource the service stopped needing loses
	 * slots too. A service that names no type runs on every resource, so an
	 * empty list on either side means every resource (an empty answer).
	 *
	 * @param array<int, string> $current The types after the change.
	 * @param array<int, string>|null $previous The types before it, null on a create or delete.
	 *
	 * @return array<int, string>
	 */
	private function affectedTypes(array $current, ?array $previous): array {
		if ($current === [] || $previous === []) {
			return [];
		}

		return array_values(array_unique(array_merge($current, ($previous ?? []))));
	}//end affectedTypes()

	/**
	 * A service's required resource types.
	 *
	 * @param array<string, mixed> $data The service data.
	 *
	 * @return array<int, string>
	 */
	private function resourceTypes(array $data): array {
		$types = ($data['requiredResourceTypes'] ?? []);
		if (is_array($types) === false) {
			return [];
		}

		return array_values(array_filter($types, static fn ($type): bool => is_string($type) === true && $type !== ''));
	}//end resourceTypes()
}//end class
