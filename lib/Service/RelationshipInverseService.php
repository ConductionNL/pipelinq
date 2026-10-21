<?php

/**
 * Pipelinq RelationshipInverseService.
 *
 * Writes the inverse row of a relationship: A is B's parent, so B is A's
 * child. Called from RelationshipInverseListener's deferred work.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, patches and deletes the inverse row of a relationship.
 *
 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
 */
class RelationshipInverseService {

	/**
	 * Fields both rows of a pair carry with the same value.
	 *
	 * @var array<int, string>
	 */
	public const SHARED_FIELDS = ['category', 'notes', 'startDate', 'endDate', 'strength'];

	/**
	 * Upper bound on rows read between one pair of parties.
	 *
	 * @var int
	 */
	private const PAIR_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config, source of the register and schema ids.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bring the inverse row of a relationship in line with it as it is stored now.
	 *
	 * @param string $uuid                The relationship uuid.
	 * @param string $previousInverseType The inverse type before an edit, '' on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
	 */
	public function syncInverse(string $uuid, string $previousInverseType): void {
		$relationship = $this->read(uuid: $uuid);
		if ($relationship === null) {
			// Deleted since the save: the delete event handles the inverse (ADR-078 Rule 7).
			return;
		}

		$expected = $this->inverseOf(relationship: $relationship);
		if ($expected === null) {
			return;
		}

		$types = array_values(
			array_unique(array_filter([$previousInverseType, $expected['type']], static fn (string $type): bool => $type !== ''))
		);
		$inverse = $this->findInverse(fromContact: $expected['fromContact'], toContact: $expected['toContact'], types: $types);
		if ($inverse === null) {
			$this->objectService->saveObject(
				object: array_filter($expected, static fn ($value): bool => $value !== null),
				register: $this->register(),
				schema: $this->schemaId()
			);
			return;
		}

		$patch = $this->differences(current: $inverse['data'], expected: $expected);
		if ($patch === []) {
			return;
		}

		$this->objectService->patchObject(
			objectId: $inverse['uuid'],
			data: $patch,
			register: $this->register(),
			schema: $this->schemaId()
		);
	}//end syncInverse()

	/**
	 * The inverse row a relationship implies, or null when it implies none.
	 *
	 * @param array<string, mixed> $relationship The stored relationship.
	 *
	 * @return array<string, mixed>|null The inverse row's fields.
	 */
	private function inverseOf(array $relationship): ?array {
		$from = trim((string)($relationship['fromContact'] ?? ''));
		$to = trim((string)($relationship['toContact'] ?? ''));
		$type = trim((string)($relationship['type'] ?? ''));
		if ($from === '' || $to === '' || $type === '' || $from === $to) {
			return null;
		}

		$inverseType = trim((string)($relationship['inverseType'] ?? ''));
		if ($inverseType === '') {
			$inverseType = $type;
		}

		$inverse = [
			'fromContact' => $to,
			'toContact' => $from,
			'fromType' => (string)($relationship['toType'] ?? 'contact'),
			'toType' => (string)($relationship['fromType'] ?? 'contact'),
			'type' => $inverseType,
			'inverseType' => $type,
		];
		foreach (self::SHARED_FIELDS as $field) {
			$inverse[$field] = ($relationship[$field] ?? null);
		}

		return $inverse;
	}//end inverseOf()

	/**
	 * The fields of the inverse row that differ from what they should be.
	 *
	 * @param array<string, mixed> $current  The inverse row as stored.
	 * @param array<string, mixed> $expected The inverse row as implied.
	 *
	 * @return array<string, mixed> The patch, [] when nothing differs.
	 */
	private function differences(array $current, array $expected): array {
		$patch = [];
		foreach ($expected as $field => $value) {
			$stored = ($current[$field] ?? null);
			if ($stored === '' && $value === null) {
				continue;
			}

			if ($stored !== $value) {
				$patch[$field] = $value;
			}
		}

		return $patch;
	}//end differences()

	/**
	 * Delete the inverse of a relationship that was deleted.
	 *
	 * @param string $fromContact The deleted row's first party.
	 * @param string $toContact   The deleted row's second party.
	 * @param string $inverseType The deleted row's inverse type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
	 */
	public function removeInverse(string $fromContact, string $toContact, string $inverseType): void {
		if ($fromContact === '' || $toContact === '' || $inverseType === '') {
			return;
		}

		$inverse = $this->findInverse(fromContact: $toContact, toContact: $fromContact, types: [$inverseType]);
		if ($inverse === null) {
			return;
		}

		$this->objectService->deleteObject(
			uuid: $inverse['uuid'],
			register: $this->register(),
			schema: $this->schemaId()
		);
	}//end removeInverse()

	/**
	 * Find the row from one party to another with the first matching type.
	 *
	 * @param string             $fromContact The party the row starts at.
	 * @param string             $toContact   The party the row points to.
	 * @param array<int, string> $types       Acceptable types, most likely first.
	 *
	 * @return array{uuid: string, data: array<string, mixed>}|null The row.
	 */
	private function findInverse(string $fromContact, string $toContact, array $types): ?array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => $this->register(),
					'schema' => $this->schemaId(),
					'fromContact' => $fromContact,
					'toContact' => $toContact,
				],
				'limit' => self::PAIR_LIMIT,
			]
		);

		$byType = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null
				|| ($normalised['data']['fromContact'] ?? null) !== $fromContact
				|| ($normalised['data']['toContact'] ?? null) !== $toContact
			) {
				continue;
			}

			$byType[(string)($normalised['data']['type'] ?? '')] ??= $normalised;
		}

		foreach ($types as $type) {
			if (isset($byType[$type]) === true) {
				return $byType[$type];
			}
		}

		return null;
	}//end findInverse()

	/**
	 * Turn a findAll row into its uuid and data.
	 *
	 * @param mixed $row An ObjectEntity or a serialised object.
	 *
	 * @return array{uuid: string, data: array<string, mixed>}|null The row, null when it has no uuid.
	 */
	private function normalise(mixed $row): ?array {
		if ($row instanceof ObjectEntity) {
			if ($row->isSoftDeleted() === true) {
				return null;
			}

			return ['uuid' => (string)$row->getUuid(), 'data' => (array)$row->getObject()];
		}

		if (is_array($row) === false) {
			return null;
		}

		$uuid = (string)($row['id'] ?? $row['@self']['id'] ?? $row['uuid'] ?? '');
		if ($uuid === '') {
			return null;
		}

		return ['uuid' => $uuid, 'data' => $row];
	}//end normalise()

	/**
	 * Read a relationship as it is stored now.
	 *
	 * @param string $uuid The relationship uuid.
	 *
	 * @return array<string, mixed>|null The relationship, null when gone.
	 */
	private function read(string $uuid): ?array {
		if ($uuid === '' || $this->register() === '' || $this->schemaId() === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $uuid, register: $this->register(), schema: $this->schemaId());
		} catch (Throwable $e) {
			$this->logger->info(
				'RelationshipInverseService: the relationship is no longer readable',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($object === null || ($object instanceof ObjectEntity && $object->isSoftDeleted() === true)) {
			return null;
		}

		return (array)$object->getObject();
	}//end read()

	/**
	 * The pipelinq register id.
	 *
	 * @return string The id, '' when not configured.
	 */
	private function register(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end register()

	/**
	 * The relationship schema id.
	 *
	 * @return string The id, '' when not configured.
	 *
	 * @spec openspec/changes/relationship-and-channel-rules-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-keeps-the-inverse-relationship
	 */
	public function schemaId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'relationship_schema', '');
	}//end schemaId()
}//end class
