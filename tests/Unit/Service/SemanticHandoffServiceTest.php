<?php

/**
 * Unit tests for SemanticHandoffService.
 *
 * Covers kind-implementer resolution and the emit wrapper over OR's handoff
 * engine: implementer present/absent, OR-absent degradation, executed / parked
 * / failed / thrown execute outcomes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SemanticHandoffService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SemanticHandoffService unit coverage.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One test per resolver/engine outcome.
 */
class SemanticHandoffServiceTest extends TestCase {
	private const RESOLVER = 'OCA\\OpenRegister\\Service\\SemanticTypeResolver';
	private const ENGINE = 'OCA\\OpenRegister\\Service\\Handoff\\HandoffService';

	/**
	 * Build the service with a container resolving the given OR-service stubs.
	 *
	 * @param array<string, object> $services FQCN → stub.
	 *
	 * @return SemanticHandoffService The service under test.
	 */
	private function make(array $services): SemanticHandoffService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new SemanticHandoffService($container, $this->createMock(LoggerInterface::class));
	}//end make()

	/**
	 * A resolver stub whose resolveSchemaByImplements returns the given value.
	 *
	 * @param mixed $schema Value to return.
	 * @param bool $throws Whether the method throws.
	 *
	 * @return object The stub.
	 */
	private function resolverStub(mixed $schema, bool $throws = false): object {
		return new class($schema, $throws) {
			public function __construct(
				private mixed $schema,
				private bool $throws,
			) {
			}

			public function resolveSchemaByImplements(string $uri, ?int $registerId = null): mixed {
				if ($this->throws === true) {
					throw new \RuntimeException('resolver boom');
				}

				return $this->schema;
			}
		};
	}//end resolverStub()

	/**
	 * An engine stub whose execute returns the given array (or throws).
	 *
	 * @param array<string, mixed> $result Result to return.
	 * @param bool $throws Whether execute throws.
	 *
	 * @return object The stub.
	 */
	private function engineStub(array $result, bool $throws = false): object {
		return new class($result, $throws) {
			/** @param array<string, mixed> $result */
			public function __construct(
				private array $result,
				private bool $throws,
			) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function execute(
				string $register,
				string $schema,
				string $id,
				string $handoffId,
				bool $deferred = false,
				?string $correlationId = null,
			): array {
				if ($this->throws === true) {
					throw new \RuntimeException('engine boom');
				}

				return $this->result;
			}
		};
	}//end engineStub()

	/**
	 * hasImplementer is true when the resolver returns a schema.
	 *
	 * @return void
	 */
	public function testHasImplementerTrue(): void {
		$service = $this->make([self::RESOLVER => $this->resolverStub(schema: (object)['slug' => 'case'])]);
		$this->assertTrue($service->hasImplementer('https://openregister.app/ns#Case'));
	}//end testHasImplementerTrue()

	/**
	 * hasImplementer is false when the resolver returns null.
	 *
	 * @return void
	 */
	public function testHasImplementerFalseWhenNoSchema(): void {
		$service = $this->make([self::RESOLVER => $this->resolverStub(schema: null)]);
		$this->assertFalse($service->hasImplementer('https://openregister.app/ns#Case'));
	}//end testHasImplementerFalseWhenNoSchema()

	/**
	 * hasImplementer is false when OpenRegister is absent (container miss).
	 *
	 * @return void
	 */
	public function testHasImplementerFalseWhenOrAbsent(): void {
		$service = $this->make([]);
		$this->assertFalse($service->hasImplementer('https://openregister.app/ns#Case'));
	}//end testHasImplementerFalseWhenOrAbsent()

	/**
	 * A resolver throwable degrades to false (no escape).
	 *
	 * @return void
	 */
	public function testHasImplementerFalseOnResolverThrow(): void {
		$service = $this->make([self::RESOLVER => $this->resolverStub(schema: null, throws: true)]);
		$this->assertFalse($service->hasImplementer('https://openregister.app/ns#Case'));
	}//end testHasImplementerFalseOnResolverThrow()

	/**
	 * A resolver stub that also answers which register a schema sits in.
	 *
	 * @param string|null $schemaSlug The implementing schema's slug, null when nothing implements the kind.
	 * @param string|null $registerSlug The owning register's slug, null when the schema sits in no register.
	 * @param bool $throws Whether the register lookup throws.
	 *
	 * @return object The stub.
	 */
	private function locatingResolverStub(?string $schemaSlug, ?string $registerSlug, bool $throws = false): object {
		$entity = static fn (?string $slug): ?object => $slug === null ? null : new class($slug) {
			public function __construct(
				private string $slug,
			) {
			}

			public function getSlug(): string {
				return $this->slug;
			}
		};

		return new class($entity($schemaSlug), $entity($registerSlug), $throws) {
			public function __construct(
				private ?object $schema,
				private ?object $register,
				private bool $throws,
			) {
			}

			public function resolveSchemaByImplements(string $uri, ?int $registerId = null): ?object {
				return $this->schema;
			}

			public function findRegisterForSchema(object $schema): ?object {
				if ($this->throws === true) {
					throw new \RuntimeException('register lookup boom');
				}

				return $this->register;
			}
		};
	}//end locatingResolverStub()

	/**
	 * The location is the slugs OpenRegister answers, whatever app they belong to.
	 *
	 * @return void
	 */
	public function testImplementerLocationReturnsTheResolvedSlugs(): void {
		$service = $this->make([self::RESOLVER => $this->locatingResolverStub(schemaSlug: 'case-file', registerSlug: 'cases')]);

		$this->assertSame(
			['register' => 'cases', 'schema' => 'case-file'],
			$service->implementerLocation('https://openregister.app/ns#Case')
		);
	}//end testImplementerLocationReturnsTheResolvedSlugs()

	/**
	 * Every way the location can be unknown answers null, never half a location.
	 *
	 * @return void
	 */
	public function testImplementerLocationIsNullWhenUnknown(): void {
		$kind = 'https://openregister.app/ns#Case';
		$cases = [
			'no kind asked' => [[self::RESOLVER => $this->locatingResolverStub('case', 'cases')], ''],
			'OpenRegister absent' => [[], $kind],
			'a resolver that cannot name a register' => [[self::RESOLVER => $this->resolverStub(schema: (object)['slug' => 'case'])], $kind],
			'nothing implements the kind' => [[self::RESOLVER => $this->locatingResolverStub(null, 'cases')], $kind],
			'the schema sits in no register' => [[self::RESOLVER => $this->locatingResolverStub('case', null)], $kind],
			'the schema has no slug' => [[self::RESOLVER => $this->locatingResolverStub('', 'cases')], $kind],
			'the register has no slug' => [[self::RESOLVER => $this->locatingResolverStub('case', '')], $kind],
			'the register lookup throws' => [[self::RESOLVER => $this->locatingResolverStub('case', 'cases', throws: true)], $kind],
		];

		foreach ($cases as $name => [$services, $asked]) {
			$this->assertNull($this->make($services)->implementerLocation($asked), $name);
		}
	}//end testImplementerLocationIsNullWhenUnknown()

	/**
	 * An executed handoff returns ok + the target uuid.
	 *
	 * @return void
	 */
	public function testHandoffExecuted(): void {
		$engine = $this->engineStub(['status' => 'executed', 'correlationId' => 'corr-1', 'target' => ['uuid' => 'case-9']]);
		$service = $this->make([self::ENGINE => $engine]);

		$result = $service->handoff('pipelinq', 'request', 'req-1', 'convert-to-case');
		$this->assertTrue($result['ok']);
		$this->assertSame('case-9', $result['targetUuid']);
		$this->assertSame('corr-1', $result['correlationId']);
	}//end testHandoffExecuted()

	/**
	 * A parked handoff is ok (queued) with no target uuid yet.
	 *
	 * @return void
	 */
	public function testHandoffParked(): void {
		$service = $this->make([self::ENGINE => $this->engineStub(['status' => 'parked', 'correlationId' => 'corr-2'])]);
		$result = $service->handoff('pipelinq', 'salesContract', 'c-1', 'send-to-invoicing');
		$this->assertTrue($result['ok']);
		$this->assertSame('queued', $result['reason']);
	}//end testHandoffParked()

	/**
	 * An engine throwable (e.g. HandoffException) degrades to ok:false.
	 *
	 * @return void
	 */
	public function testHandoffFailsOnEngineThrow(): void {
		$service = $this->make([self::ENGINE => $this->engineStub([], throws: true)]);
		$result = $service->handoff('pipelinq', 'request', 'req-1', 'convert-to-case');
		$this->assertFalse($result['ok']);
		$this->assertSame('handoff-failed', $result['reason']);
	}//end testHandoffFailsOnEngineThrow()

	/**
	 * A missing engine (OR absent) degrades to ok:false.
	 *
	 * @return void
	 */
	public function testHandoffFailsWhenEngineAbsent(): void {
		$service = $this->make([]);
		$result = $service->handoff('pipelinq', 'request', 'req-1', 'convert-to-case');
		$this->assertFalse($result['ok']);
		$this->assertSame('engine-unavailable', $result['reason']);
	}//end testHandoffFailsWhenEngineAbsent()
}//end class
