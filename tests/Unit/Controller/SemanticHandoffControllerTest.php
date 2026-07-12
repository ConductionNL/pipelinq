<?php

/**
 * Unit tests for SemanticHandoffController.
 *
 * Covers the auth guard, per-object guard, status guards, hidden-without
 * -implementer refusal and success atomicity of the request→case and
 * contract→invoice emit endpoints.
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
 * @spec openspec/changes/semantic-handoff-emit/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SemanticHandoffController;
use OCA\Pipelinq\Service\SemanticHandoffService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SemanticHandoffController unit coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class SemanticHandoffControllerTest extends TestCase
{
    private SemanticHandoffService $handoffService;
    private IUserSession $userSession;
    private object $objectService;
    private SemanticHandoffController $controller;

    /**
     * Build the controller with an in-memory OR object stub.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->handoffService = $this->createMock(SemanticHandoffService::class);
        $this->userSession    = $this->createMock(IUserSession::class);

        $this->objectService = new class {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var array<int, array<string, mixed>> */
            public array $saves = [];

            /**
             * @param string $id       Id.
             * @param mixed  $register Register.
             * @param mixed  $schema   Schema.
             *
             * @return array<string, mixed>|null
             */
            public function find(string $id, $register = null, $schema = null): ?array
            {
                return ($this->store[$id] ?? null);
            }

            /**
             * @param array<string, mixed> $object   Payload.
             * @param mixed                $register Register.
             * @param mixed                $schema   Schema.
             * @param string|null          $uuid     Id.
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array
            {
                $object['uuid']       = ($uuid ?? 'new');
                $this->store[$object['uuid']] = $object;
                $this->saves[]        = $object;
                return $object;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }

                throw new \RuntimeException('not registered: '.$id);
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                if ($key === 'register') {
                    return 'pipelinq';
                }

                return $default;
            }
        );

        // Unconfigured ticket schema id → the controller falls back to the
        // built-in `ticket` slug, exactly as it does on a fresh install.
        $ticketService = $this->createMock(TicketService::class);
        $ticketService->method('getSchemaId')->willReturn('');

        $this->controller = new SemanticHandoffController(
            $this->createMock(IRequest::class),
            $this->handoffService,
            $container,
            $appConfig,
            $ticketService,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

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
     * Unauthenticated conversion is rejected with 401.
     *
     * @return void
     */
    public function testConvertUnauthorized(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->convertRequestToCase(id: 'req-1');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testConvertUnauthorized()

    /**
     * A missing request is 404 and no handoff is attempted.
     *
     * @return void
     */
    public function testConvertNotFound(): void
    {
        $this->signIn();
        $this->handoffService->expects($this->never())->method('handoff');
        $response = $this->controller->convertRequestToCase(id: 'nope');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testConvertNotFound()

    /**
     * Converting a non-in_progress request is refused (409) without a handoff.
     *
     * @return void
     */
    public function testConvertInvalidStatus(): void
    {
        $this->signIn();
        $this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'new'];
        $this->handoffService->expects($this->never())->method('handoff');

        $response = $this->controller->convertRequestToCase(id: 'req-1');
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame('invalid-status', $response->getData()['status']);
    }//end testConvertInvalidStatus()

    /**
     * With no ns#Case implementer, conversion is refused cleanly (409).
     *
     * @return void
     */
    public function testConvertNotAvailable(): void
    {
        $this->signIn();
        $this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
        $this->handoffService->method('hasImplementer')->willReturn(false);
        $this->handoffService->expects($this->never())->method('handoff');

        $response = $this->controller->convertRequestToCase(id: 'req-1');
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame('not-available', $response->getData()['status']);
    }//end testConvertNotAvailable()

    /**
     * A failed handoff leaves the request status unchanged (502).
     *
     * @return void
     */
    public function testConvertHandoffFailedLeavesRequestUntouched(): void
    {
        $this->signIn();
        $this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
        $this->handoffService->method('hasImplementer')->willReturn(true);
        $this->handoffService->method('handoff')->willReturn(['ok' => false, 'reason' => 'handoff-failed']);

        $response = $this->controller->convertRequestToCase(id: 'req-1');
        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        // No save happened → status still in_progress.
        $this->assertSame('in_progress', $this->objectService->store['req-1']['status']);
        $this->assertCount(0, $this->objectService->saves);
    }//end testConvertHandoffFailedLeavesRequestUntouched()

    /**
     * A successful conversion sets status=converted + caseReference.
     *
     * @return void
     */
    public function testConvertSuccess(): void
    {
        $this->signIn();
        $this->objectService->store['req-1'] = [
            'uuid'       => 'req-1',
            'ticketType' => 'request',
            'status'     => 'in_progress',
            'title'      => 'Broken bin',
        ];
        $this->handoffService->method('hasImplementer')->willReturn(true);
        $this->handoffService->method('handoff')->willReturn([
            'ok'            => true,
            'targetUuid'    => 'case-42',
            'correlationId' => 'corr-1',
            'reason'        => '',
        ]);

        $response = $this->controller->convertRequestToCase(id: 'req-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('converted', $response->getData()['status']);
        $this->assertSame('case-42', $response->getData()['caseReference']);
        // Persisted, with the discriminator kept pinned on the round-tripped payload.
        $this->assertSame('converted', $this->objectService->store['req-1']['status']);
        $this->assertSame('case-42', $this->objectService->store['req-1']['caseReference']);
        $this->assertSame('request', $this->objectService->store['req-1']['ticketType']);
    }//end testConvertSuccess()

    /**
     * A ticket of another subtype is not reachable through the request
     * endpoints — it is reported as absent (404) and never converted.
     *
     * @return void
     *
     * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
     */
    public function testConvertRefusesNonRequestTicket(): void
    {
        $this->signIn();
        $this->objectService->store['cm-1'] = [
            'uuid'       => 'cm-1',
            'ticketType' => 'contactmoment',
            'status'     => 'in_progress',
        ];
        $this->handoffService->expects($this->never())->method('handoff');

        $response = $this->controller->convertRequestToCase(id: 'cm-1');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertCount(0, $this->objectService->saves);
    }//end testConvertRefusesNonRequestTicket()

    /**
     * requestAvailability reports canConvert only for in_progress + implementer.
     *
     * @return void
     */
    public function testRequestAvailability(): void
    {
        $this->signIn();
        $this->objectService->store['req-1'] = ['uuid' => 'req-1', 'ticketType' => 'request', 'status' => 'in_progress'];
        $this->handoffService->method('hasImplementer')->willReturn(true);

        $response = $this->controller->requestAvailability(id: 'req-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['available']);
        $this->assertTrue($response->getData()['canConvert']);
    }//end testRequestAvailability()

    /**
     * Sending a non-active contract to invoicing is refused (409).
     *
     * @return void
     */
    public function testSendContractInvalidStatus(): void
    {
        $this->signIn();
        $this->objectService->store['c-1'] = ['uuid' => 'c-1', 'status' => 'draft'];
        $this->handoffService->expects($this->never())->method('handoff');

        $response = $this->controller->sendContractToInvoicing(id: 'c-1');
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame('invalid-status', $response->getData()['status']);
    }//end testSendContractInvalidStatus()

    /**
     * A successful contract handoff records the invoice provenance link.
     *
     * @return void
     */
    public function testSendContractSuccess(): void
    {
        $this->signIn();
        $this->objectService->store['c-1'] = ['uuid' => 'c-1', 'status' => 'active', 'contractNumber' => 'C-2026-1'];
        $this->handoffService->method('hasImplementer')->willReturn(true);
        $this->handoffService->method('handoff')->willReturn([
            'ok'            => true,
            'targetUuid'    => 'inv-7',
            'correlationId' => 'corr-2',
            'reason'        => '',
        ]);

        $response = $this->controller->sendContractToInvoicing(id: 'c-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('sent', $response->getData()['status']);
        $this->assertSame('inv-7', $response->getData()['invoiceReference']);
        $this->assertSame('inv-7', $this->objectService->store['c-1']['invoiceReference']);
    }//end testSendContractSuccess()
}//end class
