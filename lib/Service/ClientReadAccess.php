<?php

/**
 * Pipelinq ClientReadAccess.
 *
 * The per-object read guard for endpoints about one client: whether the
 * caller may read that client, decided by OpenRegister with the caller's RBAC
 * and multitenancy on. Moved out of Customer360Controller so every client
 * endpoint guards the same way.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the caller may read one client.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
 */
class ClientReadAccess {

	/**
	 * The caller may read the client.
	 */
	public const GRANTED = 'granted';

	/**
	 * OpenRegister refused the read.
	 */
	public const DENIED = 'denied';

	/**
	 * The client does not exist.
	 */
	public const MISSING = 'missing';

	/**
	 * The read failed or the app is not configured.
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister ObjectService).
	 * @param IAppConfig $appConfig App config (register and client schema).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read the client, through OpenRegister.
	 *
	 * The read runs as the caller with RBAC and multitenancy on, so the
	 * client schema's own read rights decide. OpenRegister refuses a read
	 * the caller may not do with NotAuthorizedException (denied); a client
	 * that does not exist comes back as null or DoesNotExistException
	 * (missing). Anything else fails closed (failed), never as a grant.
	 *
	 * The call uses named arguments. The positional form
	 * `find($clientId, $register, $schema)` put the register into
	 * `?array $_extend`, raised a TypeError that the old catch swallowed, and
	 * so denied every caller, which is why every Customer 360 widget showed a
	 * 404 (pipelinq#805).
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array{0: string, 1: array<string, mixed>} One of the constants, and the client's data when granted.
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function check(string $clientId): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return [self::FAILED, []];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $clientId,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true,
			);
		} catch (NotAuthorizedException $e) {
			return [self::DENIED, []];
		} catch (Throwable $e) {
			// OCP's DoesNotExistException, matched by name.
			if (str_ends_with(get_class($e), '\\DoesNotExistException') === true) {
				return [self::MISSING, []];
			}

			$this->logger->warning(
				'ClientReadAccess: client read-guard failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return [self::FAILED, []];
		}

		if ($object === null) {
			return [self::MISSING, []];
		}

		return [self::GRANTED, (array)$object->getObject()];
	}//end check()

	/**
	 * The HTTP status and message refusing an outcome, or null when granted.
	 *
	 * @param string $access One of the constants.
	 *
	 * @return array{status: int, message: string}|null
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/customer-360/spec.md#requirement-the-customer-360-summary-follows-the-clients-read-rights-req-raf-030
	 */
	public function refusal(string $access): ?array {
		return match ($access) {
			self::GRANTED => null,
			self::DENIED => ['status' => Http::STATUS_FORBIDDEN, 'message' => 'Forbidden'],
			self::MISSING => ['status' => Http::STATUS_NOT_FOUND, 'message' => 'Client not found'],
			default => ['status' => Http::STATUS_INTERNAL_SERVER_ERROR, 'message' => 'Operation failed'],
		};
	}//end refusal()
}//end class
