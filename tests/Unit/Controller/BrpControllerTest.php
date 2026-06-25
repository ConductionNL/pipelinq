<?php

/**
 * Unit tests for BrpController — Wet-BRP audit-record mapping.
 *
 * These tests pin the legally-required invariant that the `brpLookupVerzoek`
 * audit record carries the correlation id, response duration and response
 * status returned by HaalCentraalClient::lookupPersoon() — REGARDLESS of which
 * transport (the OpenRegister BRP leaf or the legacy OAuth2 + mTLS direct path)
 * produced them. The controller is source-agnostic: it reads `_correlationId`
 * / `_responseDurationMs` / `_responseStatus` off the returned person and maps
 * them to `haalcentraalCorrelationId` / `responseDuurMs` / `responseStatus`, so
 * an OR-200 envelope and a legacy-200 envelope with the same meta produce a
 * byte-identical persisted record.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\OptOutService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 */
class BrpControllerTest extends TestCase
{
    /**
     * A formally-valid demo BSN (passes the 11-proef).
     *
     * @var string
     */
    private const DEMO_BSN = '123456782';

    /**
     * Holder receiving the captured `brpLookupVerzoek` payload.
     *
     * @var \ArrayObject<string,mixed>
     */
    private \ArrayObject $verzoekHolder;

    /**
     * Return the captured `brpLookupVerzoek` payload, or null when none.
     *
     * @return array<string,mixed>|null
     */
    private function capturedVerzoek(): ?array
    {
        if (isset($this->verzoekHolder['verzoek']) === true) {
            return $this->verzoekHolder['verzoek'];
        }

        return null;
    }//end capturedVerzoek()

    /**
     * The meta values that both transports surface identically. Feeding this as
     * `_correlationId`/`_responseDurationMs`/`_responseStatus` proves the
     * controller's audit mapping is transport-agnostic and byte-identical.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
     */
    public function testLookupPersistsMetaIntoAuditRecord(): void
    {
        $person = [
            'voornamen'           => 'Jan',
            'geslachtsnaam'       => 'Jansen',
            'indicatieGeheim'     => '0',
            'verblijfplaats'      => ['straat' => 'Hoofdstraat'],
            '_correlationId'      => 'corr-shared-xyz',
            '_responseDurationMs' => 142,
            '_responseStatus'     => 200,
        ];

        $controller = $this->buildController(remotePerson: $person);

        $response = $controller->lookup();
        $data     = $response->getData();

        // Happy-path lookup succeeded.
        self::assertSame(200, $response->getStatus());
        self::assertArrayHasKey('persoon', $data);

        // The audit record carries the meta from the client, mapped to the
        // canonical Wet-BRP field names — identical regardless of transport.
        $verzoek = $this->capturedVerzoek();
        self::assertNotNull($verzoek, 'brpLookupVerzoek was not persisted');
        self::assertSame('corr-shared-xyz', $verzoek['haalcentraalCorrelationId']);
        self::assertSame(142, $verzoek['responseDuurMs']);
        self::assertSame('geslaagd', $verzoek['responseStatus']);
        self::assertSame('Wet BRP', $verzoek['doelbinding']);
        self::assertArrayHasKey('bsnHash', $verzoek);
        // The raw BSN must never be a field on the audit record.
        self::assertArrayNotHasKey('bsn', $verzoek);
    }//end testLookupPersistsMetaIntoAuditRecord()

    /**
     * A null correlation id from the client (no upstream X-Correlation-ID) must
     * persist as an absent `haalcentraalCorrelationId` (the controller filters
     * nulls), exactly as the legacy path records today.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
     */
    public function testLookupNullCorrelationIdNotPersisted(): void
    {
        $person = [
            'voornamen'           => 'Jan',
            'indicatieGeheim'     => '0',
            '_correlationId'      => null,
            '_responseDurationMs' => 9,
            '_responseStatus'     => 200,
        ];

        $controller = $this->buildController(remotePerson: $person);
        $controller->lookup();

        $verzoek = $this->capturedVerzoek();
        self::assertNotNull($verzoek);
        self::assertArrayNotHasKey(
            'haalcentraalCorrelationId',
            $verzoek,
            'null correlation id is array_filtered out, not persisted as null'
        );
        self::assertSame(9, $verzoek['responseDuurMs']);
    }//end testLookupNullCorrelationIdNotPersisted()

    /**
     * Build the controller wired with mocks for all collaborators, the user
     * authorised, the BSN formally valid, the cache empty, and the
     * HaalCentraalClient returning the supplied person. The container's
     * ObjectService stub captures the persisted `brpLookupVerzoek`.
     *
     * @param array<string,mixed> $remotePerson The person HaalCentraalClient returns.
     *
     * @return BrpController
     */
    private function buildController(array $remotePerson): BrpController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                $params = [
                    'bsn'          => self::DEMO_BSN,
                    'verzoekreden' => 'Adresverificatie',
                    'doelbinding'  => 'Wet BRP',
                    'grondslag'    => 'Wet BRP art. 1.4',
                ];
                return $params[$key] ?? $default;
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar1');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        // Authorise the actor as admin (resolveActorRol → 'beheerder').
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        // App-config: register + brpLookupVerzoek_schema set so saveLookupVerzoek
        // routes through the ObjectService; brpPersoon_schema set for back-fill.
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') {
                $cfg = [
                    'register'                => 'reg-1',
                    'brpLookupVerzoek_schema' => 'schema-verzoek',
                    'brpPersoon_schema'       => 'schema-persoon',
                ];
                return $cfg[$key] ?? $default;
            }
        );

        $validation = $this->createMock(BsnValidationService::class);
        $validation->method('validate')->willReturn(
            ['isFormeelGeldig' => true, 'errorCode' => null, 'errorMessage' => null, 'maskedBsn' => '*****6782']
        );

        $cacheService = $this->createMock(BrpCacheService::class);
        $cacheService->method('get')->willReturn(null);
        // set() echoes the persoon back (so back-fill + response shaping work).
        $cacheService->method('set')->willReturnCallback(
            static fn(array $persoon, ?int $ttl=null): array => $persoon
        );

        $haalCentraal = $this->createMock(HaalCentraalClient::class);
        $haalCentraal->method('lookupPersoon')->willReturn($remotePerson);

        $audit           = $this->createMock(BsnAuditService::class);
        $optOut          = $this->createMock(OptOutService::class);
        $webhookListener = $this->createMock(BrpMutationWebhookListener::class);

        // ObjectService stub captures the brpLookupVerzoek payload into an
        // ArrayObject holder so the reference survives back to the test.
        $holder        = new \ArrayObject();
        $objectService = new class ($holder) {
            /**
             * @param \ArrayObject<string,mixed> $holder Capture holder.
             */
            public function __construct(private \ArrayObject $holder)
            {
            }//end __construct()

            /**
             * Capture the first verzoek-shaped save (has verzoekreden), echo it back.
             *
             * @param array<string,mixed> $object   The object to save.
             * @param array<int,mixed>    $extend   Extend list.
             * @param string              $register Register id.
             * @param string              $schema   Schema id.
             * @param string|null         $uuid     Optional uuid.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object, array $extend=[], string $register='', string $schema='', ?string $uuid=null): array
            {
                if (isset($object['verzoekreden']) === true && $this->holder->count() === 0) {
                    $this->holder['verzoek'] = $object;
                }

                $object['@self'] = ['id' => 'saved-uuid'];
                return $object;
            }//end saveObject()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);
        $this->verzoekHolder = $holder;

        return new BrpController(
            $request,
            $userSession,
            $groupManager,
            $l10n,
            $appConfig,
            $validation,
            $cacheService,
            $haalCentraal,
            $audit,
            $optOut,
            $webhookListener,
            $container,
            $this->createMock(LoggerInterface::class),
        );
    }//end buildController()
}//end class
