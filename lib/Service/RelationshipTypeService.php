<?php

/**
 * Pipelinq RelationshipTypeService.
 *
 * The relationship types an agent can pick (partner, ouder, werkgever, ...),
 * each with its inverse and category, and the create of a relationship
 * between two parties: the type must be known and the same relationship must
 * not exist yet. The inverse row is written by RelationshipInverseListener.
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
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use Throwable;

/**
 * The relationship type table and the relationship create.
 *
 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-owns-the-relationship-types
 */
class RelationshipTypeService {

	/**
	 * The types, by stored value: label, inverse type and category.
	 *
	 * The stored values and categories are the Dutch identifiers relationships
	 * already carry; the labels are English source strings, translated per reader.
	 *
	 * @var array<string, array{label: string, inverse: string, category: string}>
	 */
	public const TYPES = [
		'partner' => ['label' => 'Partner', 'inverse' => 'partner', 'category' => 'Familie'],
		'ouder' => ['label' => 'Parent', 'inverse' => 'kind', 'category' => 'Familie'],
		'kind' => ['label' => 'Child', 'inverse' => 'ouder', 'category' => 'Familie'],
		'broer/zus' => ['label' => 'Sibling', 'inverse' => 'broer/zus', 'category' => 'Familie'],
		'werkgever' => ['label' => 'Employer', 'inverse' => 'werknemer', 'category' => 'Professioneel'],
		'werknemer' => ['label' => 'Employee', 'inverse' => 'werkgever', 'category' => 'Professioneel'],
		'collega' => ['label' => 'Colleague', 'inverse' => 'collega', 'category' => 'Professioneel'],
		'contactpersoon' => ['label' => 'Contact person', 'inverse' => 'organisatie', 'category' => 'Professioneel'],
		'organisatie' => ['label' => 'Organisation', 'inverse' => 'contactpersoon', 'category' => 'Professioneel'],
		'mentor' => ['label' => 'Mentor', 'inverse' => 'mentee', 'category' => 'Professioneel'],
		'mentee' => ['label' => 'Mentee', 'inverse' => 'mentor', 'category' => 'Professioneel'],
		'moederorganisatie' => ['label' => 'Parent organisation', 'inverse' => 'dochterorganisatie', 'category' => 'Organisatie'],
		'dochterorganisatie' => ['label' => 'Subsidiary', 'inverse' => 'moederorganisatie', 'category' => 'Organisatie'],
	];

	/**
	 * The category labels, by stored category.
	 *
	 * @var array<string, string>
	 */
	public const CATEGORIES = [
		'Familie' => 'Family',
		'Professioneel' => 'Professional',
		'Organisatie' => 'Organisation',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig             $appConfig     App config, source of the register and schema ids.
	 * @param IL10N                  $l10n          Translates the labels.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The types as select options, with their category to group by.
	 *
	 * @return array<int, array{value: string, label: string, category: string}> The options.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-owns-the-relationship-types
	 */
	public function options(): array {
		$options = [];
		foreach (self::TYPES as $value => $type) {
			$options[] = [
				'value' => $value,
				'label' => $this->l10n->t($type['label']),
				'category' => $this->l10n->t(self::CATEGORIES[$type['category']]),
			];
		}

		return $options;
	}//end options()

	/**
	 * The fields a type implies: its inverse and category.
	 *
	 * @param string $type The stored type.
	 *
	 * @return array<string, string> `inverseType` and `category`, [] for an unknown type.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-owns-the-relationship-types
	 */
	public function impliedBy(string $type): array {
		if (isset(self::TYPES[$type]) === false) {
			return [];
		}

		return ['inverseType' => self::TYPES[$type]['inverse'], 'category' => self::TYPES[$type]['category']];
	}//end impliedBy()

	/**
	 * Create a relationship as the signed-in user.
	 *
	 * @param array<string, mixed> $fields FromContact, fromType, toContact, type, and optional notes, startDate, endDate, strength.
	 *
	 * @return array<string, mixed> The saved relationship.
	 *
	 * @throws DomainException 'incomplete', 'unknown-type', 'self', 'duplicate' or 'refused'.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-refuses-an-unknown-or-repeated-relationship
	 */
	public function create(array $fields): array {
		$from = trim((string)($fields['fromContact'] ?? ''));
		$toContact = trim((string)($fields['toContact'] ?? ''));
		$type = trim((string)($fields['type'] ?? ''));
		self::assertCreatable(from: $from, toContact: $toContact, type: $type);
		if ($this->exists(from: $from, toContact: $toContact, type: $type) === true) {
			throw new DomainException('duplicate');
		}

		$relationship = array_merge(
			array_intersect_key($fields, array_flip(['notes', 'startDate', 'endDate', 'strength'])),
			[
				'fromContact' => $from,
				'toContact' => $toContact,
				'fromType' => self::partyType(value: (string)($fields['fromType'] ?? '')),
				'type' => $type,
			],
			$this->impliedBy(type: $type)
		);

		try {
			$saved = $this->objectService->saveObject(
				object: array_filter($relationship, static fn ($value): bool => $value !== null && $value !== ''),
				register: $this->register(),
				schema: $this->schemaId()
			);
		} catch (Throwable) {
			throw new DomainException('refused');
		}

		return array_merge((array)$saved->getObject(), ['id' => (string)$saved->getUuid()]);
	}//end create()

	/**
	 * Refuse a relationship that misses a party or type, names an unknown type or points at itself.
	 *
	 * @param string $from      The party it starts at.
	 * @param string $toContact The party it points to.
	 * @param string $type      The type.
	 *
	 * @return void
	 *
	 * @throws DomainException 'incomplete', 'unknown-type' or 'self'.
	 *
	 * @spec openspec/changes/relationship-types-on-the-server/specs/contact-relationship-mapping/spec.md#requirement-the-server-refuses-an-unknown-or-repeated-relationship
	 */
	public static function assertCreatable(string $from, string $toContact, string $type): void {
		if ($from === '' || $toContact === '' || $type === '') {
			throw new DomainException('incomplete');
		}

		if (isset(self::TYPES[$type]) === false) {
			throw new DomainException('unknown-type');
		}

		if ($from === $toContact) {
			throw new DomainException('self');
		}
	}//end assertCreatable()

	/**
	 * Whether this relationship is already stored.
	 *
	 * @param string $from      The party it starts at.
	 * @param string $toContact The party it points to.
	 * @param string $type      The type.
	 *
	 * @return bool True when it exists.
	 */
	private function exists(string $from, string $toContact, string $type): bool {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->register(),
						'schema' => $this->schemaId(),
						'fromContact' => $from,
						'toContact' => $toContact,
						'type' => $type,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable) {
			return false;
		}

		return count($rows) > 0;
	}//end exists()

	/**
	 * A party type: 'client' when it says so, else 'contact'.
	 *
	 * @param string $value The given type.
	 *
	 * @return string The party type.
	 */
	private static function partyType(string $value): string {
		if ($value === 'client') {
			return 'client';
		}

		return 'contact';
	}//end partyType()

	/**
	 * The pipelinq register id.
	 *
	 * @return string The id.
	 */
	private function register(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
	}//end register()

	/**
	 * The relationship schema id.
	 *
	 * @return string The id.
	 */
	private function schemaId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'relationship_schema', '');
	}//end schemaId()
}//end class
