<?php

/**
 * Unit tests for the KCC contact-centre register model (kcc-schemaorg-consolidation).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-schemaorg-consolidation/specs/kcc-schemaorg-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the contact-centre vocabulary contract, the additive-merge constraint
 * and the seeded demo dataset over the merged Pipelinq register.
 *
 * The register is read the way OpenRegister reads it: the monolith
 * `pipelinq_register.json` deep-merged with every `register.d/*.json` fragment
 * in sorted filename order, with `components.objects` and the register's
 * `schemas[]` membership list unioned rather than replaced (ADR-037, mirroring
 * ConfigFileLoaderService).
 */
class KccContactCentreRegisterTest extends TestCase
{

    /**
     * The merged register configuration.
     *
     * @var array<string, mixed>
     */
    private array $register;

    /**
     * Schemas of the merged register, keyed by name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $schemas;

    /**
     * The ratified schema.org marker per contact-centre schema
     * (kcc-schemaorg-model: "Each contact-centre schema carries its ratified type").
     *
     * `routingRule` is deliberately absent: it is configuration, not a
     * schema.org thing, and MUST carry no marker.
     *
     * @var array<string, string>
     */
    private const RATIFIED_MARKERS = [
        'contactmoment'         => 'schema:CommunicateAction',
        'complaint'             => 'schema:CommunicateAction',
        'callPlan'              => 'schema:Schedule',
        'callTransfer'          => 'schema:TransferAction',
        'contactSentiment'      => 'schema:Rating',
        'quickAction'           => 'schema:Action',
        'agentProfile'          => 'schema:Person',
        'task'                  => 'schema:Action',
        'queue'                 => 'schema:ItemList',
        'skill'                 => 'schema:DefinedTerm',
        'complaintCategory'     => 'schema:DefinedTerm',
        'complaintDisposition'  => 'schema:Review',
        'hearing'               => 'schema:Event',
    ];

    /**
     * Schemas the contact-centre model owns, marker or not.
     *
     * @var array<int, string>
     */
    private const CONTACT_CENTRE_SCHEMAS = [
        'contactmoment',
        'complaint',
        'callPlan',
        'callTransfer',
        'contactSentiment',
        'quickAction',
        'routingRule',
        'agentProfile',
        'task',
        'queue',
        'skill',
        'complaintCategory',
        'complaintDisposition',
        'hearing',
    ];

    /**
     * Dutch property names that MUST NOT be stored anywhere in the register
     * (ADR-001: "fn, not naam").
     *
     * @var array<int, string>
     */
    private const BANNED_DUTCH_PROPERTIES = [
        'klachtnummer',
        'klager',
        'onderwerp',
        'omschrijving',
        'ontvangstdatum',
        'ontvangstkanaal',
        'categorie',
        'betrokkenMedewerker',
        'betrokkenAfdeling',
        'behandelaar',
        'prioriteit',
        'ontvangstbevestigingDeadline',
        'afhandelDeadline',
        'verdagingMogelijk',
        'verdagingJustificatie',
        'geescaleerdeZaak',
        'hoorgespreksWaiver',
        'bellerIdentificatie',
        'kccMedewerkerId',
        'geidentificeerdeBurgerId',
        'identificatieMethode',
        'identificatieScore',
        'samenvatting',
        'kanaal',
        'richting',
        'startTijd',
        'eindTijd',
        'duurSeconden',
        'gerelateerdeZaken',
        'nieuweZaakIds',
        'transcriptie',
        'transferNaar',
        'naam',
        'volgorde',
        'permissies',
        'vereisteContext',
        'actieType',
        'targetZaaktype',
        'routeringStappen',
        'openingstijden',
        'terugvalActie',
        'triggerNummer',
        'medewerkerId',
        'expertises',
        'huidigeWachtrijLengte',
        'gemiddeldeBehandelduur',
        'gespreksInProgress',
        'laatsteUpdate',
        'doorverbindingsReden',
        'contextOverdracht',
        'vanMedewerkerId',
        'naarMedewerkerId',
        'naarWachtrij',
        'geaccepteerd',
        'acceptatieTijd',
        'afgekeurdReden',
        'triggerWoorden',
        'transcriptieSnippet',
        'escalatieAanbevolen',
        'escalatieLevel',
        'oordeel',
        'toelichting',
        'maatregelen',
        'afsluitdatum',
        'afsluitbrief',
        'goedkeurder',
        'goedkeuringStatus',
    ];

    /**
     * Enum values that MUST NOT survive on a zero-row contact-centre schema.
     *
     * `task` is excluded on purpose: its Dutch type/status/priority values back
     * live objects and are anglicised, with a Repair-step row migration, by the
     * chained `kcc-task-enum-migration` change.
     *
     * @var array<int, string>
     */
    private const BANNED_DUTCH_ENUM_VALUES = [
        'telefoon',
        'balie',
        'brief',
        'webformulier',
        'social_media',
        'inkomend',
        'uitgaand',
        'afgehandeld',
        'doorverbonden',
        'terugbelverzoek',
        'vervolgactie',
        'opgelost',
        'doorverwezen',
        'informatieverzoek',
        'statusverzoek',
        'klacht',
        'melding',
        'nieuwe_aanvraag',
        'doorverbinding',
        'ontvangen',
        'ontvangst_bevestigd',
        'in_behandeling',
        'hoorgesprek_gepland',
        'hoorgesprek_afgerond',
        'ingetrokken',
        'beschikbaar',
        'in_gesprek',
        'afwezig',
        'niet_storen',
        'positief',
        'neutraal',
        'negatief',
        'boos',
        'geen',
        'geel',
        'oranje',
        'rood',
        'gegrond',
        'deels_gegrond',
        'ongegrond',
        'niet_ontvankelijk',
        'wacht_op_goedkeuring',
        'goedgekeurd',
        'afgekeurd',
        'fysiek',
        'telefonisch',
        'videogesprek',
        'bsn_verificatie',
        'identificatievragen',
        'niet_geidentificeerd',
        'status_geven',
        'nieuwe_zaak',
        'klacht_registreren',
        'doorverbinden',
        'bel_terug_inplannen',
        'email_sturen',
        'kopie_document_sturen',
    ];

    /**
     * The properties, and their types, that the four data-bearing schemas
     * declared before this change (measured at origin/development).
     *
     * Live-row counts at the time of writing: `task` 10, `agentProfile` 5,
     * `queue` 153, `skill` 3. The merge into them MUST be strictly additive:
     * no property removed, renamed or re-typed out from under those rows.
     *
     * @var array<string, array<string, string>>
     */
    private const DATA_BEARING_BASELINE = [
        'agentProfile' => [
            'userId'        => 'string',
            'skills'        => 'array',
            'maxConcurrent' => 'integer',
            'isAvailable'   => 'boolean',
        ],
        'task'         => [
            'type'                 => 'string',
            'subject'              => 'string',
            'description'          => 'string',
            'status'               => 'string',
            'priority'             => 'string',
            'deadline'             => 'string',
            'assigneeUserId'       => 'string',
            'assigneeGroupId'      => 'string',
            'clientId'             => 'string',
            'requestId'            => 'string',
            'contactMomentSummary' => 'string',
            'callbackPhoneNumber'  => 'string',
            'preferredTimeSlot'    => 'string',
            'createdBy'            => 'string',
            'completedAt'          => 'string',
            'resultText'           => 'string',
            'attempts'             => 'array',
        ],
        'queue'        => [
            'title'          => 'string',
            'description'    => 'string',
            'categories'     => 'array',
            'isActive'       => 'boolean',
            'maxCapacity'    => 'integer',
            'sortOrder'      => 'integer',
            'assignedAgents' => 'array',
        ],
        'skill'        => [
            'title'       => 'string',
            'description' => 'string',
            'categories'  => 'array',
            'isActive'    => 'boolean',
        ],
    ];

    /**
     * The seed dataset the register must ship, per schema.
     *
     * @var array<string, int>
     */
    private const EXPECTED_SEED_COUNTS = [
        'routingRule'       => 3,
        'agentProfile'      => 3,
        'callPlan'          => 2,
        'quickAction'       => 5,
        'complaintCategory' => 7,
        'callTransfer'      => 1,
        'contactSentiment'  => 1,
        'complaint'         => 1,
    ];

    /**
     * Absolute path to the app root.
     *
     * @return string The app root path.
     */
    private static function appRoot(): string
    {
        return dirname(__DIR__, 3);
    }//end appRoot()

    /**
     * Load the register the way OpenRegister does: the monolith deep-merged
     * with every `register.d/*.json` fragment, in sorted filename order.
     *
     * @return array<string, mixed> The merged register configuration.
     */
    private static function loadMergedRegister(): array
    {
        $base = json_decode((string) file_get_contents(self::appRoot().'/lib/Settings/pipelinq_register.json'), true);
        if (is_array($base) === false) {
            return [];
        }

        $fragments = glob(self::appRoot().'/lib/Settings/register.d/*.json');
        if ($fragments === false) {
            $fragments = [];
        }

        sort($fragments);

        foreach ($fragments as $fragment) {
            $decoded = json_decode((string) file_get_contents($fragment), true);
            if (is_array($decoded) === false) {
                continue;
            }

            $base = self::deepMerge(base: $base, override: $decoded, path: '');
        }

        return $base;
    }//end loadMergedRegister()

    /**
     * Deep-merge a fragment onto the base, unioning the additive list paths
     * (`components.objects`, `components.registers.*.schemas`) exactly as
     * ConfigFileLoaderService does.
     *
     * @param array<string, mixed> $base     The base configuration.
     * @param array<string, mixed> $override The fragment.
     * @param string               $path     The current dotted path.
     *
     * @return array<string, mixed> The merged configuration.
     */
    private static function deepMerge(array $base, array $override, string $path): array
    {
        foreach ($override as $key => $value) {
            $childPath = $key;
            if ($path !== '') {
                $childPath = $path.'.'.$key;
            }

            $existing = ($base[$key] ?? null);

            if (is_array($existing) === true && is_array($value) === true) {
                if (self::isAdditiveListPath(path: $childPath) === true) {
                    $base[$key] = array_merge($existing, $value);
                    continue;
                }

                if (array_is_list($existing) === false || array_is_list($value) === false) {
                    $base[$key] = self::deepMerge(base: $existing, override: $value, path: $childPath);
                    continue;
                }
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMerge()

    /**
     * Whether a dotted path names an additive (unioned) list.
     *
     * @param string $path The dotted path.
     *
     * @return bool True when the list is unioned rather than replaced.
     */
    private static function isAdditiveListPath(string $path): bool
    {
        if ($path === 'components.objects') {
            return true;
        }

        return preg_match('/^components\.registers\.[^.]+\.schemas$/', $path) === 1;
    }//end isAdditiveListPath()

    /**
     * Build the merged register once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->register = self::loadMergedRegister();
        $this->assertNotEmpty(actual: $this->register, message: 'The merged register must not be empty.');

        $this->schemas = ($this->register['components']['schemas'] ?? []);
        $this->assertNotEmpty(actual: $this->schemas, message: 'The merged register must declare schemas.');
    }//end setUp()

    /**
     * Recursively collect every value stored under a given key.
     *
     * @param mixed  $node The node to walk.
     * @param string $key  The key to collect.
     *
     * @return array<int, mixed> Every value found under that key.
     */
    private static function collectByKey(mixed $node, string $key): array
    {
        if (is_array($node) === false) {
            return [];
        }

        $found = [];
        foreach ($node as $childKey => $childValue) {
            if ($childKey === $key) {
                $found[] = $childValue;
            }

            $found = array_merge($found, self::collectByKey(node: $childValue, key: $key));
        }

        return $found;
    }//end collectByKey()

    /**
     * Task 7.1 — every ratified contact-centre schema carries its ratified,
     * `schema:`-prefixed marker at schema level (a sibling of title/properties),
     * which is the only placement OpenRegister's JsonLdContextService reads.
     *
     * @return void
     */
    public function testEveryContactCentreSchemaCarriesItsRatifiedMarker(): void
    {
        foreach (self::RATIFIED_MARKERS as $name => $expected) {
            $schema = ($this->schemas[$name] ?? null);
            $this->assertIsArray(actual: $schema, message: "Schema '{$name}' must be declared in the register.");

            $this->assertArrayHasKey(
                key: 'x-schema-org',
                array: $schema,
                message: "Schema '{$name}' must declare a schema-level x-schema-org marker."
            );

            $this->assertSame(
                expected: $expected,
                actual: $schema['x-schema-org'],
                message: "Schema '{$name}' must be typed {$expected}."
            );

            // The marker must sit beside `properties`, not inside it.
            $this->assertArrayHasKey(
                key: 'properties',
                array: $schema,
                message: "Schema '{$name}' marker must be a sibling of properties."
            );
        }//end foreach

        // complaint must have shed schema:Message; schema:ComplainAction does
        // not exist in the schema.org vocabulary and must appear nowhere.
        $this->assertNotSame(
            expected: 'schema:Message',
            actual: $this->schemas['complaint']['x-schema-org'],
            message: 'complaint must no longer be typed schema:Message.'
        );

        $registerJson = (string) file_get_contents(self::appRoot().'/lib/Settings/pipelinq_register.json');
        $this->assertStringNotContainsString(
            needle: 'schema:ComplainAction',
            haystack: $registerJson,
            message: 'schema:ComplainAction does not exist in schema.org and must not be referenced.'
        );
    }//end testEveryContactCentreSchemaCarriesItsRatifiedMarker()

    /**
     * Task 7.1 — routingRule is configuration and deliberately carries no
     * schema.org type.
     *
     * @return void
     */
    public function testRoutingRuleCarriesNoSchemaOrgMarker(): void
    {
        $routingRule = ($this->schemas['routingRule'] ?? null);
        $this->assertIsArray(actual: $routingRule, message: 'routingRule must be declared in the register.');

        $this->assertArrayNotHasKey(
            key: 'x-schema-org',
            array: $routingRule,
            message: 'routingRule is configuration and must carry no x-schema-org marker.'
        );
    }//end testRoutingRuleCarriesNoSchemaOrgMarker()

    /**
     * Task 7.1 — no marker anywhere in the register is bare (`Person`) or
     * foreign-prefixed (`foaf:Person`): `expandSchemaOrgMarker()` expands only
     * `schema:`-prefixed CURIEs and absolute IRIs, and silently drops the rest,
     * so such a marker would be dead metadata that reads as live.
     *
     * @return void
     */
    public function testNoMarkerIsBareOrForeignPrefixed(): void
    {
        $checked = 0;

        foreach ($this->schemas as $name => $schema) {
            $marker = ($schema['x-schema-org'] ?? null);
            if ($marker === null) {
                continue;
            }

            $this->assertIsString(actual: $marker, message: "Schema '{$name}' marker must be a string.");

            $isCurie = str_starts_with($marker, 'schema:');
            $isIri   = (str_starts_with($marker, 'https://schema.org/') === true
                || str_starts_with($marker, 'http://schema.org/') === true);

            $this->assertTrue(
                condition: ($isCurie === true || $isIri === true),
                message: "Schema '{$name}' marker '{$marker}' must be a schema: CURIE or an absolute schema.org IRI; "
                    .'a bare name or a foreign prefix is silently dropped by expandSchemaOrgMarker().'
            );

            $checked++;
        }//end foreach

        $this->assertGreaterThan(expected: 0, actual: $checked, message: 'Expected at least one x-schema-org marker.');
    }//end testNoMarkerIsBareOrForeignPrefixed()

    /**
     * Task 7.1 — no schema definition leaves a schema.org CURIE in `@type`.
     *
     * `@type` inside a schema definition is consumed by SchemaImport's
     * DialectDetector as an *inbound* JSON-LD dialect signal; a schema.org
     * CURIE parked there would be misread. The marker belongs in
     * `x-schema-org` and nowhere else.
     *
     * @return void
     */
    public function testNoSchemaDefinitionLeavesASchemaOrgCurieInAtType(): void
    {
        foreach ($this->schemas as $name => $schema) {
            foreach (self::collectByKey(node: $schema, key: '@type') as $value) {
                $this->assertIsNotArray(actual: $value, message: "Schema '{$name}' @type must not be an array.");

                $this->assertStringNotContainsString(
                    needle: 'schema:',
                    haystack: (string) $value,
                    message: "Schema '{$name}' leaves a schema.org CURIE in @type; DialectDetector would misread it."
                );

                $this->assertStringNotContainsString(
                    needle: 'schema.org',
                    haystack: (string) $value,
                    message: "Schema '{$name}' leaves a schema.org IRI in @type; DialectDetector would misread it."
                );
            }

            // x-schema-org-type is decorative and never read: it must not be used.
            $this->assertArrayNotHasKey(
                key: 'x-schema-org-type',
                array: $schema,
                message: "Schema '{$name}' must not use the decorative x-schema-org-type key."
            );
        }//end foreach
    }//end testNoSchemaDefinitionLeavesASchemaOrgCurieInAtType()

    /**
     * Task 7.1 — no contact-centre schema stores a Dutch property name
     * (ADR-001). Dutch and ZGW field names are produced by the mapping layer.
     *
     * @return void
     */
    public function testNoContactCentreSchemaDeclaresADutchPropertyName(): void
    {
        foreach (self::CONTACT_CENTRE_SCHEMAS as $name) {
            $schema = ($this->schemas[$name] ?? null);
            $this->assertIsArray(actual: $schema, message: "Schema '{$name}' must be declared in the register.");

            $properties = array_keys(($schema['properties'] ?? []));

            foreach (self::BANNED_DUTCH_PROPERTIES as $banned) {
                $this->assertNotContains(
                    needle: $banned,
                    haystack: $properties,
                    message: "Schema '{$name}' must not store the Dutch property name '{$banned}' (ADR-001)."
                );
            }
        }//end foreach
    }//end testNoContactCentreSchemaDeclaresADutchPropertyName()

    /**
     * Task 7.1 — every enum on the schemas added by this change, plus
     * `contactmoment` and `complaint` (both zero-row, hence anglicised here),
     * is English-valued.
     *
     * `task` is excluded: its Dutch enum values back live objects and are
     * migrated by the chained `kcc-task-enum-migration` change.
     *
     * @return void
     */
    public function testZeroRowAndNewSchemaEnumsAreEnglish(): void
    {
        $schemasUnderTest = [
            'contactmoment',
            'complaint',
            'callPlan',
            'callTransfer',
            'contactSentiment',
            'quickAction',
            'routingRule',
            'agentProfile',
            'complaintCategory',
            'complaintDisposition',
            'hearing',
        ];

        $checked = 0;

        foreach ($schemasUnderTest as $name) {
            $schema = ($this->schemas[$name] ?? null);
            $this->assertIsArray(actual: $schema, message: "Schema '{$name}' must be declared in the register.");

            foreach (self::collectByKey(node: $schema, key: 'enum') as $enum) {
                $this->assertIsArray(actual: $enum, message: "Schema '{$name}' enum must be an array.");

                foreach ($enum as $value) {
                    $this->assertNotContains(
                        needle: (string) $value,
                        haystack: self::BANNED_DUTCH_ENUM_VALUES,
                        message: "Schema '{$name}' declares the Dutch enum value '{$value}'; contact-centre enum "
                            .'values must be English (ADR-001).'
                    );

                    $checked++;
                }
            }
        }//end foreach

        $this->assertGreaterThan(expected: 0, actual: $checked, message: 'Expected at least one enum value.');

        // The two anglicisations this change performs, asserted positively.
        $this->assertSame(
            expected: ['phone', 'email', 'counter', 'chat', 'social', 'letter', 'sms'],
            actual: $this->schemas['contactmoment']['properties']['channel']['enum'],
            message: 'contactmoment.channel must offer the English channel values.'
        );

        $this->assertSame(
            expected: ['handled', 'transferred', 'callback_requested', 'follow_up', 'resolved', 'referred'],
            actual: $this->schemas['contactmoment']['properties']['outcome']['enum'],
            message: 'contactmoment.outcome must offer the English outcome values.'
        );

        $this->assertSame(
            expected: [
                'new',
                'acknowledged',
                'in_progress',
                'hearing_scheduled',
                'hearing_completed',
                'resolved',
                'rejected',
                'withdrawn',
            ],
            actual: $this->schemas['complaint']['properties']['status']['enum'],
            message: 'complaint.status must offer the English Awb chapter-9 lifecycle states.'
        );
    }//end testZeroRowAndNewSchemaEnumsAreEnglish()

    /**
     * Task 7.1 — the eight new schemas land in the dedicated contact-centre
     * fragment and are listed in the register's schema membership.
     *
     * @return void
     */
    public function testNewSchemasAreDeclaredInTheContactCentreFragment(): void
    {
        $fragmentPath = self::appRoot().'/lib/Settings/register.d/71-kcc-contactcentre.json';
        $this->assertFileExists(filename: $fragmentPath, message: 'The contact-centre fragment must exist.');

        $fragment = json_decode((string) file_get_contents($fragmentPath), true);
        $this->assertIsArray(actual: $fragment, message: 'The contact-centre fragment must be valid JSON.');

        $expected = [
            'callPlan',
            'callTransfer',
            'contactSentiment',
            'quickAction',
            'routingRule',
            'complaintCategory',
            'complaintDisposition',
            'hearing',
        ];

        $declared   = array_keys(($fragment['components']['schemas'] ?? []));
        $membership = ($this->register['components']['registers']['pipelinq']['schemas'] ?? []);

        foreach ($expected as $name) {
            $this->assertContains(
                needle: $name,
                haystack: $declared,
                message: "The contact-centre fragment must declare '{$name}'."
            );

            $this->assertContains(
                needle: $name,
                haystack: $membership,
                message: "'{$name}' must be listed in the pipelinq register's schema membership."
            );
        }
    }//end testNewSchemasAreDeclaredInTheContactCentreFragment()

    /**
     * Task 7.2 — the merge into the four data-bearing schemas is strictly
     * additive: every property they declared before this change is still
     * declared, with an unchanged type.
     *
     * @return void
     */
    public function testDataBearingSchemasAreExtendedAdditively(): void
    {
        foreach (self::DATA_BEARING_BASELINE as $name => $baseline) {
            $schema = ($this->schemas[$name] ?? null);
            $this->assertIsArray(actual: $schema, message: "Schema '{$name}' must be declared in the register.");

            $properties = ($schema['properties'] ?? []);

            foreach ($baseline as $property => $type) {
                $this->assertArrayHasKey(
                    key: $property,
                    array: $properties,
                    message: "Schema '{$name}' must still declare '{$property}': live objects populate it."
                );

                $this->assertSame(
                    expected: $type,
                    actual: ($properties[$property]['type'] ?? null),
                    message: "Schema '{$name}.{$property}' must keep its type '{$type}': live objects populate it."
                );
            }
        }//end foreach
    }//end testDataBearingSchemasAreExtendedAdditively()

    /**
     * Task 7.2 — `agentProfile.isAvailable` survives the merge, deprecated but
     * present: five live objects populate it and `skill-routing` reads it.
     *
     * @return void
     */
    public function testAgentProfileIsAvailableSurvivesAlongsideAvailabilityStatus(): void
    {
        $properties = ($this->schemas['agentProfile']['properties'] ?? []);

        $this->assertArrayHasKey(
            key: 'isAvailable',
            array: $properties,
            message: 'agentProfile.isAvailable must be retained: live objects populate it and skill-routing reads it.'
        );

        $this->assertSame(
            expected: 'boolean',
            actual: $properties['isAvailable']['type'],
            message: 'agentProfile.isAvailable must keep its boolean type.'
        );

        $this->assertTrue(
            condition: ($properties['isAvailable']['deprecated'] ?? false),
            message: 'agentProfile.isAvailable must be marked deprecated now that availabilityStatus supersedes it.'
        );

        $this->assertArrayHasKey(
            key: 'availabilityStatus',
            array: $properties,
            message: 'agentProfile must gain the richer availabilityStatus enum.'
        );

        $this->assertSame(
            expected: ['available', 'busy', 'wrap_up', 'break', 'away', 'do_not_disturb', 'offline'],
            actual: $properties['availabilityStatus']['enum'],
            message: 'agentProfile.availabilityStatus must offer the ratified English availability values.'
        );
    }//end testAgentProfileIsAvailableSurvivesAlongsideAvailabilityStatus()

    /**
     * Task 7.2 — the callback merge lands on `task` rather than a second
     * callback schema, and `contactmoment` / `complaint` absorb the contact and
     * complaint models rather than being duplicated (kcc-schemaorg-model: "New
     * contact-centre concepts extend existing schemas").
     *
     * @return void
     */
    public function testNoSecondContactCentreModelIsDeclared(): void
    {
        foreach (['kccAgent', 'callbackRequest', 'specialistBeschikbaarheid', 'belplan', 'doorverbinding', 'klantSentiment', 'kccQuickAction', 'customerContact'] as $forbidden) {
            $this->assertArrayNotHasKey(
                key: $forbidden,
                array: $this->schemas,
                message: "'{$forbidden}' must not be re-created: the concept lives on an existing Pipelinq schema."
            );
        }

        $taskProperties = ($this->schemas['task']['properties'] ?? []);

        foreach (['contactMoment', 'scheduledFor', 'nextAttemptAt', 'callbackPhoneNumber', 'preferredTimeSlot', 'attempts'] as $property) {
            $this->assertArrayHasKey(
                key: $property,
                array: $taskProperties,
                message: "task must carry the callback property '{$property}': callbacks live on task."
            );
        }

        $this->assertSame(
            expected: 'contactmoment',
            actual: ($taskProperties['contactMoment']['$ref'] ?? null),
            message: 'task.contactMoment must reference the contactmoment schema.'
        );
    }//end testNoSecondContactCentreModelIsDeclared()

    /**
     * Task 7.1 / 3.2 — the complaint model is declarative: the Awb chapter-9
     * state machine is declared as lifecycle transitions, and `category` is a
     * reference to a `complaintCategory` object rather than an enum.
     *
     * @return void
     */
    public function testComplaintLifecycleAndCategoryAreDeclarative(): void
    {
        $complaint = $this->schemas['complaint'];
        $lifecycle = ($complaint['configuration']['x-openregister-lifecycle'] ?? []);

        $this->assertSame(
            expected: 'status',
            actual: ($lifecycle['field'] ?? null),
            message: 'The complaint lifecycle must be driven by status.'
        );

        $transitions = array_keys(($lifecycle['transitions'] ?? []));

        foreach (['acknowledge', 'start', 'scheduleHearing', 'completeHearing', 'resolve', 'reject', 'withdraw'] as $transition) {
            $this->assertContains(
                needle: $transition,
                haystack: $transitions,
                message: "The complaint lifecycle must declare the '{$transition}' transition (Awb chapter 9)."
            );
        }

        // The direct in_progress → resolved path (hearing waived) must exist.
        $this->assertContains(
            needle: 'in_progress',
            haystack: ($lifecycle['transitions']['resolve']['from'] ?? []),
            message: 'A complaint whose hearing is waived must resolve directly from in_progress.'
        );

        // Every state may be withdrawn.
        $this->assertContains(
            needle: 'hearing_scheduled',
            haystack: ($lifecycle['transitions']['withdraw']['from'] ?? []),
            message: 'A complaint must be withdrawable from any active state.',
        );

        $category = ($complaint['properties']['category'] ?? []);

        $this->assertArrayNotHasKey(
            key: 'enum',
            array: $category,
            message: 'complaint.category must no longer be an enum: it references a complaintCategory object.'
        );

        $this->assertSame(
            expected: 'complaintCategory',
            actual: ($category['$ref'] ?? null),
            message: 'complaint.category must reference the complaintCategory schema.'
        );

        // Awb deadline + hearing fields.
        foreach (['complaintNumber', 'receivedAt', 'acknowledgementDeadline', 'slaDeadline', 'extensionAvailable', 'extensionJustification', 'subjectEmployee', 'subjectDepartment', 'escalatedCase', 'hearingWaiver'] as $property) {
            $this->assertArrayHasKey(
                key: $property,
                array: $complaint['properties'],
                message: "complaint must carry the Awb property '{$property}'."
            );
        }
    }//end testComplaintLifecycleAndCategoryAreDeclarative()

    /**
     * Task 7.1 / 3.1 — contact-moment read logging is declarative, and its
     * processing subject points at the English `contact` reference.
     *
     * @return void
     */
    public function testContactMomentReadLoggingIsDeclarative(): void
    {
        $processing = ($this->schemas['contactmoment']['configuration']['x-openregister-processing'] ?? []);

        $this->assertTrue(
            condition: ($processing['logReads'] ?? false),
            message: 'contactmoment must opt in to AVG read logging (x-openregister-processing.logReads).'
        );

        $this->assertNotEmpty(
            actual: ($processing['attribution'] ?? []),
            message: 'contactmoment read logging must declare an attribution.'
        );

        $subjectIdFields = ($processing['subjectIdFields'] ?? []);
        $this->assertNotEmpty(actual: $subjectIdFields, message: 'contactmoment must declare its processing subject.');

        $this->assertSame(
            expected: ['contact'],
            actual: array_values($subjectIdFields),
            message: 'subjectIdFields must point at the English `contact` reference, not an opaque citizen id.'
        );

        $this->assertSame(
            expected: 'contact',
            actual: ($this->schemas['contactmoment']['properties']['contact']['$ref'] ?? null),
            message: 'contactmoment.contact must reference the contact schema.'
        );
    }//end testContactMomentReadLoggingIsDeclarative()

    /**
     * Task 7.1 / 3.5 — the contact-centre roll-ups are declared as
     * aggregations, not implemented as an analytics service (ADR-031).
     * Mirrors OpenRegister's AggregationAnnotationValidator for cross-schema
     * specs.
     *
     * @return void
     */
    public function testContactCentreRollUpsAreDeclaredAsAggregations(): void
    {
        $validMetrics = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

        foreach (['agentProfile', 'queue'] as $name) {
            $aggregations = ($this->schemas[$name]['x-openregister-aggregations'] ?? []);
            $this->assertNotEmpty(
                actual: $aggregations,
                message: "Schema '{$name}' must declare its contact-centre roll-ups as aggregations (ADR-031)."
            );

            foreach (['contactMomentVolume', 'firstTimeFixRate', 'averageHandlingSeconds'] as $aggregation) {
                $this->assertArrayHasKey(
                    key: $aggregation,
                    array: $aggregations,
                    message: "Schema '{$name}' must declare the '{$aggregation}' aggregation."
                );
            }

            foreach ($aggregations as $aggregation => $spec) {
                $this->assertNotEmpty(
                    actual: ($spec['from'] ?? ''),
                    message: "Aggregation '{$name}.{$aggregation}' must name the schema it aggregates over."
                );

                $metric = (string) ($spec['metric'] ?? $spec['select'] ?? '');
                $this->assertContains(
                    needle: $metric,
                    haystack: $validMetrics,
                    message: "Aggregation '{$name}.{$aggregation}' metric '{$metric}' is not a valid metric."
                );

                if (in_array($metric, ['sum', 'avg', 'min', 'max', 'count_distinct'], true) === true) {
                    $this->assertNotEmpty(
                        actual: ($spec['field'] ?? ''),
                        message: "Aggregation '{$name}.{$aggregation}' with metric '{$metric}' requires a field."
                    );
                }

                $this->assertIsArray(
                    actual: ($spec['where'] ?? $spec['filter'] ?? []),
                    message: "Aggregation '{$name}.{$aggregation}' where/filter must be a map."
                );
            }//end foreach
        }//end foreach
    }//end testContactCentreRollUpsAreDeclaredAsAggregations()

    /**
     * Task 7.3 — the seed dataset materialises for every contact-centre schema.
     *
     * @return void
     */
    public function testSeedObjectsMaterialiseForEveryContactCentreSchema(): void
    {
        $objects = ($this->register['components']['objects'] ?? []);
        $this->assertNotEmpty(actual: $objects, message: 'The register must seed objects.');

        $bySchema = [];
        foreach ($objects as $object) {
            $schema = ($object['@self']['schema'] ?? '');
            if (isset($bySchema[$schema]) === false) {
                $bySchema[$schema] = [];
            }

            $bySchema[$schema][] = $object;
        }

        foreach (self::EXPECTED_SEED_COUNTS as $schema => $expected) {
            $this->assertArrayHasKey(
                key: $schema,
                array: $bySchema,
                message: "The register must seed '{$schema}' objects."
            );

            $this->assertCount(
                expectedCount: $expected,
                haystack: $bySchema[$schema],
                message: "The register must seed exactly {$expected} '{$schema}' objects."
            );
        }

        // Contact moments: the two contact-centre seeds, plus the pre-existing
        // reporting seeds, all of which must now carry English enum values.
        $this->assertGreaterThanOrEqual(
            expected: 2,
            actual: count(($bySchema['contactmoment'] ?? [])),
            message: 'The register must seed at least the two contact-centre contact moments.'
        );

        // Two callback tasks (type terugbelverzoek — anglicised by chain spec 2).
        $callbacks = array_filter(
            ($bySchema['task'] ?? []),
            static fn (array $task): bool => (($task['type'] ?? '') === 'terugbelverzoek')
        );

        $this->assertCount(
            expectedCount: 2,
            haystack: $callbacks,
            message: 'The register must seed exactly two callback tasks.'
        );
    }//end testSeedObjectsMaterialiseForEveryContactCentreSchema()

    /**
     * Task 7.3 — every seed object is keyed by a stable slug, and no two seed
     * objects share a slug or an id, so a second import matches rather than
     * duplicates. This is the property that makes re-import idempotent.
     *
     * @return void
     */
    public function testSeedObjectsAreIdempotentOnReImport(): void
    {
        $objects = ($this->register['components']['objects'] ?? []);

        $slugs = [];
        $ids   = [];

        foreach ($objects as $index => $object) {
            $self = ($object['@self'] ?? []);

            $slug = (string) ($self['slug'] ?? '');
            $this->assertNotSame(
                expected: '',
                actual: $slug,
                message: "Seed object #{$index} must carry a stable @self.slug so re-import matches it."
            );

            $key = ($self['schema'] ?? '').'/'.$slug;
            $this->assertNotContains(
                needle: $key,
                haystack: $slugs,
                message: "Two seed objects share the slug '{$key}'; a re-import would not be idempotent."
            );

            $slugs[] = $key;

            $id = (string) ($self['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $this->assertNotContains(
                needle: $id,
                haystack: $ids,
                message: "Two seed objects share the id '{$id}'; a re-import would not be idempotent."
            );

            $ids[] = $id;
        }//end foreach
    }//end testSeedObjectsAreIdempotentOnReImport()

    /**
     * Task 7.3 / 5.1 — the five previously-enumerated complaint categories are
     * seeded as complaintCategory objects whose slugs equal the old enum
     * values, so any complaint row still carrying one of those values resolves
     * once `category` became a reference.
     *
     * @return void
     */
    public function testFormerComplaintCategoryEnumValuesAreSeededAsObjects(): void
    {
        $objects = ($this->register['components']['objects'] ?? []);

        $slugs = [];
        foreach ($objects as $object) {
            if ((($object['@self']['schema'] ?? '') === 'complaintCategory') === false) {
                continue;
            }

            $slugs[] = (string) ($object['@self']['slug'] ?? '');
        }

        foreach (['service', 'product', 'communication', 'billing', 'other'] as $legacy) {
            $this->assertContains(
                needle: $legacy,
                haystack: $slugs,
                message: "The former complaint.category enum value '{$legacy}' must be seeded as a complaintCategory "
                    .'object with a matching slug, so existing complaint rows resolve.'
            );
        }
    }//end testFormerComplaintCategoryEnumValuesAreSeededAsObjects()

    /**
     * Task 7.3 — the seed dataset carries no live BSN. A nine-digit number
     * sitting on a BSN-ish key would be exactly the failure this guards.
     *
     * @return void
     */
    public function testSeedDataCarriesNoLiveBsn(): void
    {
        $objects = ($this->register['components']['objects'] ?? []);

        foreach ($objects as $object) {
            foreach (['bsn', 'burgerservicenummer'] as $key) {
                $this->assertArrayNotHasKey(
                    key: $key,
                    array: $object,
                    message: 'Seed data must contain no BSN.'
                );
            }
        }

        $fragment = (string) file_get_contents(self::appRoot().'/lib/Settings/register.d/71-kcc-contactcentre.json');
        $this->assertSame(
            expected: 0,
            actual: preg_match('/"bsn"\s*:/i', $fragment),
            message: 'The contact-centre seed data must contain no BSN field.'
        );
    }//end testSeedDataCarriesNoLiveBsn()
}//end class
