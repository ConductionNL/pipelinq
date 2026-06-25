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
            deadline: new DeadlineService(orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr())),
            access: $access,
            events: $events,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Intake classifies an explicit article, computes the EU art-12 one-month
     * deadline (via OR), sets the reference and records a receipt event.
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

    /**
     * Pinning test (legal safety net): the avgVerzoek status-transition graph moved
     * to the schema's `x-openregister-lifecycle` declaration MUST permit exactly the
     * set the ad-hoc PHP check permitted before, and reject exactly the same set —
     * with the same exception type and message.
     *
     * The historical contract: from any of the seven working states, `update()` may
     * move the status to ANY of the nine enum states (no edge was ever closed
     * between working states); the two terminal states (afgerond/gearchiveerd) are
     * read-only and every update from them is rejected with the same "afgerond"
     * message; an unknown target status is rejected.
     *
     * @return void
     */
    public function testStatusTransitionMatrixPreservesContract(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);

        $workingStates = [
            'ingediend',
            'in-behandeling',
            'bewijs-verzamelen',
            'redactie',
            'bundle-genereren',
            'wachten-op-verzoeker',
            'weigering-opgesteld',
        ];
        $allStates = array_merge($workingStates, ['afgerond', 'gearchiveerd']);

        // LEGAL: every working state -> every enum state succeeds and persists.
        foreach ($workingStates as $from) {
            foreach ($allStates as $to) {
                $id = $this->seedRequest(status: $from);

                $updated = $this->service->update(
                    id: $id,
                    patch: ['status' => $to],
                    userId: 'handlerA'
                );

                $this->assertSame(
                    $to,
                    (string) $updated['status'],
                    sprintf('Legal transition %s -> %s must succeed and persist', $from, $to)
                );
            }
        }

        // ILLEGAL (unknown target status): rejected with OCSBadRequestException.
        $idUnknown = $this->seedRequest(status: 'in-behandeling');
        try {
            $this->service->update(id: $idUnknown, patch: ['status' => 'verzonnen-status'], userId: 'handlerA');
            $this->fail('Unknown target status must be rejected');
        } catch (OCSBadRequestException $e) {
            $this->assertStringContainsString('Onbekende AVG-status', $e->getMessage());
        }

        // ILLEGAL (read-only terminal states): every update is rejected with the
        // preserved "afgerond" message — the contract that existed before the refactor.
        foreach (['afgerond', 'gearchiveerd'] as $terminal) {
            $idTerminal = $this->seedRequest(status: $terminal);
            try {
                $this->service->update(id: $idTerminal, patch: ['status' => 'in-behandeling'], userId: 'handlerA');
                $this->fail(sprintf('Update from terminal state %s must be rejected', $terminal));
            } catch (OCSBadRequestException $e) {
                $this->assertSame('Een afgerond verzoek kan niet meer worden gewijzigd.', $e->getMessage());
            }
        }
    }//end testStatusTransitionMatrixPreservesContract()

    /**
     * The transition graph the service enforces is sourced from the bundled
     * avgVerzoek schema declaration (not a hardcoded constant): the resolved graph
     * matches the schema's `x-openregister-lifecycle`, with the two terminal states
     * present as keys with empty target lists.
     *
     * @return void
     */
    public function testTransitionGraphSourcedFromSchema(): void
    {
        $graph = (new \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph())
            ->fullAdjacencyFor(schemaSlug: 'avgVerzoek');

        $this->assertNotSame([], $graph, 'avgVerzoek must declare x-openregister-lifecycle');

        // Seven working states each reach all nine enum states.
        foreach (['ingediend', 'in-behandeling', 'redactie', 'bundle-genereren', 'weigering-opgesteld'] as $from) {
            $this->assertContains('afgerond', $graph[$from] ?? [], sprintf('%s must reach afgerond', $from));
            $this->assertContains('gearchiveerd', $graph[$from] ?? [], sprintf('%s must reach gearchiveerd', $from));
            $this->assertCount(9, $graph[$from] ?? [], sprintf('%s must reach all nine states', $from));
        }

        // Terminal states are present with no outgoing transitions.
        $this->assertSame([], $graph['afgerond'] ?? null);
        $this->assertSame([], $graph['gearchiveerd'] ?? null);
    }//end testTransitionGraphSourcedFromSchema()

    /**
     * Seed an avgVerzoek owned by handlerA at a given status, returning its id.
     *
     * @param string $status The initial status.
     *
     * @return string The seeded request id.
     */
    private function seedRequest(string $status): string
    {
        $saved = $this->objectService->saveObject(
            object: ['behandelaar' => 'handlerA', 'status' => $status, 'artikel' => 'art-15-inzage'],
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );

        return (string) $saved['@self']['id'];
    }//end seedRequest()
}//end class
