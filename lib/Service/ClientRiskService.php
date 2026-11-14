<?php

/**
 * Pipelinq ClientRiskService.
 *
 * Scores how likely a client is to leave from named signals, and stores the
 * level with its reasons on the client.
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
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Risk is a sum of named signals (design D1).
 *
 * Each signal has a weight an administrator can change in app config
 * (`client_risk_weight_<signal>`), and every point in the score comes with the
 * signal and the records behind it, so a wrong weight is visible rather than
 * hidden in a number. The reads run as the system, with RBAC off: the score is
 * the client's, not the viewer's, and the daily job has no user.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
 */
class ClientRiskService {
	use PayloadToArray;

	/**
	 * The default weight of each signal, in points.
	 *
	 * @var array<string,int>
	 */
	public const DEFAULT_WEIGHTS = [
		'contract-expiring' => 30,
		'contract-churned' => 40,
		'detractor' => 25,
		'open-complaints' => 10,
		'sla-breached' => 15,
		'no-recent-contact' => 20,
	];

	/**
	 * The most points a signal counted per record can add.
	 *
	 * @var array<string,int>
	 */
	public const CAPS = [
		'detractor' => 50,
		'open-complaints' => 30,
	];

	/**
	 * The app-config key prefix of a signal's weight.
	 */
	public const WEIGHT_KEY_PREFIX = 'client_risk_weight_';

	/**
	 * A score at or above this is high.
	 */
	public const HIGH_FROM = 60;

	/**
	 * A score at or above this is medium.
	 */
	public const MEDIUM_FROM = 30;

	/**
	 * How far back a churned contract counts, in days.
	 */
	public const CHURN_WINDOW_DAYS = 90;

	/**
	 * How far back a detractor answer counts, in days.
	 */
	public const DETRACTOR_WINDOW_DAYS = 180;

	/**
	 * Days without contact after which a client is quiet.
	 */
	public const QUIET_AFTER_DAYS = 90;

	/**
	 * Rows read per call.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * The statuses of an open ticket.
	 *
	 * @var array<int,string>
	 */
	private const OPEN_TICKET_STATUSES = ['new', 'in_progress'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App config: schemas and weights.
	 * @param ObjectServiceInterface $objectService OpenRegister.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The client's risk: level, score, the reasons behind it and when.
	 *
	 * @param string                 $clientId The client's id.
	 * @param array<string,mixed>    $client   The client, for its creation date.
	 * @param DateTimeImmutable|null $now      The moment to judge at; now when null.
	 *
	 * @return array{
	 *     level:string,
	 *     score:int,
	 *     reasons:array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>,
	 *     computedAt:string
	 * }
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
	 */
	public function assess(string $clientId, array $client = [], ?DateTimeImmutable $now = null): array {
		$now      = ($now ?? new DateTimeImmutable('now'));
		$tickets  = $this->rows(schemaKey: 'ticket_schema', filters: ['client' => $clientId]);
		$open     = array_values(array_filter($tickets, static fn (array $t): bool => in_array(($t['status'] ?? ''), self::OPEN_TICKET_STATUSES, true)));
		$reasons  = array_merge(
			$this->contractReasons(clientId: $clientId, now: $now),
			$this->detractorReasons(clientId: $clientId, now: $now),
			$this->complaintReasons(open: $open),
			$this->slaReasons(open: $open, now: $now),
			$this->quietReasons(tickets: $tickets, client: $client, now: $now)
		);

		$score = array_sum(array_column($reasons, 'points'));

		return [
			'level' => $this->level(score: $score),
			'score' => $score,
			'reasons' => $reasons,
			'computedAt' => $now->format(DATE_ATOM),
		];
	}//end assess()

	/**
	 * Assess one client and store the result when its level or reasons changed.
	 *
	 * @param string                 $clientId The client's id.
	 * @param DateTimeImmutable|null $now      The moment to judge at; now when null.
	 *
	 * @return bool Whether the client was written.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function recompute(string $clientId, ?DateTimeImmutable $now = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return false;
		}

		try {
			$found  = $this->objectService->find(id: $clientId, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
			$client = $this->toArray(value: $found);
		} catch (Throwable $e) {
			$this->logger->warning('ClientRiskService: the client could not be read', ['clientId' => $clientId, 'exception' => $e->getMessage()]);
			return false;
		}

		return $this->store(client: $client, risk: $this->assess(clientId: $clientId, client: $client, now: $now), register: $register, schema: $schema);
	}//end recompute()

	/**
	 * Assess every client, in batches, writing only those whose level or
	 * reasons changed.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge at; now when null.
	 *
	 * @return array{written:int, unchanged:int}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function recomputeAll(?DateTimeImmutable $now = null): array {
		$tally    = ['written' => 0, 'unchanged' => 0];
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return $tally;
		}

		$now    = ($now ?? new DateTimeImmutable('now'));
		$offset = 0;
		do {
			$clients = $this->rows(schemaKey: 'client_schema', filters: [], offset: $offset);
			foreach ($clients as $client) {
				$risk    = $this->assess(clientId: $this->id(row: $client), client: $client, now: $now);
				$written = $this->store(client: $client, risk: $risk, register: $register, schema: $schema);
				$outcome = 'unchanged';
				if ($written === true) {
					$outcome = 'written';
				}

				$tally[$outcome]++;
			}

			$offset += self::BATCH_SIZE;
		} while (count($clients) === self::BATCH_SIZE);

		return $tally;
	}//end recomputeAll()

	/**
	 * Store a risk on a client unless its level and reasons are what is stored.
	 *
	 * Only the level and the reasons decide: a new `computedAt` alone is not a
	 * change, so the daily run leaves a steady client's audit trail quiet.
	 *
	 * @param array<string,mixed>  $client   The client as stored.
	 * @param array<string,mixed>  $risk     The risk from assess().
	 * @param string               $register The register id.
	 * @param string               $schema   The client schema id.
	 *
	 * @return bool Whether the client was written.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function store(array $client, array $risk, string $register, string $schema): bool {
		$stored = ($client['churnRisk'] ?? null);
		if (is_array($stored) === true
			&& ($stored['level'] ?? null) === $risk['level']
			&& ($stored['reasons'] ?? null) === $risk['reasons']
		) {
			return false;
		}

		$uuid = (string) ($client['id'] ?? ($client['uuid'] ?? ''));
		if ($uuid === '') {
			return false;
		}

		$client['churnRisk'] = $risk;
		unset($client['@self']);

		try {
			$this->objectService->saveObject(
				object: $client,
				register: $register,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('ClientRiskService: the risk could not be stored', ['clientId' => $uuid, 'exception' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end store()

	/**
	 * The level a score falls in.
	 *
	 * @param int $score The score.
	 *
	 * @return string low, medium or high.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
	 */
	public function level(int $score): string {
		if ($score >= self::HIGH_FROM) {
			return 'high';
		}

		if ($score >= self::MEDIUM_FROM) {
			return 'medium';
		}

		return 'low';
	}//end level()

	/**
	 * The weight of a signal: app config when set to a non-negative number, else the default.
	 *
	 * @param string $signal The signal.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.2
	 */
	public function weight(string $signal): int {
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, self::WEIGHT_KEY_PREFIX.$signal, ''));
		if ($configured !== '' && ctype_digit($configured) === true) {
			return (int) $configured;
		}

		return self::DEFAULT_WEIGHTS[$signal];
	}//end weight()

	/**
	 * An expiring contract, and a contract that churned in the last 90 days.
	 *
	 * @param string            $clientId The client's id.
	 * @param DateTimeImmutable $now      The moment to judge at.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function contractReasons(string $clientId, DateTimeImmutable $now): array {
		$since    = $now->modify('-'.self::CHURN_WINDOW_DAYS.' days');
		$expiring = [];
		$churned  = [];
		foreach ($this->rows(schemaKey: 'contract_schema', filters: ['clientRef' => $clientId], default: 'salesContract') as $contract) {
			$status = ($contract['status'] ?? '');
			if ($status === 'expiring') {
				$expiring[] = $this->id(row: $contract);
			}

			$ended = $this->date(value: ($contract['endDate'] ?? null));
			if ($status === 'churned' && $ended !== null && $ended >= $since && $ended <= $now) {
				$churned[] = $this->id(row: $contract);
			}
		}

		return array_merge(
			$this->once(signal: 'contract-expiring', schema: 'contract', records: $expiring),
			$this->once(signal: 'contract-churned', schema: 'contract', records: $churned)
		);
	}//end contractReasons()

	/**
	 * Detractor answers of the last 180 days, per answer, capped.
	 *
	 * @param string            $clientId The client's id.
	 * @param DateTimeImmutable $now      The moment to judge at.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function detractorReasons(string $clientId, DateTimeImmutable $now): array {
		$since   = $now->modify('-'.self::DETRACTOR_WINDOW_DAYS.' days');
		$records = [];
		foreach ($this->rows(schemaKey: 'surveyResponse_schema', filters: ['clientRef' => $clientId, 'classification' => 'detractor']) as $response) {
			$submitted = $this->date(value: ($response['submittedAt'] ?? null));
			if (($response['classification'] ?? '') === 'detractor' && $submitted !== null && $submitted >= $since && $submitted <= $now) {
				$records[] = $this->id(row: $response);
			}
		}

		return $this->each(signal: 'detractor', schema: 'surveyResponse', records: $records);
	}//end detractorReasons()

	/**
	 * Open complaints, per complaint, capped.
	 *
	 * @param array<int,array<string,mixed>> $open The client's open tickets.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function complaintReasons(array $open): array {
		$complaints = array_filter($open, static fn (array $t): bool => ($t['ticketType'] ?? '') === TicketService::TYPE_COMPLAINT);

		$records    = array_map(fn (array $t): string => $this->id(row: $t), array_values($complaints));

		return $this->each(signal: 'open-complaints', schema: 'ticket', records: $records);
	}//end complaintReasons()

	/**
	 * Open tickets past their SLA deadline.
	 *
	 * @param array<int,array<string,mixed>> $open The client's open tickets.
	 * @param DateTimeImmutable              $now  The moment to judge at.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function slaReasons(array $open, DateTimeImmutable $now): array {
		$breached = [];
		foreach ($open as $ticket) {
			$deadline = $this->date(value: ($ticket['slaDeadline'] ?? null));
			if ($deadline !== null && $deadline < $now) {
				$breached[] = $this->id(row: $ticket);
			}
		}

		return $this->once(signal: 'sla-breached', schema: 'ticket', records: $breached);
	}//end slaReasons()

	/**
	 * No contact for more than 90 days: the newest ticket of any kind, or the
	 * client's own creation when it has none. A client with neither is not judged.
	 *
	 * @param array<int,array<string,mixed>> $tickets The client's tickets.
	 * @param array<string,mixed>            $client  The client.
	 * @param DateTimeImmutable              $now     The moment to judge at.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function quietReasons(array $tickets, array $client, DateTimeImmutable $now): array {
		$latest = $this->created(row: $client);
		$record = '';
		foreach ($tickets as $ticket) {
			$when = ($this->date(value: ($ticket['occurredAt'] ?? null)) ?? $this->created(row: $ticket));
			if ($when !== null && ($latest === null || $when > $latest)) {
				$latest = $when;
				$record = $this->id(row: $ticket);
			}
		}

		if ($latest === null || $latest >= $now->modify('-'.self::QUIET_AFTER_DAYS.' days')) {
			return [];
		}

		return $this->once(signal: 'no-recent-contact', schema: 'ticket', records: array_filter([$record]), always: true);
	}//end quietReasons()

	/**
	 * One reason worth the signal's weight once, when it has records.
	 *
	 * @param string             $signal  The signal.
	 * @param string             $schema  The schema of the records.
	 * @param array<int,string>  $records The records behind it.
	 * @param bool               $always  Whether it counts without records.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function once(string $signal, string $schema, array $records, bool $always = false): array {
		if ($records === [] && $always === false) {
			return [];
		}

		return [['signal' => $signal, 'points' => $this->weight(signal: $signal), 'schema' => $schema, 'records' => array_values($records)]];
	}//end once()

	/**
	 * One reason worth the signal's weight per record, up to its cap.
	 *
	 * @param string            $signal  The signal.
	 * @param string            $schema  The schema of the records.
	 * @param array<int,string> $records The records behind it.
	 *
	 * @return array<int,array{signal:string, points:int, schema:string, records:array<int,string>}>
	 */
	private function each(string $signal, string $schema, array $records): array {
		if ($records === []) {
			return [];
		}

		$points = min(($this->weight(signal: $signal) * count($records)), self::CAPS[$signal]);

		return [['signal' => $signal, 'points' => $points, 'schema' => $schema, 'records' => array_values($records)]];
	}//end each()

	/**
	 * Rows of a configured schema, read as the system; [] when unconfigured or unreadable.
	 *
	 * @param string              $schemaKey The app-config key of the schema.
	 * @param array<string,mixed> $filters   Equality filters.
	 * @param string              $default   The schema when the key is unset.
	 * @param int                 $offset    Rows to skip.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schemaKey, array $filters, string $default = '', int $offset = 0): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, $default);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters), 'limit' => self::BATCH_SIZE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('ClientRiskService: a signal could not be read', ['schema' => $schemaKey, 'exception' => $e->getMessage()]);
			return [];
		}

		return array_map(fn (mixed $row): array => $this->toArray(value: $row), $rows);
	}//end rows()

	/**
	 * A row's id.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string
	 */
	private function id(array $row): string {
		return (string) ($row['id'] ?? ($row['uuid'] ?? ''));
	}//end id()

	/**
	 * When a row was created, from its `@self` metadata.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function created(array $row): ?DateTimeImmutable {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === false) {
			return null;
		}

		return $this->date(value: ($self['created'] ?? null));
	}//end created()

	/**
	 * A date string as a moment; null when blank or unreadable.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end date()
}//end class
