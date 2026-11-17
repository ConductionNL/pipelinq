<?php

/**
 * Unit tests for McpTransition.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Mcp\McpTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Tests that a lifecycle transition reaches the engine and its refusals come
 * back as the right MCP envelope.
 *
 * @spec openspec/changes/mcp-full-action-coverage/specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action
 */
#[CoversClass(McpTransition::class)]
#[UsesClass(McpAnswer::class)]
class McpTransitionTest extends TestCase {

	/**
	 * The calls the fake engine received, as [objectId, action].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $calls = [];

	/**
	 * A transition helper over an engine that answers with `$outcome`.
	 *
	 * @param ObjectEntity|Throwable $outcome The moved object, or what the engine throws.
	 *
	 * @return McpTransition
	 */
	private function transitionOver(ObjectEntity|Throwable $outcome): McpTransition {
		$calls = &$this->calls;
		$engine = new class($outcome, $calls) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity|Throwable                   $outcome The answer.
			 * @param list<array{0: string, 1: string}>        $calls   The recorded calls.
			 */
			public function __construct(private ObjectEntity|Throwable $outcome, private array &$calls) {
			}

			/**
			 * The engine's transition().
			 *
			 * @param string $objectId The object.
			 * @param string $action   The transition.
			 *
			 * @return ObjectEntity The moved object.
			 */
			public function transition(string $objectId, string $action): ObjectEntity {
				$this->calls[] = [$objectId, $action];
				if ($this->outcome instanceof Throwable) {
					throw $this->outcome;
				}

				return $this->outcome;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine')
			->willReturn($engine);

		return new McpTransition(container: $container, mcp: new McpAnswer(new NullLogger()), logger: new NullLogger());
	}//end transitionOver()

	/**
	 * A successful move answers the moved object under its subject, and the engine got the id and action.
	 *
	 * @return void
	 */
	public function testSuccessAnswersTheMovedObject(): void {
		$moved = new ObjectEntity();
		$moved->setObject(['id' => 'lead-1', 'status' => 'won']);

		$result = $this->transitionOver(outcome: $moved)->run(objectId: ' lead-1 ', action: 'win', subject: 'lead');

		$this->assertSame(['lead' => ['id' => 'lead-1', 'status' => 'won']], $result);
		$this->assertSame([['lead-1', 'win']], $this->calls);
	}//end testSuccessAnswersTheMovedObject()

	/**
	 * A blank id is refused before the engine is asked.
	 *
	 * @return void
	 */
	public function testBlankIdIsInvalidAndNeverReachesTheEngine(): void {
		$result = $this->transitionOver(outcome: new ObjectEntity())->run(objectId: '  ', action: 'win', subject: 'lead');

		$this->assertSame('invalid_arguments', $result['error']['code']);
		$this->assertSame([], $this->calls);
	}//end testBlankIdIsInvalidAndNeverReachesTheEngine()

	/**
	 * The engine's authorization refusal is `forbidden`.
	 *
	 * @return void
	 */
	public function testNotAuthorizedIsForbidden(): void {
		$result = $this->transitionOver(
			outcome: new NotAuthorizedException('You do not have permission to transition object "t-1".')
		)->run(objectId: 't-1', action: 'reject', subject: 'ticket');

		$this->assertSame(['error' => ['code' => 'forbidden', 'message' => 'You are not allowed to reject this ticket.']], $result);
	}//end testNotAuthorizedIsForbidden()

	/**
	 * A move from a state the transition does not leave is `conflict`, with the engine's reason.
	 *
	 * @return void
	 */
	public function testWrongFromStateIsConflict(): void {
		$result = $this->transitionOver(
			outcome: new RuntimeException('Transition "win" is not allowed from current state "won".')
		)->run(objectId: 'lead-1', action: 'win', subject: 'lead');

		$this->assertSame('conflict', $result['error']['code']);
		$this->assertSame('Transition "win" is not allowed from current state "won".', $result['error']['message']);
	}//end testWrongFromStateIsConflict()

	/**
	 * A missing object is `not_found`.
	 *
	 * @return void
	 */
	public function testMissingObjectIsNotFound(): void {
		$result = $this->transitionOver(outcome: new RuntimeException('Object "x" not found.'))
			->run(objectId: 'x', action: 'start', subject: 'ticket');

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'No ticket with that id.']], $result);
	}//end testMissingObjectIsNotFound()

	/**
	 * Any other failure is an `internal_error` that keeps the detail out of the answer.
	 *
	 * @return void
	 */
	public function testOtherFailureIsInternalErrorWithoutDetail(): void {
		$result = $this->transitionOver(outcome: new RuntimeException('SQLSTATE[HY000] connection lost'))
			->run(objectId: 'x', action: 'close', subject: 'ticket');

		$this->assertSame('internal_error', $result['error']['code']);
		$this->assertStringNotContainsString('SQLSTATE', $result['error']['message']);
	}//end testOtherFailureIsInternalErrorWithoutDetail()

	/**
	 * Without OpenRegister's engine the tool answers `not_configured`.
	 *
	 * @return void
	 */
	public function testMissingEngineIsNotConfigured(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$transition = new McpTransition(container: $container, mcp: new McpAnswer(new NullLogger()), logger: new NullLogger());

		$result = $transition->run(objectId: 'lead-1', action: 'win', subject: 'lead');

		$this->assertSame('not_configured', $result['error']['code']);
	}//end testMissingEngineIsNotConfigured()
}//end class
