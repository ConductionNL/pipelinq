<?php

/**
 * Pipelinq ClientRiskService.
 *
 * Works out how likely a client is to leave, as a weighted sum of named
 * signals, and keeps the result on the client as `churnRisk`.
 *
 * The level is never a black box. Every point comes from one reason, every
 * reason names its signal and, where there is one, the record behind it. The
 * weights are app config (`client_risk_weights`, a JSON object merged over the
 * defaults below), so an administrator who disagrees with a weight changes it
 * rather than distrusting the list.
 *
 * Reads and the write run in OpenRegister's system scope: a risk level is a
 * property of the client, not of whoever happened to trigger the recompute,
 * so it must not shrink to what one user may read. The system scope also
 * withholds the object events, so a recompute sends no "client changed"
 * notification.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Client churn risk: score, level and reasons.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads contracts, survey answers and the 360 summary.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskService {

	/**
	 * App-config key holding weight overrides as a JSON object.
	 *
	 * @var string
	 */
	public const WEIGHTS_KEY = 'client_risk_weights';

	/**
	 * The weights, caps, windows and level boundaries (design D1).
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_WEIGHTS = [
		'contractExpiring' => 30,
		'contractChurned' => 40,
		'contractChurnedDays' => 90,
		'detractor' => 25,
		'detractorCap' => 50,
		'detractorDays' => 180,
		'complaint' => 10,
		'complaintCap' => 30,
		'slaBreached' => 15,
		'silence' => 20,
		'silenceDays' => 90,
		'high' => 60,
		'medium' => 30,
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param Customer360SummaryService $summaryService Open complaints, breached deadlines and last activity.
	 * @param ClientRiskScore $score The score arithmetic.
	 * @param IAppConfig $appConfig Register, schema ids and weights.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly Customer360SummaryService $summaryService,
		private readonly ClientRiskScore $score,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The weights in force: the defaults with any valid override on top.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function weights(): array {
		$weights = self::DEFAULT_WEIGHTS;
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::WEIGHTS_KEY, ''), true);
		if (is_array($decoded) === false) {
			return $weights;
		}

		foreach ($decoded as $key => $value) {
			if (array_key_exists($key, $weights) === true && is_numeric($value) === true && (int)$value >= 0) {
				$weights[$key] = (int)$value;
			}
		}

		return $weights;
	}//end weights()

	/**
	 * Score a client from its facts with the weights in force.
	 *
	 * @param array<string, mixed> $facts The client's facts (see ClientRiskScore::evaluate()).
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return array{level: string, score: int, reasons: list<array<string, mixed>>, computedAt: string}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function evaluate(array $facts, DateTimeImmutable $now): array {
		return $this->score->evaluate(facts: $facts, weights: $this->weights(), now: $now);
	}//end evaluate()

	/**
	 * Recompute one client and write the result when it changed.
	 *
	 * @param string $clientId The client UUID.
	 * @param DateTimeImmutable|null $now The evaluation instant, now by default.
	 *
	 * @return bool True when the client was written.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function recompute(string $clientId, ?DateTimeImmutable $now=null): bool {
		$when = ($now ?? new DateTimeImmutable('now'));
		$result = $this->objectService->runAsSystem(
			fn (): bool => $this->recomputeInScope(clientId: $clientId, now: $when)
		);

		return ($result === true);
	}//end recompute()

	/**
	 * Recompute every client.
	 *
	 * @param DateTimeImmutable|null $now The evaluation instant, now by default.
	 *
	 * @return int How many clients were written.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function recomputeAll(?DateTimeImmutable $now=null): int {
		$when = ($now ?? new DateTimeImmutable('now'));
		$result = $this->objectService->runAsSystem(
			function () use ($when): int {
				$written = 0;
				foreach ($this->find(schemaKey: 'client_schema', filters: []) as $client) {
					$clientId = (string)($client['id'] ?? ($client['uuid'] ?? ''));
					if ($clientId !== '' && $this->writeIfChanged(clientId: $clientId, client: $client, now: $when) === true) {
						$written++;
					}
				}

				return $written;
			}
		);

		return (int)$result;
	}//end recomputeAll()

	/**
	 * Recompute one client inside the system scope.
	 *
	 * @param string $clientId The client UUID.
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return bool True when the client was written.
	 */
	private function recomputeInScope(string $clientId, DateTimeImmutable $now): bool {
		$scope = $this->scope(schemaKey: 'client_schema');
		if ($clientId === '' || $scope === null) {
			return false;
		}

		try {
			$entity = $this->objectService->find(id: $clientId, register: $scope['register'], schema: $scope['schema'], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('ClientRiskService: client not readable', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return false;
		}

		if ($entity === null) {
			return false;
		}

		return $this->writeIfChanged(clientId: $clientId, client: self::toArray(object: $entity), now: $now);
	}//end recomputeInScope()

	/**
	 * Assess a client and write `churnRisk` when it changed.
	 *
	 * @param string $clientId The client UUID.
	 * @param array<string, mixed> $client The client's data.
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return bool True when written.
	 */
	private function writeIfChanged(string $clientId, array $client, DateTimeImmutable $now): bool {
		$scope = $this->scope(schemaKey: 'client_schema');
		if ($scope === null) {
			return false;
		}

		try {
			$assessed = $this->evaluate(facts: $this->facts(clientId: $clientId, client: $client), now: $now);
			if ($this->score->changed(stored: ($client['churnRisk'] ?? null), assessed: $assessed) === false) {
				return false;
			}

			$this->objectService->patchObject(
				objectId: $clientId,
				data: ['churnRisk' => $assessed],
				register: $scope['register'],
				schema: $scope['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ClientRiskService: risk not written', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end writeIfChanged()

	/**
	 * Gather the facts the score reads.
	 *
	 * @param string $clientId The client UUID.
	 * @param array<string, mixed> $client The client's data.
	 *
	 * @return array<string, mixed>
	 */
	private function facts(string $clientId, array $client): array {
		$summary = $this->summaryService->getSummary(clientId: $clientId);

		return [
			'contracts' => $this->find(schemaKey: 'contract_schema', filters: ['clientRef' => $clientId]),
			'detractors' => $this->find(
				schemaKey: 'surveyResponse_schema',
				filters: ['clientRef' => $clientId, 'classification' => 'detractor']
			),
			'openComplaints' => (int)($summary['openTicketsByType'][TicketService::TYPE_COMPLAINT] ?? 0),
			'slaBreached' => (int)($summary['sla']['breached'] ?? 0),
			'lastActivityAt' => ($summary['lastActivityAt'] ?? null),
			'createdAt' => ($client['@self']['created'] ?? null),
		];
	}//end facts()

	/**
	 * Every object of a schema matching the filters, as arrays.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function find(string $schemaKey, array $filters): array {
		$scope = $this->scope(schemaKey: $schemaKey);
		if ($scope === null) {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => array_merge($scope, $filters), 'limit' => 10000],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ClientRiskService: read failed', ['schema' => $schemaKey, 'exception' => $e->getMessage()]);
			return [];
		}

		return array_values(array_map(static fn (mixed $row): array => self::toArray(object: $row), $rows));
	}//end find()

	/**
	 * This app's register and the schema an app-config key names, or null
	 * when either is not configured. Nothing is read or written unscoped.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function scope(string $schemaKey): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end scope()

	/**
	 * An OpenRegister object (entity or array) as an array.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
