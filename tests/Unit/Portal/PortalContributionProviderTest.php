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
final class PortalContributionProviderTest extends TestCase
{
    /**
     * Server-derived subject fixture for the client audience (nil UUIDs).
     *
     * @var array<string, mixed>
     */
    private const CLIENT_SUBJECT = [
        'subjectRef'   => '00000000-0000-0000-0000-000000000001',
        'audience'     => 'client',
        'organisation' => '00000000-0000-0000-0000-000000000002',
        'trust'        => 'substantial',
    ];

    /**
     * Server-derived subject fixture for the customer audience (nil UUIDs).
     *
     * @var array<string, mixed>
     */
    private const CUSTOMER_SUBJECT = [
        'subjectRef'   => '00000000-0000-0000-0000-000000000003',
        'audience'     => 'customer',
        'organisation' => '00000000-0000-0000-0000-000000000002',
        'trust'        => 'substantial',
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
    protected function setUp(): void
    {
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
    public function testProviderIsPlainAndDependencyFree(): void
    {
        $reflection = new ReflectionClass(PortalContributionProvider::class);

        $this->assertSame(
            'OCA\\Pipelinq\\Portal\\PortalContributionProvider',
            $reflection->getName(),
            'Provider must live at the convention FQCN portaliq probes for'
        );
        $this->assertSame([], $reflection->getInterfaceNames(), 'Duck-typed: no implements clause allowed');
        $this->assertFalse($reflection->getParentClass(), 'Provider must not extend anything');
        $this->assertNull($reflection->getConstructor(), 'Provider must have no constructor dependencies');

        $source = (string) file_get_contents((string) $reflection->getFileName());
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
    public function testAudiencesOnBothContractVersions(): void
    {
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
    public function testClientCollectionsAreOrgScoped(): void
    {
        $manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
        $this->assertIsArray($manifest);

        $collections = $this->indexById($manifest['collections']);
        $this->assertSame(
            ['clientComplaints', 'clientContactmoments', 'clientContracts', 'clientRequests'],
            $this->sortedKeys($collections),
            'Client audience exposes request, complaint, contract and (field-projected) contactmoment'
        );

        $expected = [
            'clientRequests'       => ['request', 'client'],
            'clientComplaints'     => ['complaint', 'client'],
            'clientContracts'      => ['contract', 'clientRef'],
            'clientContactmoments' => ['contactmoment', 'client'],
        ];
        foreach ($expected as $id => [$schema, $scopeField]) {
            $this->assertSame('pipelinq', $collections[$id]['register']);
            $this->assertSame($schema, $collections[$id]['schema']);
            $this->assertSame($scopeField, $collections[$id]['scopeField']);
            $this->assertSame('clientId', $collections[$id]['scopeClaim'], 'Client surfaces scope by the clientId claim');
            $this->assertTrue($collections[$id]['listable']);
        }

        $schemas = array_column($manifest['collections'], 'schema');
        $this->assertContains('contactmoment', $schemas, 'contactmoment is now field-projected in, not excluded');
        $this->assertNotContains('booking', $schemas, 'booking is a customer surface, never in the client manifest');
    }//end testClientCollectionsAreOrgScoped()

    /**
     * Scenario: Client create actions are conservative whitelists.
     *
     * @return void
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function testClientActionsAreConservativeWhitelists(): void
    {
        $manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
        $this->assertIsArray($manifest);

        $actions = $this->indexById($manifest['actions']);
        $this->assertSame(['createComplaint', 'createRequest'], $this->sortedKeys($actions));

        foreach (['createRequest' => 'request', 'createComplaint' => 'complaint'] as $id => $schema) {
            $this->assertSame('create', $actions[$id]['type']);
            $this->assertSame('pipelinq', $actions[$id]['register']);
            $this->assertSame($schema, $actions[$id]['schema']);
            $this->assertSame(['title', 'description', 'category'], $actions[$id]['fields']);
        }

        $forbidden = ['status', 'assignee', 'assignedTo', 'priority', 'pipeline', 'queue', 'stage', 'slaDeadline', 'slaStatus'];
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
    public function testCustomerCollectionsAreSubjectScoped(): void
    {
        $manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
        $this->assertIsArray($manifest);

        $collections = $this->indexById($manifest['collections']);
        $this->assertSame(['customerAvgVerzoeken', 'customerBookings', 'customerLoyalty'], $this->sortedKeys($collections));

        $dsar = $collections['customerAvgVerzoeken'];
        $this->assertSame('pipelinq', $dsar['register']);
        $this->assertSame('avgVerzoek', $dsar['schema']);
        $this->assertSame('verzoekerContact', $dsar['scopeField']);
        $this->assertSame('contactId', $dsar['scopeClaim'], 'DSAR scopes by the pipelinq contact object UUID claim');
        $this->assertSame('substantial', $dsar['minTrust'], 'DSAR case files require eIDAS-substantial assurance');
        $this->assertTrue($dsar['listable']);

        $loyalty = $collections['customerLoyalty'];
        $this->assertSame('pipelinq', $loyalty['register']);
        $this->assertSame('klantLoyaltyAccount', $loyalty['schema']);
        $this->assertSame('klantId', $loyalty['scopeField']);
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
     * Scenario: DSAR intake is the only customer action.
     *
     * @return void
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function testCustomerActionIsDsarIntakeOnly(): void
    {
        $manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
        $this->assertIsArray($manifest);

        $actions = $this->indexById($manifest['actions']);
        $this->assertSame(['createAvgVerzoek'], $this->sortedKeys($actions));

        $intake = $actions['createAvgVerzoek'];
        $this->assertSame('create', $intake['type']);
        $this->assertSame('pipelinq', $intake['register']);
        $this->assertSame('avgVerzoek', $intake['schema']);
        $this->assertSame(['artikel', 'specifiekeVraag', 'scope'], $intake['fields']);

        $forbidden = [
            'status',
            'behandelaar',
            'verzoekerBsn',
            'verzoekerBsnGeverifieerd',
            'verzoekerContact',
            'wettelijkeTermijnVerloopt',
            'retentieTot',
            'uitkomst',
        ];
        foreach ($forbidden as $field) {
            $this->assertNotContains($field, $intake['fields'], "DSAR intake must never expose '{$field}'");
        }
    }//end testCustomerActionIsDsarIntakeOnly()

    /**
     * Scenario: Unknown audience yields null (fail-closed).
     *
     * @return void
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function testUnknownAudienceYieldsNull(): void
    {
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
    public function testAllActionsAreCreateOnly(): void
    {
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
    public function testClientContactmomentIsFieldProjected(): void
    {
        $manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);
        $this->assertIsArray($manifest);

        $contactmoment = $this->indexById($manifest['collections'])['clientContactmoments'];
        $this->assertSame('contactmoment', $contactmoment['schema']);
        $this->assertSame('client', $contactmoment['scopeField']);
        $this->assertSame('clientId', $contactmoment['scopeClaim']);
        $this->assertArrayNotHasKey('minTrust', $contactmoment, 'B2B contactmoment carries no special-category data — no trust floor');
        $this->assertSame(
            ['subject', 'channel', 'outcome', 'contactedAt'],
            $contactmoment['fields'],
            'Only the client-safe interaction facts are projected'
        );

        // Staff-only / internal properties must never appear in the whitelist,
        // including the CTI union fields merged in by register.d/70-cti.json.
        $forbidden = [
            'notes',
            'summary',
            'channelMetadata',
            'duration',
            'agent',
            'contactsUid',
            'request',
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
     * Scenario: Customer booking ships a customer-safe field projection.
     *
     * @return void
     *
     * @spec openspec/changes/portal-projected-collections/specs/portal-contribution/spec.md
     */
    public function testCustomerBookingIsFieldProjected(): void
    {
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
    public function testManifestMatchesShippedRegisterSchemas(): void
    {
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
            }

            foreach ($manifest['actions'] as $action) {
                $schema = $action['schema'];
                $this->assertArrayHasKey($schema, $schemaProperties);
                foreach ($action['fields'] as $field) {
                    $this->assertContains($field, $schemaProperties[$schema], "Whitelisted field '{$field}' must exist on schema '{$schema}'");
                }
            }
        }
    }//end testManifestMatchesShippedRegisterSchemas()

    /**
     * Collect schema property names from the main register + fragments.
     *
     * Fragments union-merge into the main register at import time, so the
     * property universe here is the union across all shipped files.
     *
     * @return array<string, array<int, string>> Map of schema name to property names.
     */
    private function loadRegisterSchemaProperties(): array
    {
        $root  = dirname(__DIR__, 3);
        $files = array_merge(
            [$root . '/lib/Settings/pipelinq_register.json'],
            glob($root . '/lib/Settings/register.d/*.json') ?: []
        );

        $properties = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
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
    private function indexById(array $entries): array
    {
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
    private function sortedKeys(array $entries): array
    {
        $keys = array_keys($entries);
        sort($keys);

        return $keys;
    }//end sortedKeys()
}//end class
