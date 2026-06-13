<?php

/**
 * Unit tests for AvgRequestService (intake, classification, scoping, retention).
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
use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgEventService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\DeadlineService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for AvgRequestService.
 */
class AvgRequestServiceTest extends TestCase
{
    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The repository under the service.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * The group manager mock.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * The service under test.
     *
     * @var AvgRequestService
     */
    private AvgRequestService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeAvgObjectService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : $key)
        );

        $this->repository   = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $access             = new AvgAccessService(groupManager: $this->groupManager, appConfig: $appConfig);
        $events             = new AvgEventService(repository: $this->repository, logger: new NullLogger());

        $this->service = new AvgRequestService(
            repository: $this->repository,
            deadline: new DeadlineService(),
            access: $access,
            events: $events,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Intake classifies an explicit article, computes a 30-day deadline, sets the
     * reference and records a receipt event.
     *
     * @return void
     */
    public function testIntakeClassifiesAndComputesDeadline(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $request = $this->service->intake(
            input: ['artikel' => 'art-15-inzage', 'specifiekeVraag' => 'Inzage graag', 'scope' => ['parkeren']],
            userId: 'handler1'
        );

        $this->assertSame('art-15-inzage', $request['artikel']);
        $this->assertSame('in-behandeling', $request['status']);
        $this->assertStringStartsWith('AVG-', (string) $request['kenmerk']);
        $this->assertNotSame('', (string) $request['wettelijkeTermijnVerloopt']);

        // A receipt TermijnEvent was recorded.
        $events = $this->repository->findAll(
            schemaKey: AvgRepository::SCHEMA_TERMIJN_EVENT,
            filters: ['type' => 'ontvangstbevestiging-verstuurd']
        );
        $this->assertCount(1, $events);
    }//end testIntakeClassifiesAndComputesDeadline()

    /**
     * An ambiguous free-text intent (two articles) is rejected so the handler
     * disambiguates.
     *
     * @return void
     */
    public function testIntakeRejectsAmbiguousFreeText(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(OCSBadRequestException::class);
        $this->service->intake(
            input: ['specifiekeVraag' => 'Ik wil mijn gegevens corrigeren en daarna verwijderen'],
            userId: 'handler1'
        );
    }//end testIntakeRejectsAmbiguousFreeText()

    /**
     * A single free-text intent classifies without an explicit article.
     *
     * @return void
     */
    public function testClassifySingleIntent(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $request = $this->service->intake(
            input: ['specifiekeVraag' => 'Graag inzage in mijn dossier'],
            userId: 'handler1'
        );

        $this->assertSame('art-15-inzage', $request['artikel']);
    }//end testClassifySingleIntent()

    /**
     * A plain handler may not view another handler's request (IDOR guard); a team
     * lead may.
     *
     * @return void
     */
    public function testAccessScopingHidesOtherHandlersRequests(): void
    {
        // Seed a request owned by handlerA directly in the store.
        $this->objectService->saveObject(
            object: ['behandelaar' => 'handlerA', 'status' => 'in-behandeling', 'kenmerk' => 'AVG-2026-0001'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );

        // handlerB is not admin, not team lead, not DPO.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $visible = $this->service->list(filters: [], userId: 'handlerB');
        $this->assertCount(0, $visible);

        // handlerA sees their own request.
        $visibleOwn = $this->service->list(filters: [], userId: 'handlerA');
        $this->assertCount(1, $visibleOwn);
    }//end testAccessScopingHidesOtherHandlersRequests()

    /**
     * get() throws 403 when a handler reaches for another handler's request.
     *
     * @return void
     */
    public function testGetForbiddenForOtherHandler(): void
    {
        $saved = $this->objectService->saveObject(
            object: ['behandelaar' => 'handlerA', 'status' => 'in-behandeling'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $id = $saved['@self']['id'];

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->expectException(OCSForbiddenException::class);
        $this->service->get(id: (string) $id, userId: 'handlerB');
    }//end testGetForbiddenForOtherHandler()

    /**
     * Delete is refused while the 5-year retention window is active (and no DPO
     * override), and allowed once the window has passed.
     *
     * @return void
     */
    public function testDeleteRefusedDuringRetention(): void
    {
        $saved = $this->objectService->saveObject(
            object: [
                'behandelaar' => 'handlerA',
                'status'      => 'gearchiveerd',
                'retentieTot' => (new DateTimeImmutable('+2 years'))->format('c'),
            ],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $id = (string) $saved['@self']['id'];

        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(OCSForbiddenException::class);
        $this->service->delete(id: $id, userId: 'handlerA', isDpo: false);
    }//end testDeleteRefusedDuringRetention()

    /**
     * A DPO may override the retention guard and delete early.
     *
     * @return void
     */
    public function testDeleteAllowedForDpoOverride(): void
    {
        $saved = $this->objectService->saveObject(
            object: [
                'behandelaar' => 'handlerA',
                'status'      => 'gearchiveerd',
                'retentieTot' => (new DateTimeImmutable('+2 years'))->format('c'),
            ],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $id = (string) $saved['@self']['id'];

        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->service->delete(id: $id, userId: 'dpo1', isDpo: true);

        $this->assertNull(
            $this->repository->findOrNull(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id)
        );
    }//end testDeleteAllowedForDpoOverride()

    /**
     * Archive stamps the 5-year retention date and the archived status.
     *
     * @return void
     */
    public function testArchiveStampsRetention(): void
    {
        $saved = $this->objectService->saveObject(
            object: ['behandelaar' => 'handlerA', 'status' => 'in-behandeling'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
        $id = (string) $saved['@self']['id'];

        $this->groupManager->method('isAdmin')->willReturn(true);

        $archived = $this->service->archive(id: $id, userId: 'handlerA');

        $this->assertSame('gearchiveerd', $archived['status']);
        $year = (int) (new DateTimeImmutable())->format('Y');
        $this->assertStringContainsString((string) ($year + AvgRequestService::RETENTION_YEARS), (string) $archived['retentieTot']);
    }//end testArchiveStampsRetention()
}//end class
