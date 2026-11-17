<?php

/**
 * Pipelinq FillFromKvkAction.
 *
 * The OpenRegister bulk action "Fill in from KvK" (id `pipelinq:fill-from-kvk`,
 * clients-registry-numbers-and-enrichment design D4). For each selected
 * organisation with a KvK number it looks the number up in the KvK register
 * and fills only the fields that are empty. Running as an OpenRegister bulk job
 * gives it the preview, the ceilings, the audit trail and the undo of the Bulk
 * changes page with no code of its own.
 *
 * What it fills: `place`, `legalForm` and `industry` (the SBI descriptions).
 * What it never writes: `name` (a client's own name wins) and `address`, the
 * read-only mirror of the linked Nextcloud contact, which OpenRegister refuses
 * on update.
 *
 * @category BulkAction
 * @package  OCA\Pipelinq\BulkAction
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BulkAction;

use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\BulkAction\StepBulkActionInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCA\Pipelinq\Service\ClientIdentifierService;
use OCA\Pipelinq\Service\OrganisationEnrichmentService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills the empty company fields of organisations from the KvK.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md#requirement-empty-company-details-can-be-filled-from-kvk-in-bulk-req-crn-003
 */
class FillFromKvkAction implements StepBulkActionInterface, ReversibleBulkActionInterface {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'pipelinq:fill-from-kvk';

	/**
	 * How long a fill stays undoable, in seconds: seven days, as a property write.
	 *
	 * @var int
	 */
	public const REVERSAL_WINDOW = 604800;

	/**
	 * The skip reason of a client without a KvK number.
	 *
	 * @var string
	 */
	public const REASON_NO_KVK = 'no KvK number';

	/**
	 * The skip reason when the KvK register cannot be reached.
	 *
	 * @var string
	 */
	public const REASON_UNAVAILABLE = 'KvK unavailable';

	/**
	 * The skip reason when the KvK does not know the number.
	 *
	 * @var string
	 */
	public const REASON_NOT_FOUND = 'KvK number not found in the KvK';

	/**
	 * The skip reason when every field the KvK fills already holds a value.
	 *
	 * @var string
	 */
	public const REASON_ALREADY_FILLED = 'already has these values';

	/**
	 * The skip reason of a person.
	 *
	 * @var string
	 */
	public const REASON_PERSON = 'not an organisation';

	/**
	 * The fill each object gets, by object uuid, so the reversal plan and the
	 * write ask the KvK once.
	 *
	 * @var array<string, array{reason: string|null, patch: array<string, mixed>}>
	 */
	private array $fills = [];

	/**
	 * Constructor.
	 *
	 * @param ClientIdentifierServiceInterface $identifiers   The KvK lookup.
	 * @param ObjectServiceInterface           $objectService The write path, as the job's user.
	 * @param LoggerInterface                  $logger        The logger.
	 */
	public function __construct(
		private readonly ClientIdentifierServiceInterface $identifiers,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The action's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function getLabel(): string {
		return 'Fill in from KvK';
	}//end getLabel()

	/**
	 * What the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function getDescription(): string {
		return 'Looks each selected organisation up in the KvK by its KvK number and fills its empty place, legal form and industry. '
			. 'Fields that hold a value are never overwritten.';
	}//end getDescription()

	/**
	 * Whether a justification is asked for: no, it fills empty fields only.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * The guards: one schema per job.
	 *
	 * @return array<int, string> The guards.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function getGuards(): array {
		return [self::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * The action takes no parameters; any given are ignored.
	 *
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function validateParameters(array $parameters): void {
	}//end validateParameters()

	/**
	 * Rehearse or make the fill of one organisation.
	 *
	 * @param BulkStep $step The member, rehearsal or commit, and the job's user.
	 *
	 * @return BulkActionResult Applied, or skipped with its reason.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function apply(BulkStep $step): BulkActionResult {
		$fill = $this->fillFor(object: $step->object);
		if ($fill['reason'] !== null) {
			return BulkActionResult::skipped(reason: $fill['reason']);
		}

		if ($step->commit === false) {
			return BulkActionResult::applied();
		}

		try {
			$this->objectService->patchObject(
				patch: new ObjectPatch(
					objectId: (string)$step->object->getUuid(),
					data: $fill['patch'],
					target: new ObjectFindTarget(register: $step->object->getRegister(), schema: $step->object->getSchema()),
					currentUser: $step->actor
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: a KvK fill failed', ['object' => $step->object->getUuid(), 'error' => $e->getMessage()]);
			return BulkActionResult::failed(message: $e->getMessage());
		}

		return BulkActionResult::applied();
	}//end apply()

	/**
	 * How long after the job a reversal is accepted, in seconds.
	 *
	 * @return int The window.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function getReversalWindow(): int {
		return self::REVERSAL_WINDOW;
	}//end getReversalWindow()

	/**
	 * What the fill changes on one object, and what it changes it from.
	 *
	 * Only the filled fields; each was empty, so its prior value is what the
	 * object held (null, '' or []), and the undo restores exactly that.
	 *
	 * @param ObjectEntity         $object     The object.
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array{prior: array<string, mixed>, applied: array<string, mixed>} The plan.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public function reversalPlanFor(ObjectEntity $object, array $parameters): array {
		$patch = $this->fillFor(object: $object)['patch'];
		$current = $object->getObject();
		$prior = [];
		foreach (array_keys($patch) as $key) {
			$prior[$key] = ($current[$key] ?? null);
		}

		return [self::PLAN_PRIOR => $prior, self::PLAN_APPLIED => $patch];
	}//end reversalPlanFor()

	/**
	 * The KvK number of a client: its KvK identifier, else its KvK number field.
	 *
	 * @param array<string, mixed> $client The client's data.
	 *
	 * @return string The number, or '' when it has none.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public static function kvkNumberOf(array $client): string {
		foreach ((array)($client['identifiers'] ?? []) as $identifier) {
			if (is_array($identifier) === true && ($identifier['scheme'] ?? null) === 'kvk') {
				$number = ClientIdentifierService::normalise(scheme: 'kvk', value: (string)($identifier['value'] ?? ''));
				if ($number !== '') {
					return $number;
				}
			}
		}

		return trim((string)($client['kvkNumber'] ?? ''));
	}//end kvkNumberOf()

	/**
	 * The empty fields of a client the KvK row fills.
	 *
	 * @param array<string, mixed> $client  The client's data.
	 * @param array<string, mixed> $company The KvK row.
	 *
	 * @return array<string, mixed> The patch.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.1
	 */
	public static function patchFor(array $client, array $company): array {
		$address = (array)($company['adres']['binnenlandsAdres'] ?? ($company['adres'] ?? []));
		$legalForm = mb_strtolower(trim((string)($company['rechtsvorm'] ?? '')));
		$found = [
			'place' => trim((string)($address['plaats'] ?? '')),
			'legalForm' => (OrganisationEnrichmentService::LEGAL_FORMS[$legalForm] ?? ''),
			'industry' => self::sbiDescriptions(company: $company),
		];

		$patch = [];
		foreach ($found as $field => $value) {
			if ($value === '' || $value === [] || self::isEmpty(value: ($client[$field] ?? null)) === false) {
				continue;
			}

			$patch[$field] = $value;
		}

		return $patch;
	}//end patchFor()

	/**
	 * The fill of one object, looked up once.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array{reason: string|null, patch: array<string, mixed>} The skip reason, or the patch.
	 */
	private function fillFor(ObjectEntity $object): array {
		$key = (string)$object->getUuid();
		if ($key !== '' && isset($this->fills[$key]) === true) {
			return $this->fills[$key];
		}

		$fill = $this->lookUp(client: $object->getObject());
		if ($key !== '') {
			$this->fills[$key] = $fill;
		}

		return $fill;
	}//end fillFor()

	/**
	 * Look a client up and work out its fill.
	 *
	 * @param array<string, mixed> $client The client's data.
	 *
	 * @return array{reason: string|null, patch: array<string, mixed>} The skip reason, or the patch.
	 */
	private function lookUp(array $client): array {
		if (($client['type'] ?? '') === 'person') {
			return ['reason' => self::REASON_PERSON, 'patch' => []];
		}

		$number = self::kvkNumberOf(client: $client);
		if ($number === '') {
			return ['reason' => self::REASON_NO_KVK, 'patch' => []];
		}

		$answer = $this->identifiers->kvkCompany(value: $number);
		if ($answer['checkStatus'] === ClientIdentifierService::STATUS_UNAVAILABLE) {
			return ['reason' => self::REASON_UNAVAILABLE, 'patch' => []];
		}

		if ($answer['company'] === null) {
			return ['reason' => self::REASON_NOT_FOUND, 'patch' => []];
		}

		$patch = self::patchFor(client: $client, company: $answer['company']);
		if ($patch === []) {
			return ['reason' => self::REASON_ALREADY_FILLED, 'patch' => []];
		}

		return ['reason' => null, 'patch' => $patch];
	}//end lookUp()

	/**
	 * The SBI descriptions of a KvK row, main activity first, without duplicates.
	 *
	 * @param array<string, mixed> $company The KvK row.
	 *
	 * @return array<int, string> The descriptions.
	 */
	private static function sbiDescriptions(array $company): array {
		$activities = ($company['sbiActiviteiten'] ?? ($company['spiActiviteiten'] ?? []));
		if (is_array($activities) === false) {
			return [];
		}

		$activities = array_values(array_filter($activities, static fn (mixed $activity): bool => is_array($activity) === true));
		usort(
			$activities,
			static fn (array $left, array $right): int => self::isMain(activity: $right) <=> self::isMain(activity: $left)
		);

		$descriptions = [];
		foreach ($activities as $activity) {
			$description = trim((string)($activity['sbiOmschrijving'] ?? ''));
			if ($description !== '' && in_array($description, $descriptions, true) === false) {
				$descriptions[] = $description;
			}
		}

		return $descriptions;
	}//end sbiDescriptions()

	/**
	 * Whether an SBI activity is the main one, as a sortable number.
	 *
	 * @param array<string, mixed> $activity The activity.
	 *
	 * @return int 1 for the main activity, else 0.
	 */
	private static function isMain(array $activity): int {
		return (int)(($activity['indHoofdactiviteit'] ?? '') === 'Ja');
	}//end isMain()

	/**
	 * Whether a field value counts as empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for null, '' and [].
	 */
	private static function isEmpty(mixed $value): bool {
		return $value === null || $value === [] || (is_string($value) === true && trim($value) === '');
	}//end isEmpty()
}//end class
