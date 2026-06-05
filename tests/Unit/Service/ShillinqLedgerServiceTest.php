<?php

/**
 * Unit tests for ShillinqLedgerService.
 *
 * Asserts the should-dispatch gate (HTTPS-only), the CloudEvents 1.0 payload
 * shapes for project creation and status-change events, the status-to-phase
 * mapping, and the success/failure return contract against a capturing
 * WebhookService double.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ShillinqLedgerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake WebhookService capturing dispatched CloudEvents.
 */
class FakeLedgerWebhookService
{
    /**
     * Captured events.
     *
     * @var array<int, array{eventName: string, payload: array<string, mixed>}>
     */
    public array $events = [];

    /**
     * Whether dispatchEvent should throw to simulate an unavailable consumer.
     *
     * @var bool
     */
    public bool $throw = false;

    /**
     * Capture (or throw on) a dispatched event.
     *
     * @param object               $_event    The originating event.
     * @param string               $eventName The webhook event name.
     * @param array<string, mixed> $payload   The CloudEvent payload.
     *
     * @return void
     */
    public function dispatchEvent(object $_event, string $eventName, array $payload): void
    {
        if ($this->throw === true) {
            throw new \RuntimeException('no consumer');
        }

        $this->events[] = ['eventName' => $eventName, 'payload' => $payload];
    }//end dispatchEvent()
}//end class

/**
 * Tests for ShillinqLedgerService.
 */
class ShillinqLedgerServiceTest extends TestCase
{
    /**
     * The capturing webhook double.
     *
     * @var FakeLedgerWebhookService
     */
    private FakeLedgerWebhookService $webhooks;

    /**
     * Build a service whose app-config returns the given webhook URL.
     *
     * @param string $webhookUrl The configured shillinq_ledger_webhook_url value.
     *
     * @return ShillinqLedgerService The service under test.
     */
    private function makeService(string $webhookUrl): ShillinqLedgerService
    {
        $this->webhooks = new FakeLedgerWebhookService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($webhookUrl): string {
                if ($key === 'shillinq_ledger_webhook_url') {
                    return $webhookUrl;
                }

                return $default;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\WebhookService') {
                    return $this->webhooks;
                }

                throw new \RuntimeException('unknown service '.$id);
            }
        );

        return new ShillinqLedgerService(
            appConfig: $appConfig,
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end makeService()

    /**
     * A valid HTTPS URL enables dispatch.
     *
     * @return void
     */
    public function testShouldDispatchTrueForHttpsUrl(): void
    {
        $service = $this->makeService('https://shillinq.example.com/ledger');
        $this->assertTrue($service->shouldDispatch());
    }//end testShouldDispatchTrueForHttpsUrl()

    /**
     * An empty URL disables dispatch.
     *
     * @return void
     */
    public function testShouldDispatchFalseForEmptyUrl(): void
    {
        $service = $this->makeService('');
        $this->assertFalse($service->shouldDispatch());
    }//end testShouldDispatchFalseForEmptyUrl()

    /**
     * A non-HTTPS or malformed URL disables dispatch.
     *
     * @return void
     */
    public function testShouldDispatchFalseForNonHttpsOrInvalidUrl(): void
    {
        $this->assertFalse($this->makeService('http://shillinq.example.com')->shouldDispatch());
        $this->assertFalse($this->makeService('not-a-url')->shouldDispatch());
        $this->assertFalse($this->makeService('http://')->shouldDispatch());
    }//end testShouldDispatchFalseForNonHttpsOrInvalidUrl()

    /**
     * The status-to-phase mapping covers every known status and falls through.
     *
     * @return void
     */
    public function testMapStatusToPhase(): void
    {
        $service = $this->makeService('https://x.example.com');
        $this->assertSame('initial', $service->mapStatusToPhase('open'));
        $this->assertSame('active', $service->mapStatusToPhase('in_progress'));
        $this->assertSame('closed', $service->mapStatusToPhase('completed'));
        $this->assertSame('cancelled', $service->mapStatusToPhase('cancelled'));
        $this->assertSame('unknown', $service->mapStatusToPhase('unknown'));
    }//end testMapStatusToPhase()

    /**
     * A project creation event builds the documented CloudEvents payload.
     *
     * @return void
     */
    public function testDispatchProjectEventPayload(): void
    {
        $service = $this->makeService('https://shillinq.example.com/ledger');
        $project = [
            'uuid'         => 'proj-123',
            'name'         => 'Digitalisering',
            'client'       => 'client-9',
            'status'       => 'in_progress',
            'billable'     => true,
            'budgetAmount' => 56000.0,
            'budgetHours'  => 400,
            'startDate'    => '2026-02-01',
            'endDate'      => '2026-08-31',
            'owner'        => 'jdoe',
            'createdAt'    => '2026-02-01T10:00:00Z',
        ];

        $result = $service->dispatchProjectEvent($project, 'created');

        $this->assertTrue($result);
        $this->assertCount(1, $this->webhooks->events);

        $event = $this->webhooks->events[0];
        $this->assertSame('nl.conduction.pipelinq.project.created', $event['eventName']);

        $payload = $event['payload'];
        $this->assertSame('1.0', $payload['specversion']);
        $this->assertSame('nl.conduction.pipelinq.project.created', $payload['type']);
        $this->assertSame('/apps/pipelinq/projects', $payload['source']);
        $this->assertSame('proj-123', $payload['id']);

        $data = $payload['data'];
        $this->assertSame('proj-123', $data['projectId']);
        $this->assertSame('Digitalisering', $data['projectName']);
        $this->assertSame('client-9', $data['clientId']);
        $this->assertSame('active', $data['phase']);
        $this->assertSame('in_progress', $data['status']);
        $this->assertTrue($data['billable']);
        $this->assertSame(56000.0, $data['budgetAmount']);
        $this->assertSame(400.0, $data['budgetHours']);
        $this->assertSame('jdoe', $data['createdBy']);
    }//end testDispatchProjectEventPayload()

    /**
     * A status-change event carries old/new status and the mapped phase.
     *
     * @return void
     */
    public function testDispatchPhaseChangeEventPayload(): void
    {
        $service = $this->makeService('https://shillinq.example.com/ledger');
        $project = [
            'uuid'         => 'proj-7',
            'name'         => 'Website',
            'client'       => 'client-2',
            'billable'     => true,
            'budgetAmount' => 19200.0,
        ];

        $result = $service->dispatchPhaseChangeEvent($project, 'open', 'in_progress');

        $this->assertTrue($result);
        $event = $this->webhooks->events[0];
        $this->assertSame('nl.conduction.pipelinq.project.status-changed', $event['eventName']);

        $data = $event['payload']['data'];
        $this->assertSame('open', $data['oldStatus']);
        $this->assertSame('in_progress', $data['newStatus']);
        $this->assertSame('active', $data['phase']);
        $this->assertSame('proj-7', $data['projectId']);
        $this->assertSame('Website', $data['projectName']);
    }//end testDispatchPhaseChangeEventPayload()

    /**
     * An unconfigured webhook URL means no dispatch and a false return.
     *
     * @return void
     */
    public function testDispatchNoopWhenUnconfigured(): void
    {
        $service = $this->makeService('');
        $this->assertFalse($service->dispatchProjectEvent(['uuid' => 'x'], 'created'));
        $this->assertCount(0, $this->webhooks->events);
    }//end testDispatchNoopWhenUnconfigured()

    /**
     * A WebhookService failure surfaces as a false return.
     *
     * @return void
     */
    public function testDispatchReturnsFalseOnWebhookFailure(): void
    {
        $service = $this->makeService('https://shillinq.example.com/ledger');
        $this->webhooks->throw = true;
        $this->assertFalse($service->dispatchProjectEvent(['uuid' => 'x', 'status' => 'open'], 'created'));
    }//end testDispatchReturnsFalseOnWebhookFailure()
}//end class
