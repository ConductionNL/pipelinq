<?php

/**
 * Unit tests for CtiDispositionService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CtiDispositionService (REQ-CTI-009).
 */
class CtiDispositionServiceTest extends TestCase
{
    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The app config mock.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The disposition service under test.
     *
     * @var CtiDispositionService
     */
    private CtiDispositionService $service;

    /**
     * Set up a disposition service with a mocked OpenRegister.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(
                static function (string $app, string $key, string $default): string {
                    return match ($key) {
                        'register'             => 'reg-1',
                        'contactmoment_schema' => 'sc-cm',
                        'task_schema'          => 'sc-task',
                        default                => $default,
                    };
                }
            );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        // Fluent setters return the same mock.
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->service = new CtiDispositionService(
            $container,
            $this->appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Valid outcomes are recognised; invalid ones are not.
     *
     * @return void
     */
    public function testIsValidOutcome(): void
    {
        $this->assertTrue($this->service->isValidOutcome('callback'));
        $this->assertTrue($this->service->isValidOutcome('resolved'));
        $this->assertFalse($this->service->isValidOutcome('banana'));
    }//end testIsValidOutcome()

    /**
     * An invalid outcome is rejected before any persistence.
     *
     * @return void
     */
    public function testProcessDispositionRejectsInvalidOutcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->processDisposition('cm-1', 'Subject', 'banana', 'notes');
    }//end testProcessDispositionRejectsInvalidOutcome()

    /**
     * A blank subject is rejected.
     *
     * @return void
     */
    public function testProcessDispositionRequiresSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->processDisposition('cm-1', '   ', 'resolved', 'notes');
    }//end testProcessDispositionRequiresSubject()

    /**
     * A missing contactmoment raises a runtime error.
     *
     * @return void
     */
    public function testProcessDispositionThrowsWhenContactmomentMissing(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->processDisposition('cm-x', 'Subject', 'resolved', 'notes');
    }//end testProcessDispositionThrowsWhenContactmomentMissing()

    /**
     * A "resolved" disposition updates the contactmoment and creates no task.
     *
     * @return void
     */
    public function testResolvedDispositionCreatesNoTask(): void
    {
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'cm-1'], 'channel' => 'telephony']);
        $this->objectService->method('updateObject')->willReturn(
            ['@self' => ['id' => 'cm-1'], 'dispositionOutcome' => 'resolved', 'callStatus' => 'completed']
        );

        // saveObject (task creation) must never be called for resolved.
        $this->objectService->expects($this->never())->method('saveObject');

        $result = $this->service->processDisposition('cm-1', 'Handled', 'resolved', 'done');

        $this->assertSame('completed', $result['callStatus']);
    }//end testResolvedDispositionCreatesNoTask()

    /**
     * A "callback" disposition creates a follow-up task.
     *
     * @return void
     */
    public function testCallbackDispositionCreatesTask(): void
    {
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'cm-1'], 'client' => 'client-9']);
        $this->objectService->method('updateObject')->willReturn(
            ['@self' => ['id' => 'cm-1'], 'client' => 'client-9', 'dispositionOutcome' => 'callback']
        );

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(
                    static function (array $task): bool {
                        return ($task['type'] ?? '') === 'terugbelverzoek'
                            && ($task['contactmoment'] ?? '') === 'cm-1';
                    }
                )
            )
            ->willReturn(['@self' => ['id' => 'task-1']]);

        $this->service->processDisposition('cm-1', 'Call back Friday', 'callback', 'agreed');
    }//end testCallbackDispositionCreatesTask()

    /**
     * An "escalated" disposition creates an opvolgtaak.
     *
     * @return void
     */
    public function testEscalatedDispositionCreatesFollowUpTask(): void
    {
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'cm-2']]);
        $this->objectService->method('updateObject')->willReturn(['@self' => ['id' => 'cm-2']]);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(
                    static fn(array $task): bool => ($task['type'] ?? '') === 'opvolgtaak'
                )
            )
            ->willReturn(['@self' => ['id' => 'task-2']]);

        $this->service->processDisposition('cm-2', 'Needs WMO team', 'escalated', 'transferred');
    }//end testEscalatedDispositionCreatesFollowUpTask()
}//end class
