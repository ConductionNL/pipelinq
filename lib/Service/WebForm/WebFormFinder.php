<?php

/**
 * Finds a published website form by the key a website sends, or by the Nextcloud Forms form it binds.
 *
 * A form is configuration an admin owns, and a visitor with no account must
 * reach it, so it is read with OpenRegister's RBAC off. Nothing of the form is
 * returned to the visitor; what a submit may write is decided by the
 * destination's own checks.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\WebFormFinderInterface;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Reads webForm objects from the pipelinq register.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */
class WebFormFinder implements WebFormFinderInterface {

	/**
	 * The status a form takes submits in.
	 *
	 * @var string
	 */
	public const PUBLISHED = 'published';

	/**
	 * Most forms read for one lookup: two published forms on one key is a conflict, not a list.
	 *
	 * @var int
	 */
	private const LOOKUP_LIMIT = 2;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App configuration: the register and webForm schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * The published form a website names with its `source`, or null.
	 *
	 * @param string $key The form's key.
	 *
	 * @return array<string, mixed>|null The form body with its `id`, or null when none is published.
	 *
	 * @throws RuntimeException When the register or webForm schema is not configured.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function publishedByKey(string $key): ?array {
		if ($key === '') {
			return null;
		}

		return ($this->published(filters: ['key' => $key])[0] ?? null);

	}//end publishedByKey()

	/**
	 * The published form bound to a Nextcloud Forms form, or null.
	 *
	 * @param int $formsFormId The Nextcloud Forms form id.
	 *
	 * @return array<string, mixed>|null The form body with its `id`, or null when none is bound.
	 *
	 * @throws RuntimeException When the register or webForm schema is not configured.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/public-intake-forms/spec.md#requirement-a-public-pipelinq-form-names-its-destination-and-is-checked-when-published
	 */
	public function publishedByFormsFormId(int $formsFormId): ?array {
		if ($formsFormId <= 0) {
			return null;
		}

		return ($this->published(filters: ['formsFormId' => $formsFormId])[0] ?? null);

	}//end publishedByFormsFormId()

	/**
	 * Every published form on one key, at most two: the caller only asks whether another one exists.
	 *
	 * @param string $key The form's key.
	 *
	 * @return array<int, array<string, mixed>> The forms, each with its `id`.
	 *
	 * @throws RuntimeException When the register or webForm schema is not configured.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function allPublishedByKey(string $key): array {
		if ($key === '') {
			return [];
		}

		return $this->published(filters: ['key' => $key]);

	}//end allPublishedByKey()

	/**
	 * The writes a form declares: its `writes`, or one write from `destination` and `mapping`.
	 *
	 * The same reading OpenRegister's FormDefinitionResolver applies, so a form
	 * stored here means what it would mean there.
	 *
	 * @param array<string, mixed> $form The form body.
	 *
	 * @return array<int, array<string, mixed>> The writes: `{ as, register, schema, mapping }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function writesOf(array $form): array {
		if (is_array($form['writes'] ?? null) === true && $form['writes'] !== []) {
			return array_values(array_filter($form['writes'], 'is_array'));
		}

		return $this->destinationWrites(form: $form);

	}//end writesOf()

	/**
	 * The single write a form's `destination` names; none without a schema.
	 *
	 * @param array<string, mixed> $form The form body.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function destinationWrites(array $form): array {
		$destination = ($form['destination'] ?? null);
		if (is_array($destination) === false || trim((string)($destination['schema'] ?? '')) === '') {
			return [];
		}

		return [
			[
				'as' => 'destination',
				'register' => ($destination['register'] ?? null),
				'schema' => $destination['schema'],
				'mapping' => $this->mappingOf(form: $form),
			],
		];

	}//end destinationWrites()

	/**
	 * The form's mapping, or null when it has none.
	 *
	 * @param array<string, mixed> $form The form body.
	 *
	 * @return array<mixed>|null
	 */
	private function mappingOf(array $form): ?array {
		if (is_array($form['mapping'] ?? null) === true) {
			return $form['mapping'];
		}

		return null;

	}//end mappingOf()

	/**
	 * The configured webForm schema id, or an empty string.
	 *
	 * @return string The schema id.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function schemaId(): string {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, 'webForm_schema', '');

	}//end schemaId()

	/**
	 * Published forms matching the filters.
	 *
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>> The forms, each with its `id`.
	 *
	 * @throws RuntimeException When the register or webForm schema is not configured.
	 */
	private function published(array $filters): array {
		$forms = [];
		foreach ($this->publishedRows(filters: $filters) as $row) {
			$form = $this->bodyOf(row: $row);
			if ($this->isPublishedMatch(form: $form, filters: $filters) === true) {
				$forms[] = $form;
			}
		}

		return $forms;

	}//end published()

	/**
	 * The stored rows the store hands back for the filters, published only,
	 * read with RBAC and multitenancy off.
	 *
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<mixed>
	 *
	 * @throws RuntimeException When the register or webForm schema is not configured.
	 */
	private function publishedRows(array $filters): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => array_merge($this->scope(), ['status' => self::PUBLISHED], $filters),
				'limit' => self::LOOKUP_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);

		return (array)$rows;

	}//end publishedRows()

	/**
	 * The configured register and webForm schema.
	 *
	 * @return array{register: string, schema: string}
	 *
	 * @throws RuntimeException When either is not configured.
	 */
	private function scope(): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->schemaId();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Pipelinq: the webForm register or schema is not configured.');
		}

		return ['register' => $register, 'schema' => $schema];

	}//end scope()

	/**
	 * Whether a body is published and matches every filter. The store's filter
	 * is trusted for narrowing, not for the decision: a form is only taken
	 * when its own body says published and matches.
	 *
	 * @param array<string, mixed>|null $form    The body, null for a row without one.
	 * @param array<string, mixed>      $filters Property filters.
	 *
	 * @return bool
	 */
	private function isPublishedMatch(?array $form, array $filters): bool {
		if ($form === null || ($form['status'] ?? null) !== self::PUBLISHED) {
			return false;
		}

		return $this->matchesAll(form: $form, filters: $filters);

	}//end isPublishedMatch()

	/**
	 * Whether a body carries every filter's value, compared as strings.
	 *
	 * @param array<string, mixed> $form    The body.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return bool
	 */
	private function matchesAll(array $form, array $filters): bool {
		foreach ($filters as $name => $value) {
			if ((string)($form[$name] ?? '') !== (string)$value) {
				return false;
			}
		}

		return true;

	}//end matchesAll()

	/**
	 * A stored row as a form body with its id.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>|null The body, or null for a row without one.
	 */
	private function bodyOf(mixed $row): ?array {
		$data = $this->dataOf(row: $row);
		if (is_array($data) === false) {
			return null;
		}

		$data['id'] = $this->idOf(row: $row, data: $data);

		return $data;

	}//end bodyOf()

	/**
	 * A row's data: the array itself, or an entity's serialised form.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return mixed The data, null when the row has none.
	 */
	private function dataOf(mixed $row): mixed {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return null;

	}//end dataOf()

	/**
	 * A row's id: an entity's own uuid when set, else the @self id, the id
	 * or the uuid in its data.
	 *
	 * @param mixed                $row  An ObjectEntity or an array.
	 * @param array<string, mixed> $data The row's data.
	 *
	 * @return string
	 */
	private function idOf(mixed $row, array $data): string {
		$uuid = $this->entityUuid(row: $row);
		if ($uuid !== '') {
			return $uuid;
		}

		return (string)($data['@self']['id'] ?? $data['id'] ?? $data['uuid'] ?? '');

	}//end idOf()

	/**
	 * An entity row's own uuid; '' for an array or an entity without one.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return string
	 */
	private function entityUuid(mixed $row): string {
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			return (string)$row->getUuid();
		}

		return '';

	}//end entityUuid()
}//end class
