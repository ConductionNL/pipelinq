<?php

/**
 * Submits objects held in an in-between schema into the destination their form names, and reports.
 *
 * Decision 179 retires every holding object between a form and the object a
 * person works on. What was held must not be lost, and must not be guessed
 * into a destination either: each held object carries the key of the form it
 * came from (`source` on an enquiry), and goes exactly where that published
 * form sends a submit today, through the same WebFormIntakeService a visitor's
 * submit uses, so the drain is judged by the same destination checks.
 *
 * - delivered: the destination exists; with apply, the held object is then
 *   deleted (OpenRegister keeps it soft-deleted), so pending counts down.
 * - refused: the destination refused it (422 with finding codes, or 503).
 * - unmapped: no published form for its key, or no key at all. Reported,
 *   never guessed.
 *
 * Without apply nothing is written: the report says what would be delivered.
 * Each submit carries the idempotency key `drain:<id>`, so a rerun after a
 * failed delete repeats the first answer instead of writing a second object
 * (within OpenRegister's 24-hour window).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\SystemServiceAccount;
use Throwable;

/**
 * Drains one held schema into the destinations its forms name.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */
class HeldObjectDrain {

	/**
	 * Rows read per page. Never "unlimited".
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Hard ceiling on rows read in one run, so a runaway store cannot loop forever.
	 *
	 * @var int
	 */
	private const MAX_ROWS = 100000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param WebFormFinder          $finder        Finds the published form for a key.
	 * @param WebFormIntakeService   $intake        Submits into the form's destination.
	 * @param SystemServiceAccount   $account       The account a delivered held object is deleted as.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly WebFormFinder $finder,
		private readonly WebFormIntakeService $intake,
		private readonly SystemServiceAccount $account,
	) {

	}//end __construct()

	/**
	 * Drain the open objects of one held schema.
	 *
	 * @param string               $register    The register id or slug.
	 * @param string               $schema      The held schema id or slug.
	 * @param string               $keyProperty The property naming the form a held object came from.
	 * @param array<string, mixed> $openFilter  Property filters that select the still-open objects.
	 * @param bool                 $apply       True to submit and delete; false to report only.
	 *
	 * @return array<string, mixed> The report: `delivered`, `refused` and `unmapped` lists, and
	 *   `pending`, what is still held after this run.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
	 */
	public function drain(string $register, string $schema, string $keyProperty, array $openFilter, bool $apply): array {
		$report = ['delivered' => [], 'refused' => [], 'unmapped' => [], 'pending' => 0];
		$forms = [];
		foreach ($this->openObjects(register: $register, schema: $schema, filter: $openFilter) as $held) {
			$heldId = (string)$held['id'];
			$key = (string)($held[$keyProperty] ?? '');
			if (array_key_exists($key, $forms) === false) {
				$forms[$key] = $this->finder->publishedByKey(key: $key);
			}

			$form = $forms[$key];
			if ($form === null) {
				$report['unmapped'][] = ['id' => $heldId, 'key' => $key];
				$report['pending']++;
				continue;
			}

			if ($apply === false) {
				$report['delivered'][] = ['id' => $heldId, 'key' => $key, 'destination' => $this->destinationOf(form: $form), 'reference' => ''];
				$report['pending']++;
				continue;
			}

			$this->deliver(held: $held, form: $form, register: $register, schema: $schema, report: $report);
		}//end foreach

		return $report;

	}//end drain()

	/**
	 * Submit one held object and, once its destination exists, delete it.
	 *
	 * @param array<string, mixed> $held     The held object.
	 * @param array<string, mixed> $form     Its published form.
	 * @param string               $register The register.
	 * @param string               $schema   The held schema.
	 * @param array<string, mixed> $report   The report, by reference.
	 *
	 * @return void
	 */
	private function deliver(array $held, array $form, string $register, string $schema, array &$report): void {
		$heldId = (string)$held['id'];
		$payload = $held;
		unset($payload['id'], $payload['@self']);

		try {
			$answer = $this->intake->submit(form: $form, payload: $payload, idempotencyKey: 'drain:' . $heldId);
		} catch (WebFormSubmitException $e) {
			$report['refused'][] = [
				'id' => $heldId,
				'status' => $e->getStatus(),
				'codes' => array_map(static fn (array $finding): string => (string)($finding['code'] ?? ''), $e->getFindings()),
			];
			$report['pending']++;
			return;
		}

		$entry = [
			'id' => $heldId,
			'key' => (string)($form['key'] ?? ''),
			'destination' => $this->destinationOf(form: $form),
			'reference' => (string)$answer['reference'],
		];
		try {
			$this->objectService->deleteObject(
				uuid: $heldId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: false,
				currentUser: $this->account->account(),
			);
		} catch (Throwable $e) {
			// Delivered, but still held: counted pending so the schema is not
			// removed over it, and named so a person can delete it by hand.
			$entry['notRemoved'] = $e->getMessage();
			$report['pending']++;
		}

		$report['delivered'][] = $entry;

	}//end deliver()

	/**
	 * The destination a form names, for the report.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return string The destination schema slugs, comma separated.
	 */
	private function destinationOf(array $form): string {
		return implode(
			',',
			array_map(static fn (array $write): string => (string)($write['schema'] ?? ''), $this->finder->writesOf(form: $form))
		);

	}//end destinationOf()

	/**
	 * Every open held object, page by page, as bodies with their id.
	 *
	 * Read with RBAC off: the drain is an admin's command over the whole
	 * store, and an object it cannot see would be counted as drained.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The held schema.
	 * @param array<string, mixed> $filter   Property filters for the open objects.
	 *
	 * @return array<int, array<string, mixed>> The held objects.
	 */
	private function openObjects(string $register, string $schema, array $filter): array {
		$held = [];
		for ($offset = 0; $offset < self::MAX_ROWS; $offset += self::PAGE_SIZE) {
			$rows = (array)$this->objectService->findAll(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filter),
					'limit' => self::PAGE_SIZE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false,
			);
			foreach ($rows as $row) {
				$body = $this->bodyOf(row: $row);
				if ($body !== null && $this->matches(body: $body, filter: $filter) === true) {
					$held[] = $body;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}

		return $held;

	}//end openObjects()

	/**
	 * Whether a body matches the open filter itself: the store's filter narrows, it does not decide.
	 *
	 * @param array<string, mixed> $body   The body.
	 * @param array<string, mixed> $filter The filter.
	 *
	 * @return bool True when every filtered property matches.
	 */
	private function matches(array $body, array $filter): bool {
		foreach ($filter as $name => $value) {
			if ((string)($body[$name] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;

	}//end matches()

	/**
	 * A stored row as a body with its id.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>|null The body, or null.
	 */
	private function bodyOf(mixed $row): ?array {
		$data = null;
		if (is_array($row) === true) {
			$data = $row;
		} else if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
		}

		if (is_array($data) === false) {
			return null;
		}

		$id = (string)($data['@self']['id'] ?? $data['id'] ?? $data['uuid'] ?? '');
		if ($id === '') {
			return null;
		}

		$data['id'] = $id;

		return $data;

	}//end bodyOf()
}//end class
