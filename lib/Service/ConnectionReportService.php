<?php

/**
 * Pipelinq ConnectionReportService.
 *
 * Tells integriq's connection registry what a pipelinq check observed about
 * one of the connections `lib/Settings/connections.json` declares. Integriq
 * owns the rows the Integrations page lists and works out each status itself
 * (hydra change connection-registry, design D4). Pipelinq only reports what
 * it alone can see: whether a CTI adapter loads, and how ready each social
 * network is in OpenRegister's credential broker.
 *
 * The hand-off is a same-instance typed event (ADR-041). The event class is a
 * string constant behind `class_exists()`, because a `use` of an Integriq
 * class would fatal at autoload on an instance without Integriq.
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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection status reports to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
 */
class ConnectionReportService {

	/**
	 * Integriq's report event (ADR-041). Named by string so pipelinq stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * The connection keys `lib/Settings/connections.json` declares, in order.
	 * A key outside this set is a caller's typo, not a new connection: adding
	 * one means declaring it in that file too. A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'cti',
		'social-mastodon',
		'social-bluesky',
		'social-linkedin',
		'social-x',
		'social-facebook',
		'social-instagram',
		'social-threads',
		'berichtenbox',
		'mail-provider',
	];

	/**
	 * The five states the registry accepts (hydra design D3). Anything else is
	 * refused here, so a typo never travels to integriq only to be dropped.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'configured',
		'unconfigured',
		'simulated',
		'unavailable',
		'error',
	];

	/**
	 * The broker's readiness state mapped onto a registry status.
	 *
	 * `preview` reads `unavailable`. A preview network is attempted and the
	 * network may refuse the post, and the registry has no status for "works
	 * in part". Understating a network that sometimes works beats overstating
	 * one that often fails (design D5, amendment 2).
	 *
	 * @var array<string, string>
	 */
	public const READINESS_STATUS = [
		SocialBrokerGateway::READY => 'configured',
		SocialBrokerGateway::PREVIEW => 'unavailable',
		SocialBrokerGateway::NOT_CONFIGURED => 'unconfigured',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq event (ADR-041).
	 * @param LoggerInterface $logger Records a report that could not be sent.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report one connection's status to integriq.
	 *
	 * Never throws: this runs beside a check whose own answer is what the
	 * caller returns, and a report that cannot be delivered must not turn that
	 * answer into a 500. Without integriq nothing is sent and nothing is
	 * logged, because a missing optional app is not a fault.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $message What the check observed.
	 *
	 * @return bool True when the report was dispatched.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function report(string $key, string $status, string $message = ''): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				'Pipelinq: refusing to report an undeclared connection key',
				['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				'Pipelinq: refusing to report an unknown connection status',
				['key' => $key, 'status' => $status]
			);
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$event = new $eventClass(
				app: Application::APP_ID,
				key: $key,
				status: $status,
				message: $message,
			);
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not send a connection report to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end report()

	/**
	 * Report the outcome of the CTI Test connection for the `cti` row.
	 *
	 * The check resolves the chosen platform's adapter and makes no call to the
	 * platform, so a passing check says exactly that and no more.
	 *
	 * @param array<string, mixed> $outcome `{ok, platform, message}` from CtiService::testConnection().
	 *
	 * @return bool True when the report was dispatched.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function reportCtiCheck(array $outcome): bool {
		$platform = trim((string)($outcome['platform'] ?? ''));

		if (($outcome['ok'] ?? false) === true) {
			return $this->report(
				key: 'cti',
				status: 'configured',
				message: 'The ' . $platform . ' adapter loads. This check makes no call to the platform.'
			);
		}

		if ($platform === '') {
			return $this->report(key: 'cti', status: 'unconfigured', message: 'No telephony platform is chosen.');
		}

		$reason = trim((string)($outcome['message'] ?? ''));
		return $this->report(
			key: 'cti',
			status: 'error',
			message: trim('The ' . $platform . ' adapter does not load. ' . $reason)
		);
	}//end reportCtiCheck()

	/**
	 * Report every social network's broker readiness for its `social-*` row.
	 *
	 * @param array<string, mixed> $readiness Network => `{state, reason}`, from SocialAdapterRegistry::readiness().
	 *
	 * @return array<int, string> The connection keys a report was dispatched for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function reportSocialReadiness(array $readiness): array {
		$sent = [];
		foreach ($readiness as $network => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$key    = 'social-' . (string)$network;
			$status = (self::READINESS_STATUS[(string)($entry['state'] ?? '')] ?? null);
			if ($status === null || in_array($key, self::KEYS, true) === false) {
				continue;
			}

			$message = trim((string)($entry['reason'] ?? ''));
			if ($message === '' && $status === 'configured') {
				$message = 'A developer application is filed for this network in the credential broker.';
			}

			if ($this->report(key: $key, status: $status, message: $message) === true) {
				$sent[] = $key;
			}
		}

		return $sent;
	}//end reportSocialReadiness()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()
}//end class
