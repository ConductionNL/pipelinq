<?php

/**
 * Pipelinq SmsProviderFactory.
 *
 * Instantiates the right concrete SMS provider client given a
 * channelProvider row (vendor + credentials). Keeps SmsAdapter free
 * of vendor branching.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * SmsProviderFactory — channelProvider-row → vendor client.
 *
 * Public entry points:
 * - create(channelProvider) — returns a concrete client matching
 *   the row's `vendor`, or null when the vendor is not yet
 *   supported.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
 */
class SmsProviderFactory {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a vendor client for a channelProvider row.
	 *
	 * @param array<string, mixed> $channelProvider channelProvider row.
	 *
	 * @return SmsProviderClientInterface|null Client or null when the
	 *                                         vendor key is not supported.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#3.2
	 */
	public function create(array $channelProvider): ?SmsProviderClientInterface {
		$vendor = (string)($channelProvider['vendor'] ?? '');
		$fromNumber = (string)($channelProvider['phoneNumber'] ?? '');
		$secret = (string)($channelProvider['webhookSecret'] ?? '');
		$sourceId = (string)($channelProvider['sourceId'] ?? ($channelProvider['openconnectorSourceId'] ?? ''));
		$credentials = (array)($channelProvider['credentials'] ?? []);

		$sourceIdOrNull = $sourceId;
		if ($sourceId === '') {
			$sourceIdOrNull = null;
		}

		return match ($vendor) {
			'twilio' => new TwilioSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			'messagebird' => new MessageBirdSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			'cm-com' => new CmComSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			default => null,
		};// End match.
	}//end create()
}//end class
