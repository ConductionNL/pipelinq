<?php

/**
 * Pipelinq ChannelProviderRepository.
 *
 * Read-side helper for the channelProvider schema. Exposes a thin,
 * test-friendly API over OpenRegister so {@see SmsAdapter} /
 * {@see WhatsAppAdapter} can pick the right provider row without
 * duplicating OR plumbing.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ChannelProviderRepository — ordered listing + lookup helpers.
 *
 * Public entry points:
 * - listActive(kind) — every active provider of a kind, sorted by
 *   priority ascending (lower wins).
 * - findById(id) — load one provider row by UUID/slug.
 * - findByVendor(kind, vendor) — find the first active row matching
 *   both `kind` and `vendor` (used by SmsAdapter.providerHint).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.4
 */
class ChannelProviderRepository {
	/**
	 * Default channelProvider schema slug.
	 */
	private const DEFAULT_SCHEMA_SLUG = 'channelProvider';

	/**
	 * Default pipelinq register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.4
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every active provider of the given kind, sorted by priority.
	 *
	 * @param string $kind `whatsapp-cloud-api` / `whatsapp-bsp` / `sms`.
	 *
	 * @return array<int, array<string, mixed>> Provider rows.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.4
	 */
	public function listActive(string $kind): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'kind' => $kind,
						'active' => true,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ChannelProviderRepository.listActive: findAll failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			// Defence in depth — schema filter "active=true" should already
			// remove inactive rows, but some OR backends don't honour
			// boolean filters consistently.
			if (((bool)($arr['active'] ?? true)) === false) {
				continue;
			}

			$out[] = $arr;
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				$priorityA = (int)($a['priority'] ?? 100);
				$priorityB = (int)($b['priority'] ?? 100);
				return ($priorityA <=> $priorityB);
			}
		);

		return $out;
	}//end listActive()

	/**
	 * Load one provider row by id.
	 *
	 * @param string $id Provider UUID / slug.
	 *
	 * @return array<string, mixed>|null Row or null.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function findById(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ChannelProviderRepository.findById: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end findById()

	/**
	 * First active row matching kind + vendor.
	 *
	 * @param string $kind Provider kind.
	 * @param string $vendor Vendor key (twilio / messagebird / ...).
	 *
	 * @return array<string, mixed>|null Row or null.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function findByVendor(string $kind, string $vendor): ?array {
		foreach ($this->listActive(kind: $kind) as $row) {
			if ((string)($row['vendor'] ?? '') === $vendor) {
				return $row;
			}
		}

		return null;
	}//end findByVendor()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object|null Service or null.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ChannelProviderRepository.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR entity to a plain array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Register slug (app-config overridable).
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Schema slug (app-config overridable).
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'channelProvider_schema',
			''
		);

		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()
}//end class
