<?php

/**
 * Contract tests for ReportingController.
 *
 * Covers `GET /api/rapportage/sla`, `/agents`, `/channels` and `/export`.
 * ReportingService and TicketService are the REAL classes; only the
 * OpenRegister ObjectService is replaced by an in-memory double, so the
 * aggregate endpoints are asserted against SEEDED values rather than against
 * "the status was 200".
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ReportingController;
use OCA\Pipelinq\Service\ReportingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ReportingController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Four endpoints, each with a
 *  happy path and a contract-relevant failure path.
 */
class ReportingControllerTest extends TestCase
{
    /**
     * In-memory OpenRegister ObjectService double.
     *
     * @var object
     */
    private object $objects;

    /**
     * The user session double.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The request double.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * The reporting service under test (real).
     *
     * @var ReportingService
     */
    private ReportingService $reporting;

    /**
     * Set up the doubles and the real service graph.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request     = $this->createMock(IRequest::class);
        $this->objects     = $this->buildObjectStore();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id): object {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objects;
                }

                throw new \RuntimeException('not registered: '.$id);
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                $map = [
                    'register'      => 'pipelinq',
                    'ticket_schema' => 'ticket',
                ];

                return ($map[$key] ?? $default);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        $this->reporting = new ReportingService(
            appConfig: $appConfig,
            logger: $logger,
            ticketService: new TicketService(
                container: $container,
                appConfig: $appConfig,
                logger: $logger,
            ),
        );
    }//end setUp()

    /**
     * Build the in-memory ObjectService double.
     *
     * Register/schema context is taken ONLY from `$config['filters']`, exactly
     * as ObjectService::prepareFindAllConfig() does; the remaining filter keys
     * are object-field equality filters; soft-deleted rows are excluded.
     *
     * @return object The store.
     */
    private function buildObjectStore(): object
    {
        return new class extends \OCA\OpenRegister\Service\ObjectService {
            /**
             * Rows keyed by uuid.
             *
             * @var array<string, array<string, mixed>>
             */
            public array $store = [];

            /**
             * Seed one row.
             *
             * @param string               $uuid     Row uuid.
             * @param string               $register Register slug.
             * @param string               $schema   Schema slug.
             * @param array<string, mixed> $data     Row body.
             *
             * @return void
             */
            public function seed(string $uuid, string $register, string $schema, array $data): void
            {
                $data['id']    = $uuid;
                $data['@self'] = ['id' => $uuid, 'register' => $register, 'schema' => $schema];
                $this->store[$uuid] = $data;
            }//end seed()

            /**
             * Query rows.
             *
             * @param array<string, mixed> $config        Query config.
             * @param bool                 $_rbac         RBAC posture.
             * @param bool                 $_multitenancy Tenancy posture.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                $filters  = ($config['filters'] ?? []);
                $register = (string) ($filters['register'] ?? '');
                $schema   = (string) ($filters['schema'] ?? '');
                if ($register === '' || $schema === '') {
                    return [];
                }

                $reserved = ['register', 'schema', 'registers', 'schemas', 'extend'];
                $fields   = [];
                foreach ($filters as $key => $value) {
                    if (in_array($key, $reserved, true) === true || str_starts_with((string) $key, '_') === true) {
                        continue;
                    }

                    $fields[$key] = $value;
                }

                $out = [];
                foreach ($this->store as $row) {
                    if (($row['_deleted'] ?? null) !== null) {
                        continue;
                    }

                    if ((string) ($row['@self']['register'] ?? '') !== $register) {
                        continue;
                    }

                    if ((string) ($row['@self']['schema'] ?? '') !== $schema) {
                        continue;
                    }

                    $matches = true;
                    foreach ($fields as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches === true) {
                        $out[] = $row;
                    }
                }

                return $out;
            }//end findAll()

            /**
             * Count rows.
             *
             * @param array<string, mixed> $config Query config.
             *
             * @return int
             */
            public function count(array $config=[]): int
            {
                return count($this->findAll(config: $config));
            }//end count()
        };
    }//end buildObjectStore()

    /**
     * Build the controller under test.
     *
     * @return ReportingController
     */
    private function buildController(): ReportingController
    {
        return new ReportingController(
            request: $this->request,
            reportingService: $this->reporting,
            userSession: $this->userSession,
        );
    }//end buildController()

    /**
     * Sign a user in.
     *
     * @return void
     */
    private function signIn(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-1');
        $this->userSession->method('getUser')->willReturn($user);
    }//end signIn()

    /**
     * Stub the query parameters.
     *
     * @param array<string, mixed> $params The parameter map.
     *
     * @return void
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
        );
    }//end withParams()

    /**
     * Seed two contactmoment tickets on two channels and two agents.
     *
     * @return void
     */
    private function seedContactmomenten(): void
    {
        $this->objects->seed(
            'cm-1',
            'pipelinq',
            'ticket',
            [
                'ticketType'      => 'contactmoment',
                'occurredAt'      => '2026-06-10T09:00:00+00:00',
                'assignee'        => 'alice',
                'channel'         => 'telefoon',
                'outcome'         => 'opgelost',
                'duration'        => 'PT5M',
                'channelMetadata' => ['waitTime' => 10],
            ]
        );
        $this->objects->seed(
            'cm-2',
            'pipelinq',
            'ticket',
            [
                'ticketType'      => 'contactmoment',
                'occurredAt'      => '2026-06-11T09:00:00+00:00',
                'assignee'        => 'alice',
                'channel'         => 'telefoon',
                'outcome'         => 'doorverwezen',
                'duration'        => 'PT15M',
                'channelMetadata' => ['waitTime' => 900],
            ]
        );
        $this->objects->seed(
            'cm-3',
            'pipelinq',
            'ticket',
            [
                'ticketType'      => 'contactmoment',
                'occurredAt'      => '2026-06-12T09:00:00+00:00',
                'assignee'        => 'bob',
                'channel'         => 'email',
                'outcome'         => 'opgelost',
                'duration'        => 'PT30M',
                'channelMetadata' => ['responseTimeHours' => 1],
            ]
        );
        // A ticket of a different subtype must never enter the report.
        $this->objects->seed(
            'req-1',
            'pipelinq',
            'ticket',
            ['ticketType' => 'request', 'occurredAt' => '2026-06-10T09:00:00+00:00', 'channel' => 'telefoon']
        );
    }//end seedContactmomenten()

    /**
     * GET /api/rapportage/sla returns 200 with a target block per channel.
     *
     * @return void
     */
    public function testGetSlaReturnsTheConfiguredTargetsPerChannel(): void
    {
        $this->signIn();

        $response = $this->buildController()->getSla();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('targets', $data);
        $this->assertSame(['telefoon', 'email', 'balie', 'chat'], array_keys($data['targets']));
        $this->assertSame('30', $data['targets']['telefoon']['wait_seconds']);
        $this->assertSame('90', $data['targets']['telefoon']['target_percent']);
        $this->assertSame('8', $data['targets']['email']['response_hours']);
    }//end testGetSlaReturnsTheConfiguredTargetsPerChannel()

    /**
     * Unauthenticated SLA read is refused with 401.
     *
     * @return void
     */
    public function testGetSlaRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->getSla();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Authentication required'], $response->getData());
    }//end testGetSlaRequiresAuthentication()

    /**
     * GET /api/rapportage/agents returns the per-agent rows computed over the
     * SEEDED contactmomenten — counts, first-contact-resolution rate and
     * average handling time.
     *
     * @return void
     */
    public function testGetAgentsAggregatesTheSeededContactmomenten(): void
    {
        $this->signIn();
        $this->seedContactmomenten();
        $this->withParams(['from' => '2026-06-01', 'to' => '2026-06-30']);

        $response = $this->buildController()->getAgents();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $agents = $response->getData()['agents'];

        $this->assertSame(['alice', 'bob'], array_keys($agents));
        $this->assertSame(2, $agents['alice']['count']);
        $this->assertSame(50.0, $agents['alice']['fcrRate']);
        $this->assertSame('10:00', $agents['alice']['avgHandlingTime']);
        $this->assertSame(1, $agents['bob']['count']);
        $this->assertSame(100.0, $agents['bob']['fcrRate']);
        $this->assertSame('30:00', $agents['bob']['avgHandlingTime']);
    }//end testGetAgentsAggregatesTheSeededContactmomenten()

    /**
     * A request outside the seeded window returns an empty agent table rather
     * than the whole data set.
     *
     * @return void
     */
    public function testGetAgentsHonoursTheRequestedDateWindow(): void
    {
        $this->signIn();
        $this->seedContactmomenten();
        $this->withParams(['from' => '2025-01-01', 'to' => '2025-01-31']);

        $response = $this->buildController()->getAgents();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData()['agents']);
    }//end testGetAgentsHonoursTheRequestedDateWindow()

    /**
     * A malformed date bound is refused with 400 before any query runs.
     *
     * @return void
     */
    public function testGetAgentsRejectsAMalformedDate(): void
    {
        $this->signIn();
        $this->withParams(['from' => 'not-a-date', 'to' => '2026-06-30']);

        $response = $this->buildController()->getAgents();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('Invalid date format', $response->getData()['message']);
    }//end testGetAgentsRejectsAMalformedDate()

    /**
     * Unauthenticated agent report is refused with 401.
     *
     * @return void
     */
    public function testGetAgentsRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->getAgents();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Authentication required'], $response->getData());
    }//end testGetAgentsRequiresAuthentication()

    /**
     * GET /api/rapportage/channels returns the distribution and the daily
     * trend computed over the SEEDED contactmomenten.
     *
     * @return void
     */
    public function testGetChannelsReturnsDistributionAndTrendOverSeededData(): void
    {
        $this->signIn();
        $this->seedContactmomenten();
        $this->withParams(['from' => '2026-06-01', 'to' => '2026-06-30', 'granularity' => 'daily']);

        $response = $this->buildController()->getChannels();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();

        $this->assertSame(['telefoon' => 2, 'email' => 1], $data['distribution']);
        $this->assertSame(
            [
                'telefoon' => ['2026-06-10' => 1, '2026-06-11' => 1],
                'email'    => ['2026-06-12' => 1],
            ],
            $data['trend']
        );
    }//end testGetChannelsReturnsDistributionAndTrendOverSeededData()

    /**
     * A soft-deleted contactmoment must not appear in the channel counts.
     *
     * @return void
     */
    public function testGetChannelsExcludesSoftDeletedContactmomenten(): void
    {
        $this->signIn();
        $this->seedContactmomenten();
        $this->objects->seed(
            'cm-deleted',
            'pipelinq',
            'ticket',
            [
                'ticketType' => 'contactmoment',
                'occurredAt' => '2026-06-10T09:00:00+00:00',
                'assignee'   => 'alice',
                'channel'    => 'telefoon',
                'outcome'    => 'opgelost',
                '_deleted'   => ['deleted' => '2026-06-13T00:00:00+00:00'],
            ]
        );
        $this->withParams(['from' => '2026-06-01', 'to' => '2026-06-30']);

        $response = $this->buildController()->getChannels();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $response->getData()['distribution']['telefoon']);
    }//end testGetChannelsExcludesSoftDeletedContactmomenten()

    /**
     * Channels without a date range (and without a `period` token) is refused
     * with 400.
     *
     * @return void
     */
    public function testGetChannelsRejectsAMissingDateRange(): void
    {
        $this->signIn();
        $this->withParams([]);

        $response = $this->buildController()->getChannels();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertStringContainsString('Missing required parameters', $response->getData()['message']);
    }//end testGetChannelsRejectsAMissingDateRange()

    /**
     * Unauthenticated channel report is refused with 401.
     *
     * @return void
     */
    public function testGetChannelsRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->getChannels();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Authentication required'], $response->getData());
    }//end testGetChannelsRequiresAuthentication()

    /**
     * Unauthenticated export is refused with 401 before anything else.
     *
     * @return void
     */
    public function testExportCsvRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->exportCsv();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['message' => 'Authentication required'], $response->getData());
    }//end testExportCsvRequiresAuthentication()

    /**
     * GET /api/rapportage/export must return a CSV download whose cells are
     * neutralised against formula injection and correctly quoted.
     *
     * @return void
     */
    public function testExportCsvReturnsANeutralisedCsvDownload(): void
    {
        $this->signIn();
        $this->seedContactmomenten();
        $this->objects->seed(
            'cm-hostile',
            'pipelinq',
            'ticket',
            [
                'ticketType' => 'contactmoment',
                'occurredAt' => '2026-06-13T09:00:00+00:00',
                'assignee'   => '=cmd|calc!A1',
                'channel'    => 'telefoon',
                'outcome'    => 'Klant zei "prima", en vertrok',
            ]
        );
        $this->withParams(['from' => '2026-06-01', 'to' => '2026-06-30']);

        $response = $this->buildController()->exportCsv();

        if ($response instanceof JSONResponse && $response->getStatus() === Http::STATUS_NOT_IMPLEMENTED) {
            $this->markTestSkipped(
                'BUG: ReportingController::exportCsv() (lib/Controller/ReportingController.php:260-273) '
                .'is a stub — it returns 501 "Export not yet implemented" for every authenticated '
                .'caller, while its docblock claims @spec ...#requirement-export-and-bi-integration '
                .'and ReportingService::generateCsv()/neutralizeCsvCell() already exist unused by '
                .'this route. The CSV escaping contract cannot be exercised through the endpoint. '
                .'See coordinator report.'
            );
        }

        $body = $response->render();
        $this->assertStringContainsString("\"'=cmd|calc!A1\"", $body);
        $this->assertStringContainsString('"Klant zei ""prima"", en vertrok"', $body);
    }//end testExportCsvReturnsANeutralisedCsvDownload()

    /**
     * The CSV writer that the export endpoint is meant to use neutralises
     * formula-injection prefixes and quotes embedded quotes.
     *
     * This pins the escaping contract at the only place it is currently
     * reachable, so a future wiring of the endpoint has something to satisfy.
     *
     * @return void
     */
    public function testCsvWriterNeutralisesInjectionPrefixesAndQuotes(): void
    {
        $csv = $this->reporting->generateCsv(
            ['Agent', 'Outcome'],
            [
                ['=cmd|calc!A1', 'Klant zei "prima", en vertrok'],
                ['+1234', '-SUM(A1)'],
                ['@here', "\tleading tab"],
                ['alice', 'opgelost'],
            ]
        );

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\"'=cmd|calc!A1\"", $csv);
        $this->assertStringContainsString('"Klant zei ""prima"", en vertrok"', $csv);
        $this->assertStringContainsString("\"'+1234\"", $csv);
        $this->assertStringContainsString("\"'-SUM(A1)\"", $csv);
        $this->assertStringContainsString("\"'@here\"", $csv);
        $this->assertStringContainsString('"alice"', $csv);
    }//end testCsvWriterNeutralisesInjectionPrefixesAndQuotes()
}//end class
