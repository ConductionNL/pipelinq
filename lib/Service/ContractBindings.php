<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Pipelinq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\ActivityTimelineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\AnalyticsServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\AppointmentDepositServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\AppointmentEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ArticleServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\AttributionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\AvailabilityServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BerichtenboxServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BlastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BookingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BudgetServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CashShiftServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ConsentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CronExpressionHelperInterface;
use OCA\Pipelinq\Kernel\Contracts\CtiServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\DemoSeedServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EligibilityServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EmailMatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EntityActivityServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportDestinationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\GiftCardServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCA\Pipelinq\Kernel\Contracts\KassakoppelingAuditServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LogiusConnectorInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyReportingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MailingListServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MessagingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NaviServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PointsLedgerServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalRequestServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosBookkeepingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosCustomerLinkServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosPaymentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosRefundServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosStaffServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosTenderServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosTransactionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProgrammePortfolioServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RapportageServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReceiptDeliveryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RedemptionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReportingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RoutingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ScheduledTaskServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaAttainmentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SmsAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\SnapshotGenerationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SocialAccountServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SocialPostServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SurveyDispatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TierServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TimeBillingHandoffServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TrackingLinkServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WalkInQueueServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WebhookProcessorServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WhatsAppAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\XWikiServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ZgwApiClientInterface;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Pipelinq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ActivityTimelineServiceInterface::class, ActivityTimelineService::class);
		$context->registerServiceAlias(AnalyticsServiceInterface::class, AnalyticsService::class);
		$context->registerServiceAlias(AppointmentDepositServiceInterface::class, AppointmentDepositService::class);
		$context->registerServiceAlias(AppointmentEmailServiceInterface::class, AppointmentEmailService::class);
		$context->registerServiceAlias(ArticleServiceInterface::class, ArticleService::class);
		$context->registerServiceAlias(AttributionServiceInterface::class, AttributionService::class);
		$context->registerServiceAlias(AvailabilityServiceInterface::class, AvailabilityService::class);
		$context->registerServiceAlias(BerichtenboxServiceInterface::class, BerichtenboxService::class);
		$context->registerServiceAlias(BlastServiceInterface::class, BlastService::class);
		$context->registerServiceAlias(BookingServiceInterface::class, BookingService::class);
		$context->registerServiceAlias(BudgetServiceInterface::class, BudgetService::class);
		$context->registerServiceAlias(CashShiftServiceInterface::class, CashShiftService::class);
		$context->registerServiceAlias(ComplianceServiceInterface::class, ComplianceService::class);
		$context->registerServiceAlias(ConsentServiceInterface::class, ConsentService::class);
		$context->registerServiceAlias(CronExpressionHelperInterface::class, CronExpressionHelper::class);
		$context->registerServiceAlias(CtiServiceInterface::class, CtiService::class);
		$context->registerServiceAlias(DemoSeedServiceInterface::class, DemoSeedService::class);
		$context->registerServiceAlias(EligibilityServiceInterface::class, EligibilityService::class);
		$context->registerServiceAlias(EmailMatchServiceInterface::class, EmailMatchService::class);
		$context->registerServiceAlias(EntityActivityServiceInterface::class, EntityActivityService::class);
		$context->registerServiceAlias(EventRegistrationServiceInterface::class, EventRegistrationService::class);
		$context->registerServiceAlias(ExportDestinationServiceInterface::class, ExportDestinationService::class);
		$context->registerServiceAlias(ExportJobServiceInterface::class, ExportJobService::class);
		$context->registerServiceAlias(GiftCardServiceInterface::class, GiftCardService::class);
		$context->registerServiceAlias(HaalCentraalClientInterface::class, HaalCentraalClient::class);
		$context->registerServiceAlias(KassakoppelingAuditServiceInterface::class, KassakoppelingAuditService::class);
		$context->registerServiceAlias(LogiusConnectorInterface::class, LogiusConnector::class);
		$context->registerServiceAlias(LoyaltyEngineServiceInterface::class, LoyaltyEngineService::class);
		$context->registerServiceAlias(LoyaltyReportingServiceInterface::class, LoyaltyReportingService::class);
		$context->registerServiceAlias(MailingListServiceInterface::class, MailingListService::class);
		$context->registerServiceAlias(MessagingServiceInterface::class, MessagingService::class);
		$context->registerServiceAlias(NaviServiceInterface::class, NaviService::class);
		$context->registerServiceAlias(NotificationServiceInterface::class, NotificationService::class);
		$context->registerServiceAlias(PointsLedgerServiceInterface::class, PointsLedgerService::class);
		$context->registerServiceAlias(PortalRequestServiceInterface::class, PortalRequestService::class);
		$context->registerServiceAlias(PosBookkeepingServiceInterface::class, PosBookkeepingService::class);
		$context->registerServiceAlias(PosCustomerLinkServiceInterface::class, PosCustomerLinkService::class);
		$context->registerServiceAlias(PosPaymentServiceInterface::class, PosPaymentService::class);
		$context->registerServiceAlias(PosRefundServiceInterface::class, PosRefundService::class);
		$context->registerServiceAlias(PosStaffServiceInterface::class, PosStaffService::class);
		$context->registerServiceAlias(PosTenderServiceInterface::class, PosTenderService::class);
		$context->registerServiceAlias(PosTransactionServiceInterface::class, PosTransactionService::class);
		$context->registerServiceAlias(ProductCatalogServiceInterface::class, ProductCatalogService::class);
		$context->registerServiceAlias(ProgrammePortfolioServiceInterface::class, ProgrammePortfolioService::class);
		$context->registerServiceAlias(RapportageServiceInterface::class, RapportageService::class);
		$context->registerServiceAlias(ReceiptDeliveryServiceInterface::class, ReceiptDeliveryService::class);
		$context->registerServiceAlias(RedemptionServiceInterface::class, RedemptionService::class);
		$context->registerServiceAlias(ReportingServiceInterface::class, ReportingService::class);
		$context->registerServiceAlias(RoutingServiceInterface::class, RoutingService::class);
		$context->registerServiceAlias(ScheduledTaskServiceInterface::class, ScheduledTaskService::class);
		$context->registerServiceAlias(SegmentServiceInterface::class, SegmentService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(SlaAttainmentServiceInterface::class, SlaAttainmentService::class);
		$context->registerServiceAlias(SlaEngineServiceInterface::class, SlaEngineService::class);
		$context->registerServiceAlias(SmsAdapterInterface::class, SmsAdapter::class);
		$context->registerServiceAlias(SnapshotGenerationServiceInterface::class, SnapshotGenerationService::class);
		$context->registerServiceAlias(SocialAccountServiceInterface::class, SocialAccountService::class);
		$context->registerServiceAlias(SocialPostServiceInterface::class, SocialPostService::class);
		$context->registerServiceAlias(SubscriptionServiceInterface::class, SubscriptionService::class);
		$context->registerServiceAlias(SurveyDispatchServiceInterface::class, SurveyDispatchService::class);
		$context->registerServiceAlias(TicketServiceInterface::class, TicketService::class);
		$context->registerServiceAlias(TierServiceInterface::class, TierService::class);
		$context->registerServiceAlias(TimeBillingHandoffServiceInterface::class, TimeBillingHandoffService::class);
		$context->registerServiceAlias(TrackingLinkServiceInterface::class, TrackingLinkService::class);
		$context->registerServiceAlias(WalkInQueueServiceInterface::class, WalkInQueueService::class);
		$context->registerServiceAlias(WebhookProcessorServiceInterface::class, WebhookProcessorService::class);
		$context->registerServiceAlias(WhatsAppAdapterInterface::class, WhatsAppAdapter::class);
		$context->registerServiceAlias(WorklistServiceInterface::class, WorklistService::class);
		$context->registerServiceAlias(XWikiServiceInterface::class, XWikiService::class);
		$context->registerServiceAlias(ZgwApiClientInterface::class, ZgwApiClient::class);
	}//end register()
}//end class
