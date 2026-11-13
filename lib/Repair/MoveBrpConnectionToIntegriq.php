<?php

/**
 * Pipelinq MoveBrpConnectionToIntegriq repair step.
 *
 * Hands pipelinq's BRP connection settings to integriq's `brp-haalcentraal`
 * source once, on upgrade: the base URL, OAuth endpoint and client id as plain
 * settings, the client secret and the PEM certificate, key and CA bundle as
 * secret material integriq stores in keepiq. pipelinq's keys are cleared only
 * after integriq has answered every part and taken or kept each one; otherwise
 * they stay and the report says why. Without integriq, or without settings, it
 * does nothing. It never logs a secret.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves the BRP connection settings to integriq; idempotent.
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */
class MoveBrpConnectionToIntegriq implements IRepairStep {

	/**
	 * The plain settings, by app config key.
	 */
	private const SETTING_KEYS = [
		'brp.base_url'       => 'baseUrl',
		'brp.oauth_endpoint' => 'oauthEndpoint',
		'brp.client_id'      => 'clientId',
	];

	/**
	 * The certificate files, by app config key: each holds a path on this server.
	 */
	private const FILE_KEYS = [
		'brp.cert_path' => 'certificate',
		'brp.key_path'  => 'privateKey',
		'brp.ca_bundle' => 'caBundle',
	];

	/**
	 * The encrypted client secret.
	 */
	private const SECRET_KEY = 'brp.client_secret_encrypted';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig       $appConfig  Holds pipelinq's BRP keys.
	 * @param ICrypto          $crypto     Decrypts the client secret.
	 * @param IEventDispatcher $dispatcher Carries the handover to integriq.
	 * @param IAppManager      $appManager Tells whether integriq is installed.
	 * @param LoggerInterface  $logger     Logs the outcome, never a secret.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function getName(): string {
		return 'Move the BRP connection settings to integriq (brp-monitor-moves-to-integriq)';
	}//end getName()

	/**
	 * Hand the settings over, report per key, and clear the keys once integriq confirms.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function run(IOutput $output): void {
		$values = $this->readKeys();
		if (array_filter($values, static fn (string $value): bool => $value !== '') === []) {
			return;
		}

		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			$this->logger->info('MoveBrpConnectionToIntegriq: integriq is not installed; the BRP connection settings stay in pipelinq');
			$output->warning('integriq is not installed: the BRP connection settings stay in pipelinq until it is.');
			return;
		}

		$problems = [];
		$event    = new BrpConnectionHandoverEvent(
			settings: $this->settings(values: $values),
			secrets: $this->secrets(values: $values, problems: $problems)
		);

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error('MoveBrpConnectionToIntegriq: the handover to integriq failed: '.$e::class);
			$output->warning('The handover to integriq failed; the BRP connection settings stay in pipelinq.');
			return;
		}

		$this->report(event: $event, values: $values, problems: $problems, output: $output);

		if ($problems !== [] || $event->isConfirmed() === false) {
			$output->warning('The BRP connection keys stay in pipelinq until integriq confirms every part.');
			return;
		}

		foreach (array_keys($values) as $key) {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}

		$this->logger->info('MoveBrpConnectionToIntegriq: integriq confirmed every part; the BRP connection keys are cleared');
		$output->info('integriq confirmed every part; the BRP connection keys are cleared from pipelinq.');
	}//end run()

	/**
	 * Every BRP connection key and its stored value.
	 *
	 * @return array<string, string> The values, by key; empty when not set.
	 */
	private function readKeys(): array {
		$keys   = array_merge(array_keys(self::SETTING_KEYS), [self::SECRET_KEY], array_keys(self::FILE_KEYS));
		$values = [];
		foreach ($keys as $key) {
			$values[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		return $values;
	}//end readKeys()

	/**
	 * The plain settings that are set, by part.
	 *
	 * @param array<string, string> $values The stored values, by key.
	 *
	 * @return array<string, string> The settings.
	 */
	private function settings(array $values): array {
		$settings = [];
		foreach (self::SETTING_KEYS as $key => $part) {
			if ($values[$key] !== '') {
				$settings[$part] = $values[$key];
			}
		}

		return $settings;
	}//end settings()

	/**
	 * The secret material that is set and readable, by part. A secret that is
	 * set but cannot be read is left out and named in the problems.
	 *
	 * @param array<string, string> $values   The stored values, by key.
	 * @param array<string, string> $problems Collects why a key could not be handed over, by key.
	 *
	 * @return array<string, string> The secrets.
	 */
	private function secrets(array $values, array &$problems): array {
		$secrets = [];
		if ($values[self::SECRET_KEY] !== '') {
			try {
				$secrets['clientSecret'] = $this->crypto->decrypt($values[self::SECRET_KEY]);
			} catch (Throwable $e) {
				$problems[self::SECRET_KEY] = 'the stored client secret cannot be decrypted';
			}
		}

		foreach (self::FILE_KEYS as $key => $part) {
			if ($values[$key] === '') {
				continue;
			}

			$pem = $this->readFile(path: $values[$key]);
			if ($pem === null) {
				$problems[$key] = 'the file it names cannot be read';
				continue;
			}

			$secrets[$part] = $pem;
		}

		return $secrets;
	}//end secrets()

	/**
	 * The contents of a certificate file, or null when it cannot be read or is empty.
	 *
	 * @param string $path The path on this server.
	 *
	 * @return string|null The contents.
	 */
	private function readFile(string $path): ?string {
		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		$contents = file_get_contents($path);
		if ($contents === false || trim($contents) === '') {
			return null;
		}

		return $contents;
	}//end readFile()

	/**
	 * Report the outcome per key that is set, naming parts but never values.
	 *
	 * @param BrpConnectionHandoverEvent $event    The answered handover.
	 * @param array<string, string>      $values   The stored values, by key.
	 * @param array<string, string>      $problems Why a key could not be handed over, by key.
	 * @param IOutput                    $output   The output.
	 *
	 * @return void
	 */
	private function report(BrpConnectionHandoverEvent $event, array $values, array $problems, IOutput $output): void {
		$parts = array_merge(self::SETTING_KEYS, [self::SECRET_KEY => 'clientSecret'], self::FILE_KEYS);
		foreach ($parts as $key => $part) {
			if ($values[$key] === '') {
				continue;
			}

			if (isset($problems[$key]) === true) {
				$output->warning($key.': not handed over, '.$problems[$key]);
				continue;
			}

			$answer = $event->getAnswer($part);
			if ($answer === null) {
				$output->warning($key.': integriq did not answer');
				continue;
			}

			$line = $key.': '.$this->describe(outcome: $answer['outcome']);
			if ($answer['detail'] !== '') {
				$line .= ' ('.$answer['detail'].')';
			}

			if ($answer['outcome'] === BrpConnectionHandoverEvent::TAKEN) {
				$output->info($line);
				continue;
			}

			$output->warning($line);
		}//end foreach
	}//end report()

	/**
	 * How an outcome reads in the report.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return string The wording.
	 */
	private function describe(string $outcome): string {
		return match ($outcome) {
			BrpConnectionHandoverEvent::TAKEN => 'taken by integriq',
			BrpConnectionHandoverEvent::KEPT => 'integriq kept the value it already had (conflict)',
			default => 'integriq could not take it',
		};
	}//end describe()
}//end class
