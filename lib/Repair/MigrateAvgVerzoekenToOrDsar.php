<?php

/**
 * Pipelinq MigrateAvgVerzoekenToOrDsar repair step.
 *
 * ADR-047 Phase 3: converts pre-existing pipelinq `avgVerzoek` objects (and
 * their bewijsItem / weigering / redactieActie satellites) into OpenRegister
 * `dataSubjectRequest` cases in OR's `data-subject-requests` register, applying
 * the field-mapping table from the change design. Lossless where the OR schema
 * has a field; NL-specific extras land in a structured JSON migration block in
 * `notes` so nothing is silently dropped.
 *
 * Idempotent on the TARGET: each case records the uuid of the verzoek it came
 * from (`notes.migratedFromId`), and a re-run skips any source that already has
 * one. The `migratedTo` marker on the source is a best-effort back-reference and
 * is NOT what makes this safe — it cannot be written from a repair step at all,
 * because an avgVerzoek is owned by `__system__` and carries a folder no acting
 * user can reach. Keying idempotency on the source marker (the original design)
 * meant the marker write failed after the case was created, so every re-run
 * produced a duplicate case.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-time migration of pipelinq avgVerzoek objects into OR dataSubjectRequest.
 *
 * @spec                                           openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MigrateAvgVerzoekenToOrDsar implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * OR's data-subject-requests register slug.
	 *
	 * @var string
	 */
	private const OR_REGISTER = 'data-subject-requests';

	/**
	 * OR's dataSubjectRequest schema slug.
	 *
	 * @var string
	 */
	private const OR_SCHEMA = 'dataSubjectRequest';

	/**
	 * Maps avgVerzoek `artikel` to dataSubjectRequest `type`.
	 *
	 * The keys are the avgVerzoek `artikel` enum verbatim. They were previously
	 * the bare article numbers ('art-15'), which match nothing — so every lookup
	 * missed and fell through to a default of 'access', silently recording an
	 * erasure request as an access request. Keys must stay in step with the
	 * source enum, which is why the (now-retired) fragment's values are spelled
	 * out here rather than abbreviated.
	 *
	 * `geen-avg` ("not a GDPR request") has no dataSubjectRequest equivalent and
	 * is deliberately absent: such a verzoek is skipped rather than mistyped.
	 *
	 * @var array<string, string>
	 */
	private const ARTICLE_TYPE = [
		'art-15-inzage' => 'access',
		'art-16-rectificatie' => 'rectification',
		'art-17-wissing' => 'erasure',
		'art-18-beperking' => 'restriction',
		'art-20-portabiliteit' => 'portability',
	];

	/**
	 * Maps avgVerzoek `status` to dataSubjectRequest `status` (non-terminal).
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'ingediend' => 'received',
		'in-progress' => 'in-progress',
		'bewijs-verzamelen' => 'evidence-collection',
		'redactie' => 'evidence-collection',
		'bundle-genereren' => 'in-progress',
		'wachten-op-verzoeker' => 'verifying',
		'weigering-opgesteld' => 'denial-drafted',
		'gearchiveerd' => 'closed',
	];

	/**
	 * Maps avgVerzoek `uitkomst` to a terminal dataSubjectRequest `status` for
	 * the `afgerond` source status.
	 *
	 * @var array<string, string>
	 */
	private const OUTCOME_TERMINAL_STATUS = [
		'toegekend' => 'fulfilled',
		'gedeeltelijk' => 'fulfilled',
		'geweigerd' => 'refused',
		'ingetrokken' => 'closed',
	];

	/**
	 * Maps pipelinq weigering `grond` to OR `denialGround`.
	 *
	 * The source enum is the GDPR Article 23 sub-paragraph the restriction rests
	 * on ('art-23-lid-1-sub-a' …), not a descriptive slug. The previous keys
	 * ('kennelijk-ongegrond' …) matched none of them, so every denial ground
	 * collapsed to 'not-applicable' — erasing the legal basis for the refusal.
	 *
	 * Art 23(1)(a)–(e) and (h) are all restrictions in the general public
	 * interest (national security, defence, public security, criminal
	 * enforcement, other public-interest objectives, and the regulatory
	 * monitoring attached to them), so they share the 'public-interest' target.
	 * (f) judicial independence/proceedings maps to the statutory exemption,
	 * (g) breaches of professional ethics to professional secrecy, (i) the
	 * rights and freedoms of others to third-party rights, and (j) enforcement
	 * of civil-law claims to legal claims. Art 23(3) governs the *content* of a
	 * restriction rather than supplying a ground, hence 'not-applicable'.
	 *
	 * @var array<string, string>
	 */
	private const DENIAL_GROUND = [
		'art-23-lid-1-sub-a' => 'public-interest',
		'art-23-lid-1-sub-b' => 'public-interest',
		'art-23-lid-1-sub-c' => 'public-interest',
		'art-23-lid-1-sub-d' => 'public-interest',
		'art-23-lid-1-sub-e' => 'public-interest',
		'art-23-lid-1-sub-f' => 'legal-exemption',
		'art-23-lid-1-sub-g' => 'professional-secrecy',
		'art-23-lid-1-sub-h' => 'public-interest',
		'art-23-lid-1-sub-i' => 'third-party-rights',
		'art-23-lid-1-sub-j' => 'legal-claims',
		'art-23-lid-3' => 'not-applicable',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config (register/schema ids).
	 * @param ContainerInterface $container Container for the OpenRegister ObjectService.
	 * @param LoggerInterface $logger Logger.
	 * @param IGroupManager|null $groupManager Resolves the acting user for folder access.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * The set of source verzoek uuids that already have a dataSubjectRequest.
	 *
	 * Idempotency is keyed on the TARGET, not on a marker written back to the
	 * source. The source cannot be relied on: an avgVerzoek is owned by
	 * `__system__` and carries a folder that no acting user can reach, and
	 * OpenRegister's folder guard is a documented default-deny that must not be
	 * weakened — so a repair step cannot update it at all. Marking the source was
	 * therefore permanently failing AFTER the case had been created, and each
	 * re-run produced another duplicate case.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return array<string, bool> Source uuids that are already migrated.
	 */
	private function alreadyMigrated(object $objectService): array {
		$migrated = [];

		try {
			$cases = $objectService->findAll(
				['filters' => ['register' => self::OR_REGISTER, 'schema' => self::OR_SCHEMA], 'limit' => 5000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq AVG→DSAR migration: could not read existing dataSubjectRequest cases — ' . $e->getMessage()
			);
			return [];
		}

		foreach ((array)$cases as $case) {
			$row = $this->rowToArray(row: $case);
			$notes = ($row['notes'] ?? null);

			// OpenRegister may hand `notes` back as the raw JSON string OR as an
			// already-decoded array (it hydrates JSON-shaped values), so accept
			// both. Casting an array to string and json_decode()-ing it yields
			// null, which silently emptied this map and duplicated every case.
			if (is_string($notes) === true) {
				$notes = json_decode($notes, true);
			}

			if (is_array($notes) === false) {
				continue;
			}

			$sourceId = (string)($notes['migratedFromId'] ?? '');
			if ($sourceId !== '') {
				$migrated[$sourceId] = true;
			}
		}//end foreach

		return $migrated;
	}//end alreadyMigrated()

	/**
	 * Resolve the user OpenRegister should act as when touching an object's folder.
	 *
	 * @return IUser|null The acting user, or null when none is resolvable.
	 */
	private function resolveActingUser(): ?IUser {
		try {
			$adminGroup = $this->groupManager?->get('admin');
			if ($adminGroup !== null) {
				$admins = $adminGroup->getUsers();
				if (count($admins) > 0) {
					return reset($admins);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq AVG→DSAR migration: could not resolve an acting user — ' . $e->getMessage()
			);
		}

		return null;
	}//end resolveActingUser()

	/**
	 * Repair step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or
	 */
	public function getName(): string {
		return 'Migrate pipelinq avgVerzoek objects to OpenRegister dataSubjectRequest cases (consume-or-dsar)';
	}//end getName()

	/**
	 * Migrate every not-yet-migrated avgVerzoek into an OR dataSubjectRequest.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017-migration-of-existing-avgverzoek-data-to-or
	 */
	public function run(IOutput $output): void {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$requestSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'avgVerzoek_schema', '');
		if ($registerId === '' || $requestSchema === '') {
			$output->info('Pipelinq AVG→DSAR migration: no avgVerzoek schema provisioned, nothing to migrate.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->warning('Pipelinq AVG→DSAR migration: OpenRegister ObjectService unavailable — skipped.');
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses the write for 'Anonymous'. Without it this migration moves
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $registerId, $requestSchema, $output): void {
				$this->runInner(
					source: new DsarMigrationSource(objectService: $objectService, registerId: $registerId, requestSchema: $requestSchema),
					output: $output,
				);
			}
		);
	}//end run()

	/**
	 * The migration itself.
	 *
	 * @param DsarMigrationSource $source Where the avgVerzoek objects are read from.
	 * @param IOutput             $output Progress reporting.
	 *
	 * @return void
	 */
	private function runInner(DsarMigrationSource $source, IOutput $output): void {
		$verzoeken = $this->readRequests(source: $source, output: $output);
		if ($verzoeken === []) {
			return;
		}

		$run = new DsarMigrationRun(
			source: $source,
			satellites: $this->loadSatellites(source: $source),
			migrated: $this->alreadyMigrated(objectService: $source->objectService),
		);
		$counts = $this->migrateAll(run: $run, verzoeken: $verzoeken);

		$this->report(output: $output, summary: new DsarMigrationSummary(counts: $counts, total: count($verzoeken)));
	}//end runInner()

	/**
	 * Read the avgVerzoek objects; a failed read is a warning, no objects an
	 * info line, and either gives nothing to migrate.
	 *
	 * @param DsarMigrationSource $source Where the avgVerzoek objects are read from.
	 * @param IOutput             $output Progress reporting.
	 *
	 * @return array<int|string, mixed> The source rows, or none.
	 */
	private function readRequests(DsarMigrationSource $source, IOutput $output): array {
		try {
			$verzoeken = $this->fetchRequests(source: $source);
		} catch (\Throwable $e) {
			$output->warning('Pipelinq AVG→DSAR migration: could not read avgVerzoek objects — ' . $e->getMessage());
			return [];
		}

		if (is_array($verzoeken) === false || $verzoeken === []) {
			$output->info('Pipelinq AVG→DSAR migration: no avgVerzoek objects present.');
			return [];
		}

		return $verzoeken;
	}//end readRequests()

	/**
	 * Query every avgVerzoek object.
	 *
	 * @param DsarMigrationSource $source Where the avgVerzoek objects are read from.
	 *
	 * @return mixed What findAll returned.
	 */
	private function fetchRequests(DsarMigrationSource $source): mixed {
		// System read: an RBAC-filtered read as 'Anonymous' sees nothing, so
		// the migration would silently report "no avgVerzoek objects present"
		// instead of migrating them.
		return $source->objectService->findAll(
			['filters' => ['register' => $source->registerId, 'schema' => $source->requestSchema], 'limit' => 5000],
			_rbac: false,
			_multitenancy: false
		);
	}//end fetchRequests()

	/**
	 * Migrate every readable source row and tally the outcome buckets.
	 *
	 * @param DsarMigrationRun         $run       The run.
	 * @param array<int|string, mixed> $verzoeken The source rows.
	 *
	 * @return array<string, int> Outcome buckets.
	 */
	private function migrateAll(DsarMigrationRun $run, array $verzoeken): array {
		$counts = ['migrated' => 0, 'skipped' => 0, 'unmappable' => 0, 'failed' => 0];
		foreach ($verzoeken as $row) {
			$request = $this->rowToArray(row: $row);
			if ($request === null) {
				continue;
			}

			$counts[$this->migrateRequest(run: $run, request: $request)]++;
		}

		return $counts;
	}//end migrateAll()

	/**
	 * Migrate one source object of a run.
	 *
	 * @param DsarMigrationRun     $run     The run.
	 * @param array<string, mixed> $request The source object.
	 *
	 * @return string One of `migrated` | `skipped` | `unmappable` | `failed`.
	 */
	private function migrateRequest(DsarMigrationRun $run, array $request): string {
		return $this->migrateOne(
			objectService: $run->source->objectService,
			registerId: $run->source->registerId,
			requestSchema: $run->source->requestSchema,
			request: $request,
			satellites: $run->satellites,
			migrated: $run->migrated
		);
	}//end migrateRequest()

	/**
	 * Emit the run summary plus the two conditional warnings.
	 *
	 * @param IOutput              $output  The repair output channel.
	 * @param DsarMigrationSummary $summary Outcome buckets and source objects seen.
	 *
	 * @return void
	 */
	private function report(IOutput $output, DsarMigrationSummary $summary): void {
		$line = $summary->line();
		$output->info($line);
		$this->logger->info($line);

		$this->warnUnmappable(output: $output, unmappable: $summary->counts['unmappable']);
		$this->warnFailed(output: $output, failed: $summary->counts['failed']);
	}//end report()

	/**
	 * Warn about verzoeken left in place because they have no DSAR equivalent.
	 *
	 * @param IOutput $output     The repair output channel.
	 * @param int     $unmappable How many were left in place.
	 *
	 * @return void
	 */
	private function warnUnmappable(IOutput $output, int $unmappable): void {
		if ($unmappable > 0) {
			$output->warning(
				'Pipelinq AVG→DSAR migration: ' . $unmappable
				. ' verzoek(en) have no dataSubjectRequest equivalent (e.g. artikel "geen-avg") and were left'
				. ' in place. They are not failures — but they will not disappear on a re-run either, so decide'
				. ' what should happen to them before retiring the avgVerzoek fragment.'
			);
		}
	}//end warnUnmappable()

	/**
	 * Warn about objects whose migration failed.
	 *
	 * @param IOutput $output The repair output channel.
	 * @param int     $failed How many failed.
	 *
	 * @return void
	 */
	private function warnFailed(IOutput $output, int $failed): void {
		if ($failed > 0) {
			$output->warning(
				'Pipelinq AVG→DSAR migration: ' . $failed
				. ' object(s) failed — the avgVerzoek fragment must not be removed until a clean run.'
			);
		}
	}//end warnFailed()

	/**
	 * Migrate one avgVerzoek object; return the outcome bucket.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId The pipelinq register id.
	 * @param string $requestSchema The avgVerzoek schema id.
	 * @param array<string, mixed> $request The source object.
	 * @param array<string, mixed> $satellites The indexed satellite store.
	 * @param array<string, bool> $migrated Source uuids that already have a case.
	 *
	 * @return string One of `migrated` | `skipped` | `unmappable` | `failed`.
	 */
	private function migrateOne(
		object $objectService,
		string $registerId,
		string $requestSchema,
		array $request,
		array $satellites,
		array $migrated = [],
	): string {
		$requestId = (string)($request['id'] ?? ($request['uuid'] ?? ''));

		// Already has a dataSubjectRequest: the authoritative idempotency check,
		// since the marker on the source may never have been writable.
		if ($requestId !== '' && isset($migrated[$requestId]) === true) {
			return 'skipped';
		}

		if (($request['migratedTo'] ?? '') !== '') {
			return 'skipped';
		}

		// A trashed verzoek has no business being resurrected into the compliance
		// register. Test emptiness rather than `!== null`: a live object carries
		// an EMPTY `deleted` block, not a null one, so a null-check skips every
		// object — including the ones we are here to migrate.
		if (empty($request['deleted']) === false || empty($request['@self']['deleted']) === false) {
			return 'skipped';
		}

		// An article with no dataSubjectRequest equivalent (notably 'geen-avg',
		// i.e. "not a GDPR request") is surfaced, never coerced into a type. The
		// old code defaulted it — and every other article — to 'access'.
		$article = (string)($request['artikel'] ?? '');
		if (isset(self::ARTICLE_TYPE[$article]) === false) {
			$this->logger->warning(
				'Pipelinq AVG→DSAR migration: no dataSubjectRequest type for artikel — verzoek left in place',
				['id' => (string)($request['id'] ?? ''), 'artikel' => $article]
			);
			return 'unmappable';
		}

		try {
			$case = $this->mapRequest(request: $request, satellites: $satellites);

			// A repair step runs from `occ` with no user session, so RBAC
			// resolves the actor to 'Anonymous' and refuses the write — which is
			// what made every migration attempt fail. Both writes are system
			// writes, as in the other repair/CLI object writers.
			$saved = $objectService->saveObject(
				$case,
				[],
				self::OR_REGISTER,
				self::OR_SCHEMA,
				null,
				_rbac: false,
				_multitenancy: false,
				currentUser: $this->resolveActingUser()
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq AVG→DSAR migration: failed to migrate an avgVerzoek',
				['id' => (string)($request['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return 'failed';
		}//end try

		// The case now EXISTS, and it records the source uuid in its notes — so a
		// re-run will skip this verzoek via alreadyMigrated() whether or not the
		// marker below ever lands. Writing the marker back is a nicety (it makes
		// the link visible from the source side too) and is therefore best-effort:
		// an avgVerzoek is owned by `__system__` and carries a folder no acting
		// user can reach, and OpenRegister's folder guard is a documented
		// default-deny, so this write CANNOT succeed from a repair step today.
		try {
			$request['migratedTo'] = (string)$saved->getUuid();

			// Drop nulls before writing the source back. An object read out of
			// OpenRegister carries its unset properties as null, and the schema
			// types them (`uitkomst` is a string) — so saving the row back
			// unchanged fails validation on a field we never touched, leaving the
			// marker unwritten and the case duplicated on every re-run.
			$payload = array_filter($request, static fn ($value): bool => $value !== null);

			$objectService->saveObject(
				$payload,
				[],
				$registerId,
				$requestSchema,
				(string)($request['id'] ?? ($request['uuid'] ?? '')),
				_rbac: false,
				_multitenancy: false,
				currentUser: $this->resolveActingUser()
			);
			return 'migrated';
		} catch (\Throwable $e) {
			// Not a failure: the case exists and carries the source uuid, so the
			// migration is complete and a re-run will not duplicate it. Only the
			// back-reference on the source is missing.
			$this->logger->info(
				'Pipelinq AVG→DSAR migration: case created; back-reference on the source could not be written'
				. ' (the verzoek is system-owned and its folder is unreachable from a repair step). The case'
				. ' records the source uuid, so this is not a failure and will not duplicate on a re-run.',
				[
					'verzoek' => (string)($request['id'] ?? ''),
					'case' => (string)$saved->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
			return 'migrated';
		}//end try
	}//end migrateOne()

	/**
	 * Map one avgVerzoek (+ its satellites) to a dataSubjectRequest payload.
	 *
	 * @param array<string, mixed> $request The source object.
	 * @param array<string, mixed> $satellites Satellites indexed by schema then verzoekId.
	 *
	 * @return array<string, mixed> The OR case payload.
	 */
	private function mapRequest(array $request, array $satellites): array {
		$requestId = (string)($request['id'] ?? ($request['uuid'] ?? ''));
		$article = (string)($request['artikel'] ?? '');

		$evidence = (array)(($satellites['bewijs'][$requestId] ?? []));
		$weigering = (array)(($satellites['weigering'][$requestId] ?? []));
		$redactie = $this->redactionsOf(evidence: $evidence, satellites: $satellites);

		$dueAt = (string)($request['wettelijkeTermijnVerloopt'] ?? '');

		// Guaranteed present: migrateOne() refuses an unmappable article before
		// we get here, rather than defaulting it to 'access' and filing an
		// erasure request as an access request.
		$type = self::ARTICLE_TYPE[$article];

		$case = [
			'subjectId' => $this->subjectIdOf(request: $request),
			'subjectType' => $this->subjectTypeOf(request: $request),
			'jurisdiction' => 'NL',
			'type' => $type,
			'status' => $this->mapStatus(request: $request),
			'receivedAt' => (string)($request['ingediendOp'] ?? ''),
			'handler' => (string)($request['behandelaar'] ?? ''),
			'dpiaRequired' => (bool)($request['dpiaFlag'] ?? false),
			'notes' => $this->migrationNotes(request: $request, weigering: $weigering),
		];

		if ($dueAt !== '') {
			$case['dueAt'] = $dueAt;
		}

		$this->applyExtension(case: $case, request: $request, dueAt: $dueAt);

		$case = $this->copyOptionalFields(case: $case, request: $request);
		$case = $this->applyGround(case: $case, weigering: $weigering);
		$case = $this->applyCollection(case: $case, key: 'evidence', records: $this->mapEvidence(items: $evidence));
		$case = $this->applyCollection(case: $case, key: 'redactions', records: $this->mapRedactions(redactie: $redactie));

		return $case;
	}//end mapVerzoek()

	/**
	 * The data subject of a verzoek: its contact, else its BSN, else its name.
	 *
	 * @param array<string, mixed> $request The source object.
	 *
	 * @return string The subject id, `unknown` when the verzoek names none.
	 */
	private function subjectIdOf(array $request): string {
		$subjectId = (string)($request['verzoekerContact'] ?? '');
		if ($subjectId === '') {
			$subjectId = (string)($request['verzoekerBsn'] ?? ($request['verzoekerNaam'] ?? 'unknown'));
		}

		return $subjectId;
	}//end subjectIdOf()

	/**
	 * Whether the subject is a CRM contact or a natural person.
	 *
	 * @param array<string, mixed> $request The source object.
	 *
	 * @return string `contact` or `natural-person`.
	 */
	private function subjectTypeOf(array $request): string {
		if (($request['verzoekerContact'] ?? '') !== '') {
			return 'contact';
		}

		return 'natural-person';
	}//end subjectTypeOf()

	/**
	 * The redaction actions of a verzoek's evidence, in evidence order.
	 *
	 * Redactions reach the verzoek only through its bewijsItems — they are
	 * indexed by `bewijsItemId`, the field they actually carry.
	 *
	 * @param array<int, mixed> $evidence The verzoek's bewijsItems.
	 * @param array<string, mixed> $satellites Satellites indexed by schema then id.
	 *
	 * @return array<int, mixed> The redaction actions.
	 */
	private function redactionsOf(array $evidence, array $satellites): array {
		$redactie = [];
		foreach ($evidence as $item) {
			$evidenceId = (string)(($item['id'] ?? ($item['uuid'] ?? '')));
			if ($evidenceId === '') {
				continue;
			}

			foreach ((array)($satellites['redactie'][$evidenceId] ?? []) as $action) {
				$redactie[] = $action;
			}
		}

		return $redactie;
	}//end redactionsOf()

	/**
	 * Copy the optional 1:1 string fields, only when present.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<string, mixed> $request The source object.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function copyOptionalFields(array $case, array $request): array {
		foreach (['afgerondOp' => 'closedAt', 'outcome' => 'outcome', 'retentionTo' => 'retainUntil'] as $src => $dst) {
			if (($request[$src] ?? '') !== '') {
				$case[$dst] = (string)$request[$src];
			}
		}

		return $case;
	}//end copyOptionalFields()

	/**
	 * Attach the denial ground to the case when a weigering satellite exists.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<string, mixed> $weigering The weigering satellite.
	 *
	 * @return array<string, mixed> The case with denialGround set when applicable.
	 */
	private function applyGround(array $case, array $weigering): array {
		$denialGround = $this->mapDenialGround(weigering: $weigering);
		if ($denialGround !== null) {
			$case['denialGround'] = $denialGround;
		}

		return $case;
	}//end applyGround()

	/**
	 * Attach a non-empty sub-collection (evidence / redactions) to the case.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param string $key The case field name.
	 * @param array<int, array<string, string>> $records The mapped records.
	 *
	 * @return array<string, mixed> The case with the collection set when non-empty.
	 */
	private function applyCollection(array $case, string $key, array $records): array {
		if ($records !== []) {
			$case[$key] = $records;
		}

		return $case;
	}//end applyCollection()

	/**
	 * Map the source status to the OR status vocabulary.
	 *
	 * @param array<string, mixed> $request The source object.
	 *
	 * @return string The OR status.
	 */
	private function mapStatus(array $request): string {
		$status = (string)($request['status'] ?? '');
		if ((string)($request['artikel'] ?? '') === 'geen-avg') {
			return 'closed';
		}

		if ($status === 'completed') {
			$outcome = (string)($request['outcome'] ?? '');
			return (self::OUTCOME_TERMINAL_STATUS[$outcome] ?? 'closed');
		}

		return (self::STATUS_MAP[$status] ?? 'received');
	}//end mapStatus()

	/**
	 * Apply extension fields (extendedUntil / extensionReason) to the case.
	 *
	 * @param array<string, mixed> $case The case payload (by reference).
	 * @param array<string, mixed> $request The source object.
	 * @param string $dueAt The base due date.
	 *
	 * @return void
	 */
	private function applyExtension(array &$case, array $request, string $dueAt): void {
		$verlengdWith = (int)($request['verlengdMet'] ?? 0);
		if ($verlengdWith <= 0) {
			return;
		}

		$case['extensionReason'] = (string)($request['verlengingsgrond'] ?? '');
		if ($dueAt === '') {
			return;
		}

		try {
			$base = new DateTimeImmutable($dueAt);
			$case['extendedUntil'] = $base->modify(sprintf('+%d days', $verlengdWith))->format(DateTimeInterface::ATOM);
		} catch (\Throwable $e) {
			// Unparseable due date — leave extendedUntil unset (reason preserved).
		}
	}//end applyExtension()

	/**
	 * Nearest OR denial ground for a weigering satellite, or null.
	 *
	 * @param array<string, mixed> $weigering The weigering satellite (may be empty).
	 *
	 * @return string|null The OR denial ground.
	 */
	private function mapDenialGround(array $weigering): ?string {
		if ($weigering === []) {
			return null;
		}

		$grond = (string)($weigering['grond'] ?? '');
		return (self::DENIAL_GROUND[$grond] ?? 'not-applicable');
	}//end mapDenialGround()

	/**
	 * Map bewijsItem satellites to the OR evidence[] shape.
	 *
	 * @param array<mixed> $items The evidence satellites.
	 *
	 * @return array<int, array<string, string>> The evidence records.
	 */
	private function mapEvidence(array $items): array {
		$evidence = [];
		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$evidence[] = [
				'sourceId' => (string)($item['bronApp'] ?? 'pipelinq-crm'),
				'contentHash' => (string)($item['contentHash'] ?? ('sha256:' . hash('sha256', (string)($item['id'] ?? '')))),
				'status' => 'collected',
			];
		}

		return $evidence;
	}//end mapEvidence()

	/**
	 * Map redactieActie satellites to the OR redactions[] shape.
	 *
	 * @param array<mixed> $redactie The redactie satellites.
	 *
	 * @return array<int, array<string, string>> The redaction records.
	 */
	private function mapRedactions(array $redactie): array {
		$redactions = [];
		foreach ($redactie as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$redactions[] = [
				'field' => (string)($item['veldpad'] ?? ''),
				'before' => (string)($item['voorWaarde'] ?? ''),
				'after' => (string)($item['naWaarde'] ?? ''),
				'ground' => (string)($item['grond'] ?? ''),
			];
		}

		return $redactions;
	}//end mapRedactions()

	/**
	 * Build the structured JSON migration notes block preserving NL extras.
	 *
	 * @param array<string, mixed> $request The source object.
	 * @param array<string, mixed> $weigering The weigering satellite (may be empty).
	 *
	 * @return string The JSON notes block.
	 */
	private function migrationNotes(array $request, array $weigering): string {
		$block = [
			'migratedFrom' => 'pipelinq/avgVerzoek',
			// The source's uuid, so the CASE records which verzoek it came from.
			// This is what makes the migration idempotent: the marker on the
			// source cannot be relied on (see alreadyMigrated()).
			'migratedFromId' => ($request['id'] ?? ($request['uuid'] ?? null)),
			'kenmerk' => ($request['kenmerk'] ?? null),
			'ingediendVia' => ($request['ingediendVia'] ?? null),
			'specifiekeVraag' => ($request['specifiekeVraag'] ?? null),
			'scope' => ($request['scope'] ?? null),
			'verzoekerBsnGeverifieerd' => ($request['verzoekerBsnGeverifieerd'] ?? null),
			'fgGeinformeerd' => ($request['fgGeinformeerd'] ?? null),
			'termijnOverschreden' => ($request['termijnOverschreden'] ?? null),
			'bewijsbundel' => ($request['bewijsbundel'] ?? null),
			'oorspronkelijkArtikel' => ($request['artikel'] ?? null),
		];

		if ($weigering !== []) {
			$block['weigeringTekst'] = ($weigering['weigering'] ?? null);
			$block['weigeringToelichting'] = ($weigering['toelichtingAvg23'] ?? null);
		}

		return (string)json_encode(array_filter($block, static fn ($v): bool => $v !== null));
	}//end migrationNotes()

	/**
	 * Load bewijsItem / weigering / redactieActie satellites indexed by verzoekId.
	 *
	 * @param DsarMigrationSource $source Where the satellites are read from.
	 *
	 * @return array<string, array<int|string, mixed>> Indexed satellite store.
	 */
	private function loadSatellites(DsarMigrationSource $source): array {
		return [
			'bewijs' => $this->indexByRequest(source: $source, satellite: new DsarSatelliteSchema(schemaKey: 'bewijsItem_schema', parentField: 'requestId')),
			// A redactieActie hangs off a bewijsItem, NOT off the verzoek: its
			// required parent is `bewijsItemId` and it has no `verzoekId` at all.
			// Indexing it by `verzoekId` matched nothing, so `redactions[]` came
			// out empty for every migrated request — a silent, total loss of the
			// redaction record. Index by the real parent and resolve through the
			// verzoek's bewijsItems (see mapVerzoek).
			'redactie' => $this->indexByRequest(
				source: $source,
				satellite: new DsarSatelliteSchema(schemaKey: 'redactieActie_schema', parentField: 'bewijsItemId')
			),
			'weigering' => $this->indexFirstByRequest(
				source: $source,
				satellite: new DsarSatelliteSchema(schemaKey: 'weigering_schema', parentField: 'requestId')
			),
		];
	}//end loadSatellites()

	/**
	 * Group a satellite schema's objects into lists keyed by their parent verzoekId.
	 *
	 * @param DsarMigrationSource $source    Where the satellite is read from.
	 * @param DsarSatelliteSchema $satellite The satellite schema and its parent field.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The grouped store.
	 */
	private function indexByRequest(DsarMigrationSource $source, DsarSatelliteSchema $satellite): array {
		$index = [];
		foreach ($this->readSchema(source: $source, schemaKey: $satellite->schemaKey) as $row) {
			$data = $this->rowToArray(row: $row);
			if ($data === null) {
				continue;
			}

			$parent = (string)($data[$satellite->parentField] ?? '');
			if ($parent === '') {
				continue;
			}

			$index[$parent][] = $data;
		}

		return $index;
	}//end indexByRequest()

	/**
	 * Group a satellite schema keeping the first object per parent verzoekId.
	 *
	 * @param DsarMigrationSource $source    Where the satellite is read from.
	 * @param DsarSatelliteSchema $satellite The satellite schema and its parent field.
	 *
	 * @return array<string, array<string, mixed>> The first-per-parent store.
	 */
	private function indexFirstByRequest(DsarMigrationSource $source, DsarSatelliteSchema $satellite): array {
		$index = [];
		foreach ($this->readSchema(source: $source, schemaKey: $satellite->schemaKey) as $row) {
			$data = $this->rowToArray(row: $row);
			if ($data === null) {
				continue;
			}

			$parent = (string)($data[$satellite->parentField] ?? '');
			if ($parent === '' || isset($index[$parent]) === true) {
				continue;
			}

			$index[$parent] = $data;
		}

		return $index;
	}//end indexFirstByRequest()

	/**
	 * Read a schema's objects by config key, tolerating an absent schema.
	 *
	 * @param DsarMigrationSource $source    Where the schema is read from.
	 * @param string              $schemaKey The schema config key.
	 *
	 * @return array<int, mixed> The rows (empty when the schema is unprovisioned/unreadable).
	 */
	private function readSchema(DsarMigrationSource $source, string $schemaKey): array {
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($schemaId === '') {
			return [];
		}

		return $this->findSchemaRows(source: $source, schemaId: $schemaId);
	}//end readSchema()

	/**
	 * Query one schema's objects; a failed or non-list answer gives none.
	 *
	 * @param DsarMigrationSource $source   Where the schema is read from.
	 * @param string              $schemaId The schema id.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function findSchemaRows(DsarMigrationSource $source, string $schemaId): array {
		try {
			$rows = $source->objectService->findAll(
				['filters' => ['register' => $source->registerId, 'schema' => $schemaId], 'limit' => 10000]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($rows) === true) {
			return $rows;
		}

		return [];
	}//end findSchemaRows()

	/**
	 * Normalise a findAll row (ObjectEntity or rendered array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null The data, or null when unreadable.
	 */
	private function rowToArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if ($row instanceof \JsonSerializable === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end rowToArray()
}//end class
