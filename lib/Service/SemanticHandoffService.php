<?php

/**
 * Pipelinq SemanticHandoffService.
 *
 * The emit side of the ADR-051 semantic-object-handoff chains: a thin,
 * OpenRegister-absent-safe wrapper over OR's shipped handoff engine. It answers
 * "is there an installed app implementing this semantic kind?" (so the emit
 * actions hide when nobody does) via `SemanticTypeResolver`, and executes a
 * declared `x-openregister-handoff` entry via `HandoffService::execute` — which
 * resolves the implementer, maps the source through the target's binding,
 * creates the target object under the caller's RBAC and writes the two-way
 * provenance relations. Pipelinq addresses handoffs by kind URI only — never by
 * a hard-coded target app id (that is the whole point of ADR-051 over a bespoke
 * bridge). No queueing / retry / handoff log lives here; the OR engine owns
 * delivery semantics (ADR-045).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Kind-resolution + emit wrapper around OpenRegister's handoff engine.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges to two OR services
 *  behind a container; the surface is deliberately tiny.
 */
class SemanticHandoffService {
	/**
	 * FQCN of the OpenRegister semantic-type resolver (autowired in OR).
	 *
	 * @var string
	 */
	private const RESOLVER_CLASS = 'OCA\\OpenRegister\\Service\\SemanticTypeResolver';

	/**
	 * FQCN of the OpenRegister handoff engine (autowired in OR).
	 *
	 * @var string
	 */
	private const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Handoff\\HandoffService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OR services).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an installed app implements the given semantic kind.
	 *
	 * Resolves lazily through the container so pipelinq loads without OR: a
	 * missing resolver, a resolver error, or no implementing schema all yield
	 * false, which hides the emit action rather than erroring.
	 *
	 * @param string $kindUri Canonical kind URI (e.g. https://openregister.app/ns#Case).
	 *
	 * @return bool True when at least one installed+enabled schema implements the kind.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 */
	public function hasImplementer(string $kindUri): bool {
		if ($kindUri === '') {
			return false;
		}

		$resolver = $this->resolveService(fqcn: self::RESOLVER_CLASS, method: 'resolveSchemaByImplements');
		if ($resolver === null) {
			return false;
		}

		try {
			$schema = $resolver->resolveSchemaByImplements($kindUri);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffService.hasImplementer: resolver threw',
				['kind' => $kindUri, 'error' => $e->getMessage()]
			);
			return false;
		}

		return $schema !== null;
	}//end hasImplementer()

	/**
	 * Where the installed implementer of a semantic kind keeps its objects.
	 *
	 * The register and schema slugs are read from OpenRegister at run time, so
	 * no other app's id or slug is written into pipelinq: whichever installed
	 * app implements the kind answers. Null when nothing implements it, when
	 * the schema sits in no register, or when either slug is empty, so a
	 * caller shows nothing instead of pointing at a place that does not exist.
	 *
	 * @param string $kindUri Canonical kind URI (e.g. https://openregister.app/ns#Case).
	 *
	 * @return array{register: string, schema: string}|null The slugs, or null.
	 *
	 * @spec openspec/specs/ticket-decisions/spec.md#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002
	 */
	public function implementerLocation(string $kindUri): ?array {
		if ($kindUri === '') {
			return null;
		}

		$resolver = $this->resolveService(fqcn: self::RESOLVER_CLASS, method: 'resolveSchemaByImplements');
		if ($resolver === null || method_exists($resolver, 'findRegisterForSchema') === false) {
			return null;
		}

		try {
			$schema = $resolver->resolveSchemaByImplements($kindUri);
			if (is_object($schema) === false) {
				return null;
			}

			$register = $resolver->findRegisterForSchema($schema);
			if (is_object($register) === false) {
				return null;
			}

			$schemaSlug = (string)$schema->getSlug();
			$registerSlug = (string)$register->getSlug();
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffService.implementerLocation: resolver threw',
				['kind' => $kindUri, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($schemaSlug === '' || $registerSlug === '') {
			return null;
		}

		return ['register' => $registerSlug, 'schema' => $schemaSlug];
	}//end implementerLocation()

	/**
	 * Execute a declared handoff via OpenRegister's engine.
	 *
	 * Kind-addressed: the target app is resolved by OR from the entry's
	 * `targetSemanticType`; no app id is named here. Returns a normalised
	 * outcome; a missing engine, an unavailable provider, or any failure leaves
	 * the source object untouched (the caller must not mutate on `ok === false`).
	 *
	 * @param string $register Source register slug.
	 * @param string $schema Source schema slug.
	 * @param string $id Source object UUID.
	 * @param string $handoffId The declared `x-openregister-handoff` entry id.
	 *
	 * @return array{ok: bool, targetUuid: string, correlationId: string, reason: string} Outcome.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 */
	public function handoff(string $register, string $schema, string $id, string $handoffId): array {
		$engine = $this->resolveService(fqcn: self::ENGINE_CLASS, method: 'execute');
		if ($engine === null) {
			return $this->outcome(succeeded:false, reason: 'engine-unavailable');
		}

		try {
			$result = $engine->execute(register: $register, schema: $schema, id: $id, handoffId: $handoffId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffService.handoff: engine execute failed',
				['handoffId' => $handoffId, 'error' => $e->getMessage()]
			);
			return $this->outcome(succeeded:false, reason: 'handoff-failed');
		}

		if (is_array($result) === false) {
			return $this->outcome(succeeded:false, reason: 'handoff-failed');
		}

		$status = (string)($result['status'] ?? '');
		$correlationId = (string)($result['correlationId'] ?? '');

		if ($status === 'executed') {
			$target = ($result['target'] ?? []);
			$uuid = '';
			if (is_array($target) === true) {
				$uuid = (string)($target['uuid'] ?? '');
			}

			return $this->outcome(succeeded:true, targetUuid: $uuid, correlationId: $correlationId);
		}

		if ($status === 'parked') {
			return $this->outcome(succeeded:true, reason: 'queued', correlationId: $correlationId);
		}

		$reason = $status;
		if ($reason === '') {
			$reason = 'handoff-failed';
		}

		return $this->outcome(succeeded:false, reason: $reason);
	}//end handoff()

	/**
	 * Build a normalised outcome envelope.
	 *
	 * @param bool $succeeded Whether the handoff succeeded / was accepted.
	 * @param string $targetUuid Created target object UUID (executed only).
	 * @param string $correlationId Engine correlation id.
	 * @param string $reason Failure / queued reason.
	 *
	 * @return array{ok: bool, targetUuid: string, correlationId: string, reason: string}
	 */
	private function outcome(bool $succeeded, string $targetUuid = '', string $correlationId = '', string $reason = ''): array {
		return [
			'ok' => $succeeded,
			'targetUuid' => $targetUuid,
			'correlationId' => $correlationId,
			'reason' => $reason,
		];
	}//end outcome()

	/**
	 * Resolve an OpenRegister service by FQCN, guarded on container + method.
	 *
	 * The container-miss (OR absent) is caught and degrades to null, so no
	 * `class_exists` gate is needed — that also keeps the wrapper unit-testable
	 * with a stub container regardless of OR autoload state.
	 *
	 * @param string $fqcn Service FQCN.
	 * @param string $method Method that must exist on the resolved service.
	 *
	 * @return object|null The service, or null when unavailable.
	 */
	private function resolveService(string $fqcn, string $method): ?object {
		try {
			$service = $this->container->get($fqcn);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || method_exists($service, $method) === false) {
			return null;
		}

		return $service;
	}//end resolveService()
}//end class
