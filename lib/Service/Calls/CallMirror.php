<?php

/**
 * Pipelinq CallMirror.
 *
 * Keeps one row per call event that reached pipelinq, whichever telephony source it came from.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Calls
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-agent-panel/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Calls;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The call mirror: a short-lived copy of each call event, with who the caller is in pipelinq.
 *
 * It knows no telephony platform. Whatever delivers a call hands it the same
 * plain fields, so an adapter in another app and a future one in this app
 * write the same row.
 *
 * Rows are written, read and removed in system context: a call arrives with
 * no user signed in, and a row holds a phone number, so the schema lets no
 * user read it through the object API.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallMirror {
	/**
	 * What can happen to a call.
	 */
	public const KINDS = ['ringing', 'answered', 'ended', 'transferred'];

	/**
	 * How long a mirrored call is kept, in days.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * How many rows one retention run reads.
	 */
	private const PURGE_BATCH = 1000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads which register and schema hold the mirror.
	 * @param ContainerInterface $container Finds OpenRegister's object service at run time.
	 * @param CallerResolver $resolver Resolves the caller once, when the row is written.
	 * @param LoggerInterface $logger Logger. A caller's number MUST NOT be written here.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private CallerResolver $resolver,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mirror one call event. A second delivery of the same event writes nothing.
	 *
	 * @param array<string, mixed> $call The event: callId, kind, callerNumber, callerName, openCases, agentId, sourceId, at, durationSeconds.
	 * @param int|null $now Unix time the row is stamped with; null is the clock.
	 *
	 * @return string|null The row's id (the existing one on a repeat), or null when nothing could be written.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function mirror(array $call, ?int $now = null): ?string {
		$callId = trim((string)($call['callId'] ?? ''));
		$kind = (string)($call['kind'] ?? '');
		$sourceId = trim((string)($call['sourceId'] ?? ''));
		[$register, $schema] = $this->location();
		if ($callId === '' || $sourceId === '' || in_array($kind, self::KINDS, true) === false || $schema === '') {
			return null;
		}

		try {
			$objects = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$key = ['callId' => $callId, 'kind' => $kind, 'sourceId' => $sourceId];
			$existing = $this->existingId(objects: $objects, register: $register, schema: $schema, key: $key);
			if ($existing !== null) {
				return $existing;
			}

			$saved = $objects->saveObject(
				object: $this->payload(call: $call, now: ($now ?? time())),
				extend: [],
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
			);
			$data = $this->toArray(row: $saved);
			return (string)($data['id'] ?? ($data['uuid'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->error('CallMirror: a call event is not mirrored', ['kind' => $kind, 'exception' => $e->getMessage()]);
			return null;
		}//end try
	}//end mirror()

	/**
	 * The id of the row already written for this call event, if there is one.
	 *
	 * @param object $objects OpenRegister's object service.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param array<string, string> $key The call id, kind and source that make an event unique.
	 *
	 * @return string|null The id, or null when the event is new.
	 */
	private function existingId(object $objects, string $register, string $schema, array $key): ?string {
		$rows = $objects->findAll(
			config: [
				'filters' => array_merge(['register' => $register, 'schema' => $schema], $key),
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false,
		);
		foreach ((array)$rows as $row) {
			$data = $this->toArray(row: $row);
			// Compared again: a store that ignored a filter must not make a new event look delivered.
			$same = true;
			foreach ($key as $field => $value) {
				$same = $same && (($data[$field] ?? null) === $value);
			}

			if ($same === true) {
				return (string)($data['id'] ?? ($data['uuid'] ?? ''));
			}
		}

		return null;
	}//end existingId()

	/**
	 * The row written for one call event.
	 *
	 * The resolved party of another app is not stored: it can carry a citizen
	 * service number, and the mirror needs a name to show, not an identifier.
	 *
	 * @param array<string, mixed> $call The event.
	 * @param int $now Unix time.
	 *
	 * @return array<string, mixed> The payload, valid against the mirroredCall schema.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function payload(array $call, int $now): array {
		$number = (string)($call['callerNumber'] ?? '');
		if (preg_match('/^\+[1-9][0-9]{1,14}$/', $number) !== 1) {
			$number = '';
		}

		$openCases = [];
		foreach ((array)($call['openCases'] ?? []) as $case) {
			if (is_array($case) === true) {
				$openCases[] = $case;
			} else if (is_string($case) === true && $case !== '') {
				$openCases[] = ['reference' => $case];
			}
		}

		return [
			'callId' => mb_substr(trim((string)$call['callId']), 0, 255),
			'kind' => (string)$call['kind'],
			'sourceId' => mb_substr(trim((string)$call['sourceId']), 0, 255),
			'callerNumber' => $number,
			'callerName' => mb_substr(trim((string)($call['callerName'] ?? '')), 0, 255),
			'agentId' => mb_substr((string)($call['agentId'] ?? ''), 0, 255),
			'at' => mb_substr((string)($call['at'] ?? ''), 0, 64),
			'durationSeconds' => max(0, (int)($call['durationSeconds'] ?? 0)),
			'openCases' => $openCases,
			'inPipelinq' => $this->resolver->inPipelinq(e164: $number),
			'mirroredAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
		];
	}//end payload()

	/**
	 * Remove mirrored calls older than the retention period.
	 *
	 * @param int|null $now Unix time to measure against; null is the clock.
	 *
	 * @return int How many rows were removed.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function purgeExpired(?int $now = null): int {
		[$register, $schema] = $this->location();
		if ($schema === '') {
			return 0;
		}

		$cutoff = (($now ?? time()) - (self::RETENTION_DAYS * 86400));
		$removed = 0;
		try {
			$objects = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$rows = $objects->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::PURGE_BATCH,
					'sort' => ['mirroredAt' => 'asc'],
				],
				_rbac: false,
				_multitenancy: false,
			);
			foreach ((array)$rows as $row) {
				$data = $this->toArray(row: $row);
				$id = (string)($data['id'] ?? ($data['uuid'] ?? ''));
				$mirroredAt = strtotime((string)($data['mirroredAt'] ?? ''));
				// A row without a readable date is kept: removing what cannot be dated is a guess.
				if ($id === '' || $mirroredAt === false || $mirroredAt >= $cutoff) {
					continue;
				}

				if ($objects->deleteObject(uuid: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false, permanent: true) === true) {
					$removed++;
				}
			}
		} catch (Throwable $e) {
			$this->logger->error('CallMirror: the retention run stopped', ['removed' => $removed, 'exception' => $e->getMessage()]);
		}//end try

		return $removed;
	}//end purgeExpired()

	/**
	 * Where the mirror lives.
	 *
	 * @return array{0: string, 1: string} Register and schema; the schema is '' when either is not configured.
	 */
	private function location(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'mirroredCall_schema', '');
		if ($register === '' || $schema === '') {
			return ['', ''];
		}

		return [$register, $schema];
	}//end location()

	/**
	 * A row as a plain array.
	 *
	 * @param mixed $row An OpenRegister entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()
}//end class
