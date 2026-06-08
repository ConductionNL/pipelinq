<?php

/**
 * Integration test for the appointment-booking domain register fragment.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-01-data-model/tasks.md#section-3-integration-test
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that the five appointment-booking domain schemas materialise in
 * the merged Pipelinq register configuration and that the bundled Service,
 * Resource, and Booking seed objects are addressable through the same
 * `(register, schema, filter)` triplet that `ObjectService::findObjects()`
 * uses at runtime (ADR-015 — three positional arguments).
 *
 * The test runs the real ADR-037 register-fragment loader against the
 * repository tree (no NC server needed), so it catches both JSON syntax
 * regressions and the additive-list merge that joins the new schemas to
 * the `pipelinq` register's schema list.
 *
 * @spec openspec/changes/appointment-booking-01-data-model/tasks.md#section-3-integration-test
 */
class AppointmentBookingRegisterTest extends TestCase
{

    /**
     * Merged register configuration produced by ConfigFileLoaderService.
     *
     * @var array<string, mixed>
     */
    private array $config;


    /**
     * Wire the real loader against the repository root and load the
     * merged register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appPath = dirname(__DIR__, 2);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appPath);

        $loader = new ConfigFileLoaderService($appManager);
        $this->config = $loader->loadConfigurationFile();

    }//end setUp()


    /**
     * Provider — every schema slug the appointment-booking data model owns.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function bookingSchemaProvider(): array
    {
        return [
            'service'           => ['service',           ['name', 'durationMinutes', 'status']],
            'resource'          => ['resource',          ['name', 'type', 'status']],
            'booking'           => ['booking',           ['customerId', 'serviceId', 'startAt', 'endAt', 'status']],
            'walkInTicket'      => ['walkInTicket',      ['displayName', 'arrivedAt', 'status']],
            'availabilityCache' => ['availabilityCache', ['resourceId', 'date', 'generatedAt']],
        ];

    }//end bookingSchemaProvider()


    /**
     * The five appointment-booking schemas MUST materialise in the merged
     * configuration with their required fields wired up.
     *
     * @param string            $slug   The schema slug.
     * @param array<int, string> $required The required field names.
     *
     * @dataProvider bookingSchemaProvider
     *
     * @return void
     */
    public function testAppointmentBookingSchemaMaterialises(string $slug, array $required): void
    {
        $schemas = ($this->config['components']['schemas'] ?? []);
        $this->assertArrayHasKey(
            $slug,
            $schemas,
            "Schema '{$slug}' MUST materialise in the merged register configuration."
        );

        $schema = $schemas[$slug];
        $this->assertSame(
            $slug,
            ($schema['slug'] ?? null),
            "Schema '{$slug}' slug field MUST match the map key."
        );

        $declaredRequired = ($schema['required'] ?? []);
        foreach ($required as $field) {
            $this->assertContains(
                $field,
                $declaredRequired,
                "Schema '{$slug}' MUST list '{$field}' as required."
            );
        }

    }//end testAppointmentBookingSchemaMaterialises()


    /**
     * The booking schemas MUST be members of the `pipelinq` register's
     * schemas[] list — the additive deep-merge MUST union the fragment's
     * extras onto the base list (no entry should be dropped).
     *
     * @return void
     */
    public function testBookingSchemasAreJoinedToPipelinqRegister(): void
    {
        $registers       = ($this->config['components']['registers'] ?? []);
        $pipelinqSchemas = ($registers['pipelinq']['schemas'] ?? []);

        $this->assertIsArray($pipelinqSchemas, 'pipelinq register MUST declare schemas[].');
        foreach (['service', 'resource', 'booking', 'walkInTicket', 'availabilityCache'] as $expected) {
            $this->assertContains(
                $expected,
                $pipelinqSchemas,
                "pipelinq register MUST list '{$expected}' (fragment merge contract)."
            );
        }

        // Base schemas must still be present after the merge — guards
        // against the fragment loader silently replacing the list.
        $this->assertContains('client', $pipelinqSchemas, 'Base register schemas MUST survive the merge.');
        $this->assertContains('contact', $pipelinqSchemas, 'Base register schemas MUST survive the merge.');

    }//end testBookingSchemasAreJoinedToPipelinqRegister()


    /**
     * Provider — slug + register + schema + minimum expected hit count
     * for the seed-object queries we exercise through the
     * `(register, schema, filter)` triplet shape (ADR-015).
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function seedObjectProvider(): array
    {
        return [
            'service-seeds'  => ['pipelinq', 'service',  4],
            'resource-seeds' => ['pipelinq', 'resource', 4],
            'booking-seeds'  => ['pipelinq', 'booking',  2],
        ];

    }//end seedObjectProvider()


    /**
     * Seed Service / Resource / Booking objects MUST be queryable via the
     * three-positional-arg shape ObjectService::findObjects() uses at
     * runtime (ADR-015) — here we exercise the contract against the
     * merged fragment payload that OpenRegister will import.
     *
     * @param string $register The register slug to query.
     * @param string $schema   The schema slug to query.
     * @param int    $minHits  Minimum number of seed rows expected.
     *
     * @dataProvider seedObjectProvider
     *
     * @return void
     */
    public function testSeedObjectsAreFindable(string $register, string $schema, int $minHits): void
    {
        $objects = ($this->config['components']['objects'] ?? []);

        $hits = $this->findObjects($objects, $register, $schema, []);
        $this->assertGreaterThanOrEqual(
            $minHits,
            count($hits),
            "Expected at least {$minHits} seed object(s) for {$register}/{$schema}."
        );

        // Every hit must declare the (register, schema, slug) triplet.
        foreach ($hits as $object) {
            $self = ($object['@self'] ?? []);
            $this->assertSame($register, ($self['register'] ?? null), '@self.register mismatch.');
            $this->assertSame($schema,   ($self['schema']   ?? null), '@self.schema mismatch.');
            $this->assertNotEmpty(($self['slug'] ?? ''), '@self.slug MUST be set on every seed object.');
        }

    }//end testSeedObjectsAreFindable()


    /**
     * The four named Service seeds MUST be present and individually
     * resolvable by slug — proving the slugs called out in the design
     * (haircut-simple, color-and-cut, oil-change-standard,
     * consultation-tax) actually land in the merged payload.
     *
     * @return void
     */
    public function testNamedServiceSeedsAreResolvable(): void
    {
        $objects = ($this->config['components']['objects'] ?? []);
        $expected = [
            'service-haircut-simple',
            'service-color-and-cut',
            'service-oil-change-standard',
            'service-consultation-tax',
        ];

        foreach ($expected as $slug) {
            $hit = $this->findOneBySlug($objects, 'pipelinq', 'service', $slug);
            $this->assertNotNull($hit, "Service seed '{$slug}' MUST be present.");
            $this->assertNotEmpty(($hit['name'] ?? ''), "Service '{$slug}' MUST have a Dutch display name.");
            $this->assertSame('EUR', ($hit['currency'] ?? 'EUR'), "Service '{$slug}' MUST quote prices in EUR.");
        }

    }//end testNamedServiceSeedsAreResolvable()


    /**
     * The colour-and-cut seed MUST round-trip the three-step multiStep
     * shape (skill-bound work / allowGap room time / final cut),
     * proving the sub-schema and seed are wired up end-to-end.
     *
     * @return void
     */
    public function testMultiStepServiceSeedRoundTrip(): void
    {
        $objects = ($this->config['components']['objects'] ?? []);
        $service = $this->findOneBySlug($objects, 'pipelinq', 'service', 'service-color-and-cut');

        $this->assertNotNull($service, 'service-color-and-cut MUST be present.');
        $this->assertCount(3, ($service['multiStep'] ?? []), 'service-color-and-cut MUST declare three steps.');
        $this->assertTrue(
            ($service['multiStep'][1]['allowGap'] ?? false),
            'The middle multiStep entry MUST set allowGap:true (room-only processing window).'
        );
        $this->assertTrue(($service['requiresDeposit'] ?? false), 'service-color-and-cut MUST require a deposit.');

    }//end testMultiStepServiceSeedRoundTrip()


    /**
     * In-memory analogue of `ObjectService::findObjects($register, $schema,
     * $filters)` over the merged `components.objects[]` array. Mirrors the
     * three-positional-arg signature mandated by ADR-015 so the test
     * exercises the exact contract used at runtime.
     *
     * @param array<int, array<string, mixed>> $objects  Merged seed objects.
     * @param string                           $register Register slug.
     * @param string                           $schema   Schema slug.
     * @param array<string, mixed>             $filters  Property filters.
     *
     * @return array<int, array<string, mixed>> Matching seed rows.
     */
    private function findObjects(array $objects, string $register, string $schema, array $filters): array
    {
        $hits = [];
        foreach ($objects as $row) {
            $self = ($row['@self'] ?? []);
            if (($self['register'] ?? null) !== $register) {
                continue;
            }

            if (($self['schema'] ?? null) !== $schema) {
                continue;
            }

            $match = true;
            foreach ($filters as $key => $expected) {
                if (($row[$key] ?? null) !== $expected) {
                    $match = false;
                    break;
                }
            }

            if ($match === true) {
                $hits[] = $row;
            }
        }

        return $hits;

    }//end findObjects()


    /**
     * Resolve a single seed object by its `@self.slug`.
     *
     * @param array<int, array<string, mixed>> $objects  Merged seed objects.
     * @param string                           $register Register slug.
     * @param string                           $schema   Schema slug.
     * @param string                           $slug     Slug to look up.
     *
     * @return array<string, mixed>|null The matched row, or null.
     */
    private function findOneBySlug(array $objects, string $register, string $schema, string $slug): ?array
    {
        foreach ($this->findObjects($objects, $register, $schema, []) as $row) {
            if (($row['@self']['slug'] ?? null) === $slug) {
                return $row;
            }
        }

        return null;

    }//end findOneBySlug()


}//end class
