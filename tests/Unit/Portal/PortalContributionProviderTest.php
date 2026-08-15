<?php

/**
 * Unit tests for the Portaliq portal contribution provider.
 *
 * Pins pipelinq's ADR-046 contract-v2 contribution: dependency-free
 * duck-typed shape (inert without portaliq), the v2 getAudiences() + v1
 * getAudience() pair, the per-audience manifest (collections, scoping map,
 * claim names, minTrust), the conservative create-action whitelists and the
 * client-safe read-field projections on the contactmoment and booking
 * collections. Also pins the scoping map + projection whitelists against the
 * register JSONs at HEAD so a schema drift (renamed scope property, dropped
 * whitelist field) fails here instead of silently scoping portal reads to
 * nothing or dropping a projected column.
 *
 * Since unify-ticket-supertype, requests/complaints/contactmomenten are all rows
 * of the ONE `ticket` schema discriminated by `ticketType`. The tests below pin
 * that every ticket-backed collection carries a narrowing `filter` and every
 * ticket-backed create action carries a server-side `defaults` stamp on that
 * discriminator — dropping either would collapse all three kinds into one
 * surface, so it must fail here rather than leak.
 *
 * Subjects use nil-pattern UUIDs per the change design.md Seed Data section —
 * self-evidently fake, never colliding with live data.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pin the declarative portal contribution manifest.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
final class PortalContributionProviderTest extends TestCase {
	/**
	 * Server-derived subject fixture for the client audience (nil UUIDs).
	 *
	 * @var array<string, mixed>
	 */
	private const CLIENT_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000001',
		'audience' => 'client',
		'organisation' => '00000000-0000-0000-0000-000000000002',
		'trust' => 'substantial',
	];

	/**
	 * Server-derived subject fixture for the customer audience (nil UUIDs).
	 *
	 * @var array<string, mixed>
	 */
	private const CUSTOMER_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000003',
		'audience' => 'customer',
		'organisation' => '00000000-0000-0000-0000-000000000002',
		'trust' => 'substantial',
	];

	/**
	 * The provider under test (direct construction — no container).
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * Construct the provider directly, as portaliq's registry would.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();
	}//end setUp()

	/**
	 * Scenario: Provider is discoverable and inert without portaliq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testProviderIsPlainAndDependencyFree(): void {
		$reflection = new ReflectionClass(PortalContributionProvider::class);

		$this->assertSame(
			'OCA\\Pipelinq\\Portal\\PortalContributionProvider',
			$reflection->getName(),
			'Provider must live at the convention FQCN portaliq probes for'
		);
		$this->assertSame([], $reflection->getInterfaceNames(), 'Duck-typed: no implements clause allowed');
		$this->assertFalse($reflection->getParentClass(), 'Provider must not extend anything');
		$this->assertNull($reflection->getConstructor(), 'Provider must have no constructor dependencies');

		$source = (string)file_get_contents((string)$reflection->getFileName());
		$this->assertStringNotContainsStringIgnoringCase(
			'portaliq',
			preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $source) ?? '',
			'Provider code must reference no portaliq symbol (comments excluded)'
		);
	}//end testProviderIsPlainAndDependencyFree()

	/**
	 * Scenario: Audiences advertised on both contract versions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testAudiencesOnBothContractVersions(): void {
		$this->assertSame(['client', 'customer'], $this->provider->getAudiences());
		$this->assertSame('client', $this->provider->getAudience(), 'v1 fallback must return the primary audience');
	}//end testAudiencesOnBothContractVersions()

	/**
	 * Scenario: Client sees org-scoped read collections.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testClientCollectionsAreOrgScoped(): void {
		$manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
		$this->assertIsArray($manifest);

		$collections = $this->indexById($manifest['collections']);
		$this->assertSame(
			['clientComplaints', 'clientContactmoments', 'clientContracts', 'clientRequests'],
			$this->sortedKeys($collections),
			'Client audience exposes request, complaint, contract and (field-projected) contactmoment'
		);

		// Requests, complaints and contactmomenten are now all `ticket` rows; the
		// per-collection ticketType filter is the ONLY thing keeping them apart.
		$expected = [
			'clientRequests' => ['ticket', 'client', 'request'],
			'clientComplaints' => ['ticket', 'client', 'complaint'],
			'clientContracts' => ['contract', 'clientRef', null],
			'clientContactmoments' => ['ticket', 'client', 'interaction'],
		];
		foreach ($expected as $id => [$schema, $scopeField, $ticketType]) {
			$this->assertSame('pipelinq', $collections[$id]['register']);
			$this->assertSame($schema, $collections[$id]['schema']);
			$this->assertSame($scopeField, $collections[$id]['scopeField']);
			$this->assertSame('clientId', $collections[$id]['scopeClaim'], 'Client surfaces scope by the clientId claim');
			$this->assertTrue($collections[$id]['listable']);

			if ($ticketType === null) {
				$this->assertArrayNotHasKey('filter', $collections[$id], 'Non-ticket collections declare no discriminator narrowing');
				continue;
			}

			$this->assertSame(
				['ticketType' => $ticketType],
				$collections[$id]['filter'],
				"'{$id}' must narrow the ticket supertype to ticketType '{$ticketType}'"
			);
		}

		// The three ticket surfaces must narrow to three DISTINCT kinds — if two
		// ever collapsed onto the same discriminator (or one dropped its filter),
		// a client would see other kinds' tickets in the wrong list.
		$ticketTypes = array_column(
			array_column(
				array_filter($manifest['collections'], static fn (array $c): bool => $c['schema'] === 'ticket'),
				'filter'
			),
			'ticketType'
		);
		sort($ticketTypes);
		$this->assertSame(['complaint', 'interaction', 'request'], $ticketTypes, 'Each ticket collection narrows to its own kind');

		$schemas = array_column($manifest['collections'], 'schema');
		$this->assertNotContains('booking', $schemas, 'booking is a customer surface, never in the client manifest');
		$this->assertSame(
			[],
			array_intersect(['request', 'complaint', 'interaction'], $schemas),
			'The request/complaint/contactmoment schemas are retired — every one of them is a ticket row now'
		);
	}//end testClientCollectionsAreOrgScoped()

	/**
	 * Scenario: Client create actions are conservative whitelists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testClientActionsAreConservativeWhitelists(): void {
		$manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
		$this->assertIsArray($manifest);

		$actions = $this->indexById($manifest['actions']);
		$this->assertSame(['createComplaint', 'createRequest'], $this->sortedKeys($actions));

		// Both intakes write the unified `ticket` schema. The kind is stamped
		// server-side from `defaults` — never taken from the client — and the
		// complaint's classification is `complaintCategory` (an enum), NOT the
		// supertype's free-text `category`, which belongs to the request.
		$expected = [
			'createRequest' => ['request', ['title', 'description', 'category']],
			'createComplaint' => ['complaint', ['title', 'description', 'complaintCategory']],
		];
		foreach ($expected as $id => [$ticketType, $fields]) {
			$this->assertSame('create', $actions[$id]['type']);
			$this->assertSame('pipelinq', $actions[$id]['register']);
			$this->assertSame('ticket', $actions[$id]['schema']);
			$this->assertSame(
				['ticketType' => $ticketType],
				$actions[$id]['defaults'],
				"'{$id}' must stamp ticketType '{$ticketType}' server-side"
			);
			$this->assertSame($fields, $actions[$id]['fields']);
			$this->assertNotContains(
				'ticketType',
				$actions[$id]['fields'],
				'ticketType is a server-side default, never a client-writable field — a client must not be able to file a complaint through the request form'
			);
		}

		$forbidden = [
			'status',
			'assignee',
			'assignedTo',
			'priority',
			'pipeline',
			'queue',
			'stage',
			'slaDeadline',
			'slaStatus',
			'client',
			'contact',
			'resolution',
			'resolvedAt',
		];
		foreach ($actions as $action) {
			foreach ($forbidden as $field) {
				$this->assertNotContains($field, $action['fields'], "Whitelist must never expose '{$field}'");
			}
		}
	}//end testClientActionsAreConservativeWhitelists()

	/**
	 * Scenario: Customer sees own DSAR and loyalty surfaces.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testCustomerCollectionsAreSubjectScoped(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$this->assertIsArray($manifest);

		$collections = $this->indexById($manifest['collections']);
		// consume-or-dsar removed the customerAvgVerzoeken collection: DSAR
		// cases moved to OpenRegister's data-subject-requests register.
		$this->assertSame(['customerBookings', 'customerLoyalty'], $this->sortedKeys($collections));

		$loyalty = $collections['customerLoyalty'];
		$this->assertSame('pipelinq', $loyalty['register']);
		$this->assertSame('klantLoyaltyAccount', $loyalty['schema']);
		$this->assertSame('customerId', $loyalty['scopeField']);
		$this->assertSame('customerUid', $loyalty['scopeClaim'], 'Loyalty scopes by the NC contact UID claim — a different identifier space than contactId');
		$this->assertTrue($loyalty['listable']);

		$booking = $collections['customerBookings'];
		$this->assertSame('pipelinq', $booking['register']);
		$this->assertSame('booking', $booking['schema']);
		$this->assertSame('customerId', $booking['scopeField']);
		$this->assertSame('customerUid', $booking['scopeClaim'], 'Booking scopes by the NC addressbook contact ref — same customerUid claim space as loyalty');
		$this->assertSame('substantial', $booking['minTrust'], 'Booking notes may carry special-category (allergy) data — eIDAS-substantial floor');
		$this->assertTrue($booking['listable']);

		$schemas = array_column($manifest['collections'], 'schema');
		$this->assertContains('booking', $schemas, 'booking is now field-projected in, not excluded');
		$this->assertNotContains('berichtenboxMessage', $schemas, 'Berichtenbox stays BSN-scoped, not contact/customer-scoped — no inbox');
		foreach ($manifest['collections'] as $collection) {
			$this->assertArrayNotHasKey('kind', $collection, 'No inbox collections ship');
		}
	}//end testCustomerCollectionsAreSubjectScoped()

	/**
	 * Scenario: no customer actions remain after DSAR moved to OpenRegister.
	 *
	 * consume-or-dsar removed the createAvgVerzoek intake action (pipelinq no
	 * longer owns the avgVerzoek schema; citizen DSAR intake is OpenRegister's
	 * responsibility). The customer contribution ships no actions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014--openregister-compliance-subsystem-consumption-boundary
	 */
	public function testCustomerHasNoActionsAfterDsarMovedToOr(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$this->assertIsArray($manifest);
		$this->assertSame([], $manifest['actions']);
	}//end testCustomerHasNoActionsAfterDsarMovedToOr()

	/**
	 * Scenario: Unknown audience yields null (fail-closed).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testUnknownAudienceYieldsNull(): void {
		$this->assertNull($this->provider->getContribution(['audience' => 'supplier']));
		$this->assertNull($this->provider->getContribution([]));
		$this->assertNull($this->provider->getContribution(['subjectRef' => '00000000-0000-0000-0000-000000000009']));
		$this->assertNull($this->provider->getContribution(['audience' => '']));
	}//end testUnknownAudienceYieldsNull()

	/**
	 * Scenario: No endpoint actions in Wave 1 — create only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function testAllActionsAreCreateOnly(): void {
		foreach ([self::CLIENT_SUBJECT, self::CUSTOMER_SUBJECT] as $subject) {
			$manifest = $this->provider->getContribution($subject);
			$this->assertIsArray($manifest);
			foreach ($manifest['actions'] as $action) {
				$this->assertSame('create', $action['type'], 'Wave 1 forbids endpoint actions');
			}
		}
	}//end testAllActionsAreCreateOnly()

	/**
	 * Scenario: Client contactmoment ships a client-safe field projection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
	 */
	public function testClientContactmomentIsFieldProjected(): void {
		$manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
		$this->assertIsArray($manifest);

		$contactmoment = $this->indexById($manifest['collections'])['clientContactmoments'];
		$this->assertSame('ticket', $contactmoment['schema']);
		$this->assertSame(['ticketType' => 'interaction'], $contactmoment['filter'], 'Narrowed to logged interactions only');
		$this->assertSame('client', $contactmoment['scopeField']);
		$this->assertSame('clientId', $contactmoment['scopeClaim']);
		$this->assertArrayNotHasKey('minTrust', $contactmoment, 'B2B contactmoment carries no special-category data — no trust floor');
		$this->assertSame(
			['title', 'channel', 'outcome', 'occurredAt'],
			$contactmoment['fields'],
			'Only the client-safe interaction facts are projected'
		);

		// Staff-only / internal properties must never appear in the whitelist —
		// the CTI union fields merged in by register.d/70-cti.json, and (since the
		// ticket supertype merged all three kinds into one schema) the request /
		// complaint properties this collection must not carry.
		$forbidden = [
			'notes',
			'description',
			'channelMetadata',
			'duration',
			'assignee',
			'contactsUid',
			'parentTicket',
			'category',
			'complaintCategory',
			'status',
			'priority',
			'pipeline',
			'stage',
			'contact',
			'resolution',
			'recording_url',
			'disposition_notes',
			'agent_skill',
			'from_number',
			'to_number',
			'external_call_id',
		];
		foreach ($forbidden as $field) {
			$this->assertNotContains($field, $contactmoment['fields'], "Projection must never expose '{$field}'");
		}
	}//end testClientContactmomentIsFieldProjected()

	/**
	 * Scenario: Client complaints ship a client-safe field projection.
	 *
	 * This collection was unprojected while it read the narrow `complaint` schema.
	 * `ticket` is a supertype whose properties are a strict superset, so without a
	 * whitelist the unification alone would newly leak the contactmoment CTI
	 * internals, the internal `notes` and the back-office fields to the client.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
	 */
	public function testClientComplaintsAreFieldProjected(): void {
		$manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
		$this->assertIsArray($manifest);

		$complaints = $this->indexById($manifest['collections'])['clientComplaints'];
		$this->assertSame('ticket', $complaints['schema']);
		$this->assertSame(['ticketType' => 'complaint'], $complaints['filter']);
		$this->assertSame(
			['title', 'complaintCategory', 'status', 'description', 'occurredAt'],
			$complaints['fields'],
			'Only the client-safe complaint facts are projected'
		);

		// Supertype bleed-through: CTI call internals and contactmoment/back-office
		// properties ride along on `ticket` and must never reach a B2B client.
		$forbidden = [
			'notes',
			'channelMetadata',
			'duration',
			'assignee',
			'contactsUid',
			'parentTicket',
			'pipeline',
			'stage',
			'contact',
			'priority',
			'queue',
			'slaDeadline',
			'slaStatus',
			'recording_url',
			'disposition_notes',
			'agent_skill',
			'from_number',
			'to_number',
			'external_call_id',
		];
		foreach ($forbidden as $field) {
			$this->assertNotContains($field, $complaints['fields'], "Projection must never expose '{$field}'");
		}
	}//end testClientComplaintsAreFieldProjected()

	/**
	 * Scenario: Customer booking ships a customer-safe field projection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
	 */
	public function testCustomerBookingIsFieldProjected(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$this->assertIsArray($manifest);

		$booking = $this->indexById($manifest['collections'])['customerBookings'];
		$this->assertSame('booking', $booking['schema']);
		$this->assertSame('customerId', $booking['scopeField']);
		$this->assertSame('customerUid', $booking['scopeClaim']);
		$this->assertSame('substantial', $booking['minTrust']);
		$this->assertSame(
			['serviceId', 'startAt', 'endAt', 'status', 'notes', 'depositAmount', 'depositPaidAt'],
			$booking['fields'],
			'Only the customer-facing booking facts are projected'
		);

		// Staff-only, audit and provenance properties must never be whitelisted.
		$forbidden = [
			'internalNotes',
			'statusHistory',
			'resourceAssignments',
			'source',
			'cancelledBy',
			'cancellationReason',
			'previousBookingId',
			'confirmationSentAt',
			'reminderSentAt',
			'noShowFeeChargedAt',
		];
		foreach ($forbidden as $field) {
			$this->assertNotContains($field, $booking['fields'], "Projection must never expose '{$field}'");
		}
	}//end testCustomerBookingIsFieldProjected()

	/**
	 * Pin the scoping map + whitelists against the register JSONs at HEAD.
	 *
	 * Every declared scopeField, every projected collection field and every
	 * whitelisted action field must exist as a property on the declared schema
	 * in the shipped register config, so register drift breaks this test
	 * instead of silently emptying portal scopes or dropping a projected column.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
	 */
	public function testManifestMatchesShippedRegisterSchemas(): void {
		$schemaProperties = $this->loadRegisterSchemaProperties();

		foreach ([self::CLIENT_SUBJECT, self::CUSTOMER_SUBJECT] as $subject) {
			$manifest = $this->provider->getContribution($subject);
			$this->assertIsArray($manifest);

			foreach ($manifest['collections'] as $collection) {
				$schema = $collection['schema'];
				$this->assertArrayHasKey($schema, $schemaProperties, "Schema '{$schema}' must exist in the shipped register config");
				$this->assertContains(
					$collection['scopeField'],
					$schemaProperties[$schema],
					"scopeField '{$collection['scopeField']}' must exist on schema '{$schema}'"
				);

				// Field-projected collections: every whitelisted read field must
				// exist on the schema, else projection silently drops a column.
				foreach (($collection['fields'] ?? []) as $field) {
					$this->assertContains(
						$field,
						$schemaProperties[$schema],
						"Projected field '{$field}' must exist on schema '{$schema}'"
					);
				}

				// A narrowing filter over a property the schema does not have would
				// silently match nothing (or, worse, be ignored) — pin it too.
				foreach (array_keys(($collection['filter'] ?? [])) as $property) {
					$this->assertContains(
						$property,
						$schemaProperties[$schema],
						"Filter property '{$property}' must exist on schema '{$schema}'"
					);
				}
			}

			foreach ($manifest['actions'] as $action) {
				$schema = $action['schema'];
				$this->assertArrayHasKey($schema, $schemaProperties);
				foreach ($action['fields'] as $field) {
					$this->assertContains($field, $schemaProperties[$schema], "Whitelisted field '{$field}' must exist on schema '{$schema}'");
				}

				// Server-side defaults are written to OR verbatim — an unknown
				// property here would fail schema validation at create time.
				foreach (array_keys(($action['defaults'] ?? [])) as $property) {
					$this->assertContains(
						$property,
						$schemaProperties[$schema],
						"Default property '{$property}' must exist on schema '{$schema}'"
					);
				}
			}
		}
	}//end testManifestMatchesShippedRegisterSchemas()

	/**
	 * Scenario: every ticket-backed surface carries its kind discriminator.
	 *
	 * `ticket` is a supertype: without a narrowing `filter` a collection lists ALL
	 * kinds (a client's complaints and logged contactmomenten would surface in the
	 * requests list), and without a `defaults` stamp a create action either fails
	 * the schema's `required` ticketType or lets the client choose the kind. This
	 * is a generic guard: any ticket surface added later must declare both, and the
	 * value must be one of the schema's three enum members.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
	 */
	public function testTicketSurfacesCarryKindDiscriminator(): void {
		$kinds = ['request', 'complaint', 'interaction'];

		foreach ([self::CLIENT_SUBJECT, self::CUSTOMER_SUBJECT] as $subject) {
			$manifest = $this->provider->getContribution($subject);
			$this->assertIsArray($manifest);

			foreach ($manifest['collections'] as $collection) {
				if ($collection['schema'] !== 'ticket') {
					continue;
				}

				$id = $collection['id'];
				$this->assertArrayHasKey('filter', $collection, "Ticket collection '{$id}' must narrow by ticketType or it lists all three kinds");
				$this->assertArrayHasKey('ticketType', $collection['filter'], "Ticket collection '{$id}' must narrow by ticketType");
				$this->assertContains($collection['filter']['ticketType'], $kinds, "Ticket collection '{$id}' must narrow to a real ticketType");
			}

			foreach ($manifest['actions'] as $action) {
				if (($action['schema'] ?? '') !== 'ticket') {
					continue;
				}

				$id = $action['id'];
				$this->assertArrayHasKey('defaults', $action, "Ticket action '{$id}' must stamp ticketType server-side");
				$this->assertArrayHasKey('ticketType', $action['defaults'], "Ticket action '{$id}' must stamp ticketType server-side");
				$this->assertContains($action['defaults']['ticketType'], $kinds, "Ticket action '{$id}' must stamp a real ticketType");
				$this->assertNotContains('ticketType', $action['fields'], "Ticket action '{$id}' must not let the client choose the kind");
			}
		}
	}//end testTicketSurfacesCarryKindDiscriminator()

	/**
	 * Collect schema property names from the main register + fragments.
	 *
	 * Fragments union-merge into the main register at import time, so the
	 * property universe here is the union across all shipped files.
	 *
	 * @return array<string, array<int, string>> Map of schema name to property names.
	 */
	private function loadRegisterSchemaProperties(): array {
		$root = dirname(__DIR__, 3);
		$files = array_merge(
			[$root . '/lib/Settings/pipelinq_register.json'],
			glob($root . '/lib/Settings/register.d/*.json') ?: []
		);

		$properties = [];
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) === false) {
				continue;
			}

			$schemas = $decoded['components']['schemas'] ?? [];
			foreach ($schemas as $name => $schema) {
				$names = array_keys($schema['properties'] ?? []);
				$properties[$name] = array_values(array_unique(array_merge($properties[$name] ?? [], $names)));
			}
		}

		return $properties;
	}//end loadRegisterSchemaProperties()

	/**
	 * Index manifest entries by their id.
	 *
	 * @param array<int, array<string, mixed>> $entries Collections or actions.
	 *
	 * @return array<string, array<string, mixed>> Entries keyed by id.
	 */
	private function indexById(array $entries): array {
		$indexed = [];
		foreach ($entries as $entry) {
			$indexed[$entry['id']] = $entry;
		}

		return $indexed;
	}//end indexById()

	/**
	 * Sorted key list helper for exact-set assertions.
	 *
	 * @param array<string, mixed> $entries Indexed entries.
	 *
	 * @return array<int, string> Sorted keys.
	 */
	private function sortedKeys(array $entries): array {
		$keys = array_keys($entries);
		sort($keys);

		return $keys;
	}//end sortedKeys()
}//end class
