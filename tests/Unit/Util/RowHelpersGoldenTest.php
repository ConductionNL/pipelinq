<?php

/**
 * Golden test of the OpenRegister row helpers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use JsonSerializable;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;
use Throwable;

/**
 * Every class's toArray-like and idOf helper over one input matrix: the rows,
 * entities and values OpenRegister answers with, and values it should not. A
 * helper is called by reflection on an instance built without its constructor.
 * Compared with RowHelpersGolden.json; set GOLDEN_RECORD=1 to rewrite it, then
 * run once more without it.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\AppointmentDepositTimeoutJob
 * @covers \OCA\Pipelinq\BackgroundJob\AvailabilityCacheRefreshJob
 * @covers \OCA\Pipelinq\BackgroundJob\GenerateZReportJob
 * @covers \OCA\Pipelinq\BackgroundJob\PointsExpiryBatchJob
 * @covers \OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob
 * @covers \OCA\Pipelinq\BackgroundJob\ReminderDispatchJob
 * @covers \OCA\Pipelinq\Controller\BerichtenboxAdminController
 * @covers \OCA\Pipelinq\Controller\OpenDealsController
 * @covers \OCA\Pipelinq\Controller\PortalController
 * @covers \OCA\Pipelinq\Controller\SemanticHandoffController
 * @covers \OCA\Pipelinq\Lifecycle\PosRefundManagerGuard
 * @covers \OCA\Pipelinq\Repair\MigratePosBookkeepingToShillinq
 * @covers \OCA\Pipelinq\Repair\UnifyClientContactIdentity
 * @covers \OCA\Pipelinq\Service\AppointmentCalendarLeafProvider
 * @covers \OCA\Pipelinq\Service\AppointmentDepositService
 * @covers \OCA\Pipelinq\Service\AppointmentEmailService
 * @covers \OCA\Pipelinq\Service\AppointmentPaymentProvider
 * @covers \OCA\Pipelinq\Service\AvailabilityService
 * @covers \OCA\Pipelinq\Service\BerichtenboxService
 * @covers \OCA\Pipelinq\Service\BlastService
 * @covers \OCA\Pipelinq\Service\BookingService
 * @covers \OCA\Pipelinq\Service\BudgetService
 * @covers \OCA\Pipelinq\Service\CashShiftService
 * @covers \OCA\Pipelinq\Service\ComplianceService
 * @covers \OCA\Pipelinq\Service\ConsentService
 * @covers \OCA\Pipelinq\Service\ContactAddressLookup
 * @covers \OCA\Pipelinq\Service\CostReconciliationService
 * @covers \OCA\Pipelinq\Service\CtiContactMatcher
 * @covers \OCA\Pipelinq\Service\CtiService
 * @covers \OCA\Pipelinq\Service\Cti\IncomingCallFinder
 * @covers \OCA\Pipelinq\Service\EligibilityService
 * @covers \OCA\Pipelinq\Service\EmailMatchService
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @covers \OCA\Pipelinq\Service\GiftCardService
 * @covers \OCA\Pipelinq\Service\KassakoppelingAuditService
 * @covers \OCA\Pipelinq\Service\Labels\LabelRenameService
 * @covers \OCA\Pipelinq\Service\LinkedContactRecords
 * @covers \OCA\Pipelinq\Service\LoyaltyAccountService
 * @covers \OCA\Pipelinq\Service\LoyaltyEngineService
 * @covers \OCA\Pipelinq\Service\LoyaltyProgrammeService
 * @covers \OCA\Pipelinq\Service\LoyaltyReportingService
 * @covers \OCA\Pipelinq\Service\MailboxResolver
 * @covers \OCA\Pipelinq\Service\MessagingService
 * @covers \OCA\Pipelinq\Service\NoteEventService
 * @covers \OCA\Pipelinq\Service\PointsLedgerService
 * @covers \OCA\Pipelinq\Service\PointsRuleEngine
 * @covers \OCA\Pipelinq\Service\Portal\MainRegisterReader
 * @covers \OCA\Pipelinq\Service\Portal\PortalCleanupService
 * @covers \OCA\Pipelinq\Service\Portal\PortalObjectRepository
 * @covers \OCA\Pipelinq\Service\Portal\PortalRequestService
 * @covers \OCA\Pipelinq\Service\PosBookkeepingService
 * @covers \OCA\Pipelinq\Service\PosCustomerLinkService
 * @covers \OCA\Pipelinq\Service\PosPaymentService
 * @covers \OCA\Pipelinq\Service\PosRefundService
 * @covers \OCA\Pipelinq\Service\PosRoleService
 * @covers \OCA\Pipelinq\Service\PosStaffReportService
 * @covers \OCA\Pipelinq\Service\PosStaffService
 * @covers \OCA\Pipelinq\Service\PosTransactionService
 * @covers \OCA\Pipelinq\Service\ProductCatalogService
 * @covers \OCA\Pipelinq\Service\ReceiptDeliveryService
 * @covers \OCA\Pipelinq\Service\RedemptionService
 * @covers \OCA\Pipelinq\Service\ShillinqFxRateReader
 * @covers \OCA\Pipelinq\Service\ShillinqInvoiceReader
 * @covers \OCA\Pipelinq\Service\SmsAdapter
 * @covers \OCA\Pipelinq\Service\SnapshotGenerationService
 * @covers \OCA\Pipelinq\Service\TierService
 * @covers \OCA\Pipelinq\Service\TimeBillingHandoffService
 * @covers \OCA\Pipelinq\Service\WalkInQueueService
 * @covers \OCA\Pipelinq\Service\WebhookProcessorService
 * @covers \OCA\Pipelinq\Service\WhatsAppAdapter
 * @covers \OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade
 * @covers \OCA\Pipelinq\Util\AnyPayloadToArray
 * @covers \OCA\Pipelinq\Util\ObjectToPlainArray
 * @covers \OCA\Pipelinq\Util\CastingPayloadToArray
 * @covers \OCA\Pipelinq\Util\EntityIdOf
 * @covers \OCA\Pipelinq\Util\EntityPayload
 * @covers \OCA\Pipelinq\Util\NullablePayloadToArray
 * @covers \OCA\Pipelinq\Util\NullableRowIdOf
 * @covers \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @covers \OCA\Pipelinq\Util\PayloadToArray
 * @covers \OCA\Pipelinq\Util\RowIdOf
 * @covers \OCA\Pipelinq\Util\RowIdentity
 * @covers \OCA\Pipelinq\Util\SerialisedPayload
 * @covers \OCA\Pipelinq\Util\SerialisedToArray
 */
class RowHelpersGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * The class that declares the helper, the class to instantiate and the helper.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private const HELPERS = [
		['OCA\\Pipelinq\\BackgroundJob\\AppointmentDepositTimeoutJob', 'OCA\\Pipelinq\\BackgroundJob\\AppointmentDepositTimeoutJob', 'idOf'],
		['OCA\\Pipelinq\\BackgroundJob\\AppointmentDepositTimeoutJob', 'OCA\\Pipelinq\\BackgroundJob\\AppointmentDepositTimeoutJob', 'toArray'],
		['OCA\\Pipelinq\\BackgroundJob\\AvailabilityCacheRefreshJob', 'OCA\\Pipelinq\\BackgroundJob\\AvailabilityCacheRefreshJob', 'idOf'],
		['OCA\\Pipelinq\\BackgroundJob\\AvailabilityCacheRefreshJob', 'OCA\\Pipelinq\\BackgroundJob\\AvailabilityCacheRefreshJob', 'toArray'],
		['OCA\\Pipelinq\\BackgroundJob\\GenerateZReportJob', 'OCA\\Pipelinq\\BackgroundJob\\GenerateZReportJob', 'toArray'],
		['OCA\\Pipelinq\\BackgroundJob\\PointsExpiryBatchJob', 'OCA\\Pipelinq\\BackgroundJob\\PointsExpiryBatchJob', 'toArray'],
		['OCA\\Pipelinq\\BackgroundJob\\PosRetryBackoffJob', 'OCA\\Pipelinq\\BackgroundJob\\PosRetryBackoffJob', 'toArray'],
		['OCA\\Pipelinq\\BackgroundJob\\ReminderDispatchJob', 'OCA\\Pipelinq\\BackgroundJob\\ReminderDispatchJob', 'idOf'],
		['OCA\\Pipelinq\\Controller\\BerichtenboxAdminController', 'OCA\\Pipelinq\\Controller\\BerichtenboxAdminController', 'toArray'],
		['OCA\\Pipelinq\\Controller\\OpenDealsController', 'OCA\\Pipelinq\\Controller\\OpenDealsController', 'toArray'],
		['OCA\\Pipelinq\\Controller\\PortalController', 'OCA\\Pipelinq\\Controller\\PortalController', 'idOf'],
		['OCA\\Pipelinq\\Controller\\PortalController', 'OCA\\Pipelinq\\Controller\\PortalController', 'toArray'],
		['OCA\\Pipelinq\\Controller\\SemanticHandoffController', 'OCA\\Pipelinq\\Controller\\SemanticHandoffController', 'toArray'],
		['OCA\\Pipelinq\\Lifecycle\\PosRefundManagerGuard', 'OCA\\Pipelinq\\Lifecycle\\PosRefundManagerGuard', 'toArray'],
		['OCA\\Pipelinq\\Repair\\MigratePosBookkeepingToShillinq', 'OCA\\Pipelinq\\Repair\\MigratePosBookkeepingToShillinq', 'toArray'],
		['OCA\\Pipelinq\\Repair\\UnifyClientContactIdentity', 'OCA\\Pipelinq\\Repair\\UnifyClientContactIdentity', 'toArray'],
		['OCA\\Pipelinq\\Service\\AppointmentCalendarLeafProvider', 'OCA\\Pipelinq\\Service\\AppointmentCalendarLeafProvider', 'idOf'],
		['OCA\\Pipelinq\\Service\\AppointmentCalendarLeafProvider', 'OCA\\Pipelinq\\Service\\AppointmentCalendarLeafProvider', 'toArray'],
		['OCA\\Pipelinq\\Service\\AppointmentDepositService', 'OCA\\Pipelinq\\Service\\AppointmentDepositService', 'toArray'],
		['OCA\\Pipelinq\\Service\\AppointmentEmailService', 'OCA\\Pipelinq\\Service\\AppointmentEmailService', 'toArray'],
		['OCA\\Pipelinq\\Service\\AppointmentPaymentProvider', 'OCA\\Pipelinq\\Service\\AppointmentPaymentProvider', 'toArray'],
		['OCA\\Pipelinq\\Service\\AvailabilityService', 'OCA\\Pipelinq\\Service\\AvailabilityService', 'idOf'],
		['OCA\\Pipelinq\\Service\\BerichtenboxService', 'OCA\\Pipelinq\\Service\\BerichtenboxService', 'toArray'],
		['OCA\\Pipelinq\\Service\\BlastService', 'OCA\\Pipelinq\\Service\\BlastService', 'toArray'],
		['OCA\\Pipelinq\\Service\\BookingService', 'OCA\\Pipelinq\\Service\\BookingService', 'idOf'],
		['OCA\\Pipelinq\\Service\\BookingService', 'OCA\\Pipelinq\\Service\\BookingService', 'toArray'],
		['OCA\\Pipelinq\\Service\\BudgetService', 'OCA\\Pipelinq\\Service\\BudgetService', 'toArray'],
		['OCA\\Pipelinq\\Service\\CashShiftService', 'OCA\\Pipelinq\\Service\\CashShiftService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ComplianceService', 'OCA\\Pipelinq\\Service\\ComplianceService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ConsentService', 'OCA\\Pipelinq\\Service\\ConsentService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ContactAddressLookup', 'OCA\\Pipelinq\\Service\\ContactAddressLookup', 'toArray'],
		['OCA\\Pipelinq\\Service\\CostReconciliationService', 'OCA\\Pipelinq\\Service\\CostReconciliationService', 'toArray'],
		['OCA\\Pipelinq\\Service\\Cti\\IncomingCallFinder', 'OCA\\Pipelinq\\Service\\Cti\\IncomingCallFinder', 'toArray'],
		['OCA\\Pipelinq\\Service\\CtiContactMatcher', 'OCA\\Pipelinq\\Service\\CtiContactMatcher', 'toArray'],
		['OCA\\Pipelinq\\Service\\CtiService', 'OCA\\Pipelinq\\Service\\CtiService', 'toArray'],
		['OCA\\Pipelinq\\Service\\EligibilityService', 'OCA\\Pipelinq\\Service\\EligibilityService', 'idOf'],
		['OCA\\Pipelinq\\Service\\EligibilityService', 'OCA\\Pipelinq\\Service\\EligibilityService', 'toArray'],
		['OCA\\Pipelinq\\Service\\EmailMatchService', 'OCA\\Pipelinq\\Service\\EmailMatchService', 'idOf'],
		['OCA\\Pipelinq\\Service\\EmailMatchService', 'OCA\\Pipelinq\\Service\\EmailMatchService', 'toArray'],
		['OCA\\Pipelinq\\Service\\Export\\AbstractExportService', 'OCA\\Pipelinq\\Service\\Export\\ExportRunService', 'toArray'],
		['OCA\\Pipelinq\\Service\\GiftCardService', 'OCA\\Pipelinq\\Service\\GiftCardService', 'toArray'],
		['OCA\\Pipelinq\\Service\\KassakoppelingAuditService', 'OCA\\Pipelinq\\Service\\KassakoppelingAuditService', 'toArray'],
		['OCA\\Pipelinq\\Service\\Labels\\LabelRenameService', 'OCA\\Pipelinq\\Service\\Labels\\LabelRenameService', 'toArray'],
		['OCA\\Pipelinq\\Service\\LinkedContactRecords', 'OCA\\Pipelinq\\Service\\LinkedContactRecords', 'toArray'],
		['OCA\\Pipelinq\\Service\\LoyaltyAccountService', 'OCA\\Pipelinq\\Service\\LoyaltyAccountService', 'toArray'],
		['OCA\\Pipelinq\\Service\\LoyaltyEngineService', 'OCA\\Pipelinq\\Service\\LoyaltyEngineService', 'toArray'],
		['OCA\\Pipelinq\\Service\\LoyaltyProgrammeService', 'OCA\\Pipelinq\\Service\\LoyaltyProgrammeService', 'toArray'],
		['OCA\\Pipelinq\\Service\\LoyaltyReportingService', 'OCA\\Pipelinq\\Service\\LoyaltyReportingService', 'normalizeOptionResult'],
		['OCA\\Pipelinq\\Service\\MailboxResolver', 'OCA\\Pipelinq\\Service\\MailboxResolver', 'toArray'],
		['OCA\\Pipelinq\\Service\\MessagingService', 'OCA\\Pipelinq\\Service\\MessagingService', 'toArray'],
		['OCA\\Pipelinq\\Service\\NoteEventService', 'OCA\\Pipelinq\\Service\\NoteEventService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PointsLedgerService', 'OCA\\Pipelinq\\Service\\PointsLedgerService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PointsRuleEngine', 'OCA\\Pipelinq\\Service\\PointsRuleEngine', 'toArray'],
		['OCA\\Pipelinq\\Service\\Portal\\MainRegisterReader', 'OCA\\Pipelinq\\Service\\Portal\\MainRegisterReader', 'toArray'],
		['OCA\\Pipelinq\\Service\\Portal\\PortalCleanupService', 'OCA\\Pipelinq\\Service\\Portal\\PortalCleanupService', 'idOf'],
		['OCA\\Pipelinq\\Service\\Portal\\PortalObjectRepository', 'OCA\\Pipelinq\\Service\\Portal\\PortalObjectRepository', 'idOf'],
		['OCA\\Pipelinq\\Service\\Portal\\PortalObjectRepository', 'OCA\\Pipelinq\\Service\\Portal\\PortalObjectRepository', 'toArray'],
		['OCA\\Pipelinq\\Service\\Portal\\PortalRequestService', 'OCA\\Pipelinq\\Service\\Portal\\PortalRequestService', 'idOf'],
		['OCA\\Pipelinq\\Service\\PosBookkeepingService', 'OCA\\Pipelinq\\Service\\PosBookkeepingService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosCustomerLinkService', 'OCA\\Pipelinq\\Service\\PosCustomerLinkService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosPaymentService', 'OCA\\Pipelinq\\Service\\PosPaymentService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosRefundService', 'OCA\\Pipelinq\\Service\\PosRefundService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosRoleService', 'OCA\\Pipelinq\\Service\\PosRoleService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosStaffReportService', 'OCA\\Pipelinq\\Service\\PosStaffReportService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosStaffService', 'OCA\\Pipelinq\\Service\\PosStaffService', 'toArray'],
		['OCA\\Pipelinq\\Service\\PosTransactionService', 'OCA\\Pipelinq\\Service\\PosTransactionService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ProductCatalogService', 'OCA\\Pipelinq\\Service\\ProductCatalogService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ReceiptDeliveryService', 'OCA\\Pipelinq\\Service\\ReceiptDeliveryService', 'toArray'],
		['OCA\\Pipelinq\\Service\\RedemptionService', 'OCA\\Pipelinq\\Service\\RedemptionService', 'toArray'],
		['OCA\\Pipelinq\\Service\\ShillinqFxRateReader', 'OCA\\Pipelinq\\Service\\ShillinqFxRateReader', 'toArray'],
		['OCA\\Pipelinq\\Service\\ShillinqInvoiceReader', 'OCA\\Pipelinq\\Service\\ShillinqInvoiceReader', 'toArray'],
		['OCA\\Pipelinq\\Service\\SmsAdapter', 'OCA\\Pipelinq\\Service\\SmsAdapter', 'toArray'],
		['OCA\\Pipelinq\\Service\\SnapshotGenerationService', 'OCA\\Pipelinq\\Service\\SnapshotGenerationService', 'toArray'],
		['OCA\\Pipelinq\\Service\\TierService', 'OCA\\Pipelinq\\Service\\TierService', 'toArray'],
		['OCA\\Pipelinq\\Service\\TimeBillingHandoffService', 'OCA\\Pipelinq\\Service\\TimeBillingHandoffService', 'toArray'],
		['OCA\\Pipelinq\\Service\\WalkInQueueService', 'OCA\\Pipelinq\\Service\\WalkInQueueService', 'idOf'],
		['OCA\\Pipelinq\\Service\\WebhookProcessorService', 'OCA\\Pipelinq\\Service\\WebhookProcessorService', 'toArray'],
		['OCA\\Pipelinq\\Service\\WhatsAppAdapter', 'OCA\\Pipelinq\\Service\\WhatsAppAdapter', 'toArray'],
		['OCA\\Pipelinq\\Service\\Portal\\AbstractPortalReadFacade', 'OCA\\Pipelinq\\Service\\Portal\\PortalContractService', 'objectId'],
	];

	/**
	 * Run every helper over the matrix and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach (self::HELPERS as [$declaring, $subject, $helper]) {
			$reflection = new ReflectionClass($subject);
			$method = new ReflectionMethod($subject, (method_exists($subject, $helper) === true ? $helper : 'toArray'));
			$instance = $reflection->newInstanceWithoutConstructor();
			$outcomes = [];
			foreach ($this->inputs(method: $method) as $name => $input) {
				try {
					$outcomes[$name] = $method->invoke($instance, $input);
				} catch (Throwable $e) {
					$outcomes[$name] = 'threw '.get_class($e);
				}
			}

			$actual[$declaring.'::'.$helper] = $outcomes;
		}

		$this->assertGolden(file: __DIR__.'/RowHelpersGolden.json', actual: $actual);
	}//end testGolden()

	/**
	 * The inputs the helper's parameter type accepts.
	 *
	 * @param ReflectionMethod $method The helper.
	 *
	 * @return array<string, mixed>
	 */
	private function inputs(ReflectionMethod $method): array {
		$type = $method->getParameters()[0]->getType();
		$rows = $this->rows();
		if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
			return $rows;
		}

		return $rows + $this->entities() + ['null' => null, 'string' => 'text', 'empty string' => '', 'int' => 5, 'zero' => 0, 'false' => false];
	}//end inputs()

	/**
	 * Rows: the identity in @self, at the top, in both, as uuid or id, empty or missing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			'empty row'        => [],
			'id'               => ['id' => 'top-id'],
			'uuid'             => ['uuid' => 'top-uuid'],
			'id and uuid'      => ['uuid' => 'top-uuid', 'id' => 'top-id'],
			'int id'           => ['id' => 42],
			'zero id'          => ['id' => 0],
			'empty id'         => ['id' => '', 'uuid' => 'top-uuid'],
			'null id'          => ['id' => null, 'uuid' => 'top-uuid'],
			'self id'          => ['@self' => ['id' => 'self-id'], 'id' => 'top-id'],
			'self uuid'        => ['@self' => ['uuid' => 'self-uuid'], 'uuid' => 'top-uuid'],
			'self both'        => ['@self' => ['uuid' => 'self-uuid', 'id' => 'self-id']],
			'self int id'      => ['@self' => ['id' => 42]],
			'self int uuid'    => ['@self' => ['uuid' => 42]],
			'self empty'       => ['@self' => [], 'id' => 'top-id'],
			'self empty id'    => ['@self' => ['id' => ''], 'id' => 'top-id'],
			'self null id'     => ['@self' => ['id' => null, 'uuid' => 'self-uuid']],
			'self not array'   => ['@self' => 'text', 'id' => 'top-id'],
			'no identity'      => ['subject' => 'x'],
		];
	}//end rows()

	/**
	 * Objects: what each of jsonSerialize, getObject and toArray answers, alone and together.
	 *
	 * @return array<string, object>
	 */
	private function entities(): array {
		$plain = new stdClass();
		$plain->id = 'plain-id';
		return [
			'object plain'         => $plain,
			'object empty'         => new stdClass(),
			'json array'           => new class implements JsonSerializable {
				public function jsonSerialize(): mixed {
					return ['id' => 'json-id', 'from' => 'json'];
				}
			},
			'json empty array'     => new class implements JsonSerializable {
				public function jsonSerialize(): mixed {
					return [];
				}
			},
			'json text'            => new class implements JsonSerializable {
				public $property = 'kept';

				public function jsonSerialize(): mixed {
					return 'text';
				}
			},
			'json self uuid'       => new class implements JsonSerializable {
				public function jsonSerialize(): mixed {
					return ['@self' => ['uuid' => 'json-self-uuid'], 'id' => 'json-id'];
				}
			},
			'getObject array'      => new class {
				public function getObject(): mixed {
					return ['id' => 'object-id', 'from' => 'getObject'];
				}
			},
			'getObject text'       => new class {
				public $property = 'kept';

				public function getObject(): mixed {
					return 'text';
				}
			},
			'toArray array'        => new class {
				public function toArray(): mixed {
					return ['id' => 'array-id', 'from' => 'toArray'];
				}
			},
			'toArray text'         => new class {
				public $property = 'kept';

				public function toArray(): mixed {
					return 'text';
				}
			},
			'json text, getObject' => new class implements JsonSerializable {
				public function jsonSerialize(): mixed {
					return 'text';
				}

				public function getObject(): mixed {
					return ['from' => 'getObject'];
				}
			},
			'all three'            => new class implements JsonSerializable {
				public function jsonSerialize(): mixed {
					return ['from' => 'json'];
				}

				public function getObject(): mixed {
					return ['from' => 'getObject'];
				}

				public function toArray(): mixed {
					return ['from' => 'toArray'];
				}
			},
			'getObject, toArray'   => new class {
				public function getObject(): mixed {
					return ['from' => 'getObject'];
				}

				public function toArray(): mixed {
					return ['from' => 'toArray'];
				}
			},
			'getObject text, toArray' => new class {
				public function getObject(): mixed {
					return 'text';
				}

				public function toArray(): mixed {
					return ['from' => 'toArray'];
				}
			},
		];
	}//end entities()
}//end class
