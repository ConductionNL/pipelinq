<?php

/**
 * Unit tests for ExtensionService (60-day extension rules).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Avg\AvgEventService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\DeadlineService;
use OCA\Pipelinq\Service\Avg\ExtensionService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for ExtensionService.
 */
class ExtensionServiceTest extends TestCase
{
    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The service under test.
     *
     * @var ExtensionService
     */
    private ExtensionService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeAvgObjectService();
        $appConfig           = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : $key)
        );
        $repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $events     = new AvgEventService(repository: $repository, logger: new NullLogger());

        $this->service = new ExtensionService(
            repository: $repository,
            deadline: new DeadlineService(orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr())),
            events: $events,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Seed a request and return it as a stored array (with @self).
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The stored request.
     */
    private function seedRequest(array $overrides = []): array
    {
        return $this->objectService->saveObject(
            object: array_merge(
                [
                    'ingediendOp'               => '2026-04-08T11:00:00+02:00',
                    'wettelijkeTermijnVerloopt' => '2026-05-08T23:59:59+02:00',
                    'verlengdMet'               => 0,
                ],
                $overrides
            ),
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
    }//end seedRequest()

    /**
     * A valid extension on day 25 sets +60 and a new deadline + records an event.
     *
     * @return void
     */
    public function testValidExtension(): void
    {
        $request = $this->seedRequest();
        $now     = new DateTimeImmutable('2026-05-03T10:00:00+02:00');

        $updated = $this->service->extend(
            request: $request,
            justification: 'Het verzoek is complex en raakt meerdere bronsystemen die elk uitgevraagd moeten worden.',
            now: $now
        );

        $this->assertSame(DeadlineService::EXTENSION_DAYS, $updated['verlengdMet']);
        // EU art-12(3): intake 2026-04-08 -> base +1mo (2026-05-08) -> extended +2mo (2026-07-08).
        $this->assertSame('2026-07-08', (new DateTimeImmutable((string) $updated['wettelijkeTermijnVerloopt']))->format('Y-m-d'));

        $events = $this->objectService->findAll(
            ['filters' => ['schema' => AvgRepository::SCHEMA_TERMIJN_EVENT, 'type' => 'verlenging-gecommuniceerd']]
        );
        $this->assertCount(1, $events);
    }//end testValidExtension()

    /**
     * A too-short justification is rejected.
     *
     * @return void
     */
    public function testRejectsShortJustification(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->extend(
            request: $this->seedRequest(),
            justification: 'kort',
            now: new DateTimeImmutable('2026-05-03T10:00:00+02:00')
        );
    }//end testRejectsShortJustification()

    /**
     * Extension is refused once the deadline has already passed.
     *
     * @return void
     */
    public function testRefusesAfterDeadline(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->extend(
            request: $this->seedRequest(),
            justification: 'Een geldige onderbouwing van voldoende lengte voor de verlenging.',
            now: new DateTimeImmutable('2026-05-10T10:00:00+02:00')
        );
    }//end testRefusesAfterDeadline()

    /**
     * A second extension is refused.
     *
     * @return void
     */
    public function testRefusesDoubleExtension(): void
    {
        $request = $this->seedRequest(['verlengdMet' => 60]);

        $this->expectException(OCSBadRequestException::class);
        $this->service->extend(
            request: $request,
            justification: 'Een geldige onderbouwing van voldoende lengte voor de verlenging.',
            now: new DateTimeImmutable('2026-05-03T10:00:00+02:00')
        );
    }//end testRefusesDoubleExtension()
}//end class
