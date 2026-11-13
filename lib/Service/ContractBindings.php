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
use OCA\Pipelinq\Kernel\Contracts\BrpCacheServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BsnAuditServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BudgetServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CallbackServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CallTranscriptionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CampaignReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CampaignServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CashShiftServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ClientBusinessReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\CompetitorWatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ConnectionAuditServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ConnectionReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ConnectorEgressInterface;
use OCA\Pipelinq\Kernel\Contracts\ConsentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactCaptureServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactMomentFilingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactmomentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactSyncServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContractServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CorrespondenceLanguageServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\CronExpressionHelperInterface;
use OCA\Pipelinq\Kernel\Contracts\CtiContactMatcherInterface;
use OCA\Pipelinq\Kernel\Contracts\CtiServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\Customer360SummaryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\DeliverabilityCheckServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\DemoSeedServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\DossierQuestionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EligibilityServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EmailMatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EntityActivityServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExchangeRateServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportDestinationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportExecutionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportRunServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastDealServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastExportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\FormsQuestionReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\FullExportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\GiftCardServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\HaalCentraalClientInterface;
use OCA\Pipelinq\Kernel\Contracts\HeldObjectDrainInterface;
use OCA\Pipelinq\Kernel\Contracts\JourneyServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\JourneyStepRunnerInterface;
use OCA\Pipelinq\Kernel\Contracts\KassakoppelingAuditServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\KccWerkplekServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LabelRenameServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LandingPageProvisioningServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LeadRotationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ListObjectStoreInterface;
use OCA\Pipelinq\Kernel\Contracts\LogiusConnectorInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyAccountServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyProgrammeServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyReportingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MailingListServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MainRegisterReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\MatomoReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MdmObjectRepositoryInterface;
use OCA\Pipelinq\Kernel\Contracts\MessagingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MockPipelineCleanupServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NaviServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\OpenMailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\OptOutServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PartyIndicatorServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PartyKindRegistryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PartyOrganisationTreeServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PointsLedgerServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalAuthServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalDelegationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalRequestServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalSessionManagerInterface;
use OCA\Pipelinq\Kernel\Contracts\PortalTenantServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosBookkeepingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosCustomerLinkServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosPaymentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosRefundServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosRoleServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosStaffReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosStaffServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosTenderServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\PosTransactionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProgrammeEstimationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProgrammePortfolioServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\QuotaServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RapportageServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReceiptDeliveryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RecurringRevenueServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RedemptionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RegisterAccessSyncInterface;
use OCA\Pipelinq\Kernel\Contracts\RenewalEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ReportingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RetentionMetadataBackfillServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\RoutingServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SatisfactionAggregationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ScheduledTaskServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SearchConsoleImportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SearchQueryReportServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SemanticHandoffServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ShillinqStockReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaAttainmentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SlaEntitySweeperInterface;
use OCA\Pipelinq\Kernel\Contracts\SmsAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\SnapshotGenerationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SocialAccountServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SocialMetricsServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SocialPostServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionQueryServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SubscriptionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SurveyDispatchServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SurveyResponseServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TaskNameBackfillServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TemplateApprovalSyncServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TierServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TimeBillingHandoffServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TrackingLinkServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WalkInQueueServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WebFormBindingCheckInterface;
use OCA\Pipelinq\Kernel\Contracts\WebFormFinderInterface;
use OCA\Pipelinq\Kernel\Contracts\WebFormIntakeServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WebhookProcessorServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WeeklyReviewServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WhatsAppAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\WooRequestConversionServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\XWikiServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ZgwApiClientInterface;
use OCA\Pipelinq\Kernel\Contracts\ZgwRegisterAccessInterface;
use OCA\Pipelinq\Kernel\Contracts\ZrcClientInterface;
use OCA\Pipelinq\Kernel\Contracts\ZtcClientInterface;
use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCA\Pipelinq\Service\CallReview\CallTranscriptionService;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Service\Export\ExportExecutionService;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCA\Pipelinq\Service\Export\FullExportService;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCA\Pipelinq\Service\MailAddIn\OpenMailService;
use OCA\Pipelinq\Service\Marketing\DeliverabilityCheckService;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\JourneyStepRunner;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\SubscriptionQueryService;
use OCA\Pipelinq\Service\Marketing\WeeklyReviewService;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService;
use OCA\Pipelinq\Service\Sla\SlaEntitySweeper;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCA\Pipelinq\Service\WebForm\FormsQuestionReader;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCA\Pipelinq\Service\WebForm\WebFormBindingCheck;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
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
		$context->registerServiceAlias(BrpCacheServiceInterface::class, BrpCacheService::class);
		$context->registerServiceAlias(BsnAuditServiceInterface::class, BsnAuditService::class);
		$context->registerServiceAlias(BudgetServiceInterface::class, BudgetService::class);
		$context->registerServiceAlias(CallTranscriptionServiceInterface::class, CallTranscriptionService::class);
		$context->registerServiceAlias(CallbackServiceInterface::class, CallbackService::class);
		$context->registerServiceAlias(CampaignReportServiceInterface::class, CampaignReportService::class);
		$context->registerServiceAlias(CampaignServiceInterface::class, CampaignService::class);
		$context->registerServiceAlias(CashShiftServiceInterface::class, CashShiftService::class);
		$context->registerServiceAlias(ClientBusinessReaderInterface::class, ClientBusinessReader::class);
		$context->registerServiceAlias(CompetitorWatchServiceInterface::class, CompetitorWatchService::class);
		$context->registerServiceAlias(ComplianceServiceInterface::class, ComplianceService::class);
		$context->registerServiceAlias(ConnectionAuditServiceInterface::class, ConnectionAuditService::class);
		$context->registerServiceAlias(ConnectionReportServiceInterface::class, ConnectionReportService::class);
		$context->registerServiceAlias(ConnectorEgressInterface::class, ConnectorEgress::class);
		$context->registerServiceAlias(ConsentServiceInterface::class, ConsentService::class);
		$context->registerServiceAlias(ContactCaptureServiceInterface::class, ContactCaptureService::class);
		$context->registerServiceAlias(ContactMomentFilingServiceInterface::class, ContactMomentFilingService::class);
		$context->registerServiceAlias(ContactSyncServiceInterface::class, ContactSyncService::class);
		$context->registerServiceAlias(ContactmomentServiceInterface::class, ContactmomentService::class);
		$context->registerServiceAlias(ContractServiceInterface::class, ContractService::class);
		$context->registerServiceAlias(CorrespondenceLanguageServiceInterface::class, CorrespondenceLanguageService::class);
		$context->registerServiceAlias(CronExpressionHelperInterface::class, CronExpressionHelper::class);
		$context->registerServiceAlias(CtiContactMatcherInterface::class, CtiContactMatcher::class);
		$context->registerServiceAlias(CtiServiceInterface::class, CtiService::class);
		$context->registerServiceAlias(Customer360SummaryServiceInterface::class, Customer360SummaryService::class);
		$context->registerServiceAlias(DeliverabilityCheckServiceInterface::class, DeliverabilityCheckService::class);
		$context->registerServiceAlias(DemoSeedServiceInterface::class, DemoSeedService::class);
		$context->registerServiceAlias(DossierQuestionServiceInterface::class, DossierQuestionService::class);
		$context->registerServiceAlias(EligibilityServiceInterface::class, EligibilityService::class);
		$context->registerServiceAlias(EmailMatchServiceInterface::class, EmailMatchService::class);
		$context->registerServiceAlias(EntityActivityServiceInterface::class, EntityActivityService::class);
		$context->registerServiceAlias(EventEmailServiceInterface::class, EventEmailService::class);
		$context->registerServiceAlias(EventRegistrationServiceInterface::class, EventRegistrationService::class);
		$context->registerServiceAlias(ExchangeRateServiceInterface::class, ExchangeRateService::class);
		$context->registerServiceAlias(ExportDestinationServiceInterface::class, ExportDestinationService::class);
		$context->registerServiceAlias(ExportExecutionServiceInterface::class, ExportExecutionService::class);
		$context->registerServiceAlias(ExportJobServiceInterface::class, ExportJobService::class);
		$context->registerServiceAlias(ExportRunServiceInterface::class, ExportRunService::class);
		$context->registerServiceAlias(ForecastDealServiceInterface::class, ForecastDealService::class);
		$context->registerServiceAlias(ForecastExportServiceInterface::class, ForecastExportService::class);
		$context->registerServiceAlias(ForecastServiceInterface::class, ForecastService::class);
		$context->registerServiceAlias(FormsQuestionReaderInterface::class, FormsQuestionReader::class);
		$context->registerServiceAlias(FullExportServiceInterface::class, FullExportService::class);
		$context->registerServiceAlias(GiftCardServiceInterface::class, GiftCardService::class);
		$context->registerServiceAlias(HaalCentraalClientInterface::class, HaalCentraalClient::class);
		$context->registerServiceAlias(HeldObjectDrainInterface::class, HeldObjectDrain::class);
		$context->registerServiceAlias(JourneyServiceInterface::class, JourneyService::class);
		$context->registerServiceAlias(JourneyStepRunnerInterface::class, JourneyStepRunner::class);
		$context->registerServiceAlias(KassakoppelingAuditServiceInterface::class, KassakoppelingAuditService::class);
		$context->registerServiceAlias(KccWerkplekServiceInterface::class, KccWerkplekService::class);
		$context->registerServiceAlias(LabelRenameServiceInterface::class, LabelRenameService::class);
		$context->registerServiceAlias(LandingPageProvisioningServiceInterface::class, LandingPageProvisioningService::class);
		$context->registerServiceAlias(LeadRotationServiceInterface::class, LeadRotationService::class);
		$context->registerServiceAlias(ListObjectStoreInterface::class, ListObjectStore::class);
		$context->registerServiceAlias(LogiusConnectorInterface::class, LogiusConnector::class);
		$context->registerServiceAlias(LoyaltyAccountServiceInterface::class, LoyaltyAccountService::class);
		$context->registerServiceAlias(LoyaltyEngineServiceInterface::class, LoyaltyEngineService::class);
		$context->registerServiceAlias(LoyaltyProgrammeServiceInterface::class, LoyaltyProgrammeService::class);
		$context->registerServiceAlias(LoyaltyReportingServiceInterface::class, LoyaltyReportingService::class);
		$context->registerServiceAlias(MailingListServiceInterface::class, MailingListService::class);
		$context->registerServiceAlias(MainRegisterReaderInterface::class, MainRegisterReader::class);
		$context->registerServiceAlias(MatomoReportServiceInterface::class, MatomoReportService::class);
		$context->registerServiceAlias(MdmObjectRepositoryInterface::class, MdmObjectRepository::class);
		$context->registerServiceAlias(MessagingServiceInterface::class, MessagingService::class);
		$context->registerServiceAlias(MockPipelineCleanupServiceInterface::class, MockPipelineCleanupService::class);
		$context->registerServiceAlias(NaviServiceInterface::class, NaviService::class);
		$context->registerServiceAlias(NotificationServiceInterface::class, NotificationService::class);
		$context->registerServiceAlias(OpenMailServiceInterface::class, OpenMailService::class);
		$context->registerServiceAlias(OptOutServiceInterface::class, OptOutService::class);
		$context->registerServiceAlias(PartyIndicatorServiceInterface::class, PartyIndicatorService::class);
		$context->registerServiceAlias(PartyKindRegistryServiceInterface::class, PartyKindRegistryService::class);
		$context->registerServiceAlias(PartyOrganisationTreeServiceInterface::class, PartyOrganisationTreeService::class);
		$context->registerServiceAlias(PointsLedgerServiceInterface::class, PointsLedgerService::class);
		$context->registerServiceAlias(PortalAuthServiceInterface::class, PortalAuthService::class);
		$context->registerServiceAlias(PortalDelegationServiceInterface::class, PortalDelegationService::class);
		$context->registerServiceAlias(PortalRequestServiceInterface::class, PortalRequestService::class);
		$context->registerServiceAlias(PortalSessionManagerInterface::class, PortalSessionManager::class);
		$context->registerServiceAlias(PortalTenantServiceInterface::class, PortalTenantService::class);
		$context->registerServiceAlias(PosBookkeepingServiceInterface::class, PosBookkeepingService::class);
		$context->registerServiceAlias(PosCustomerLinkServiceInterface::class, PosCustomerLinkService::class);
		$context->registerServiceAlias(PosPaymentServiceInterface::class, PosPaymentService::class);
		$context->registerServiceAlias(PosRefundServiceInterface::class, PosRefundService::class);
		$context->registerServiceAlias(PosRoleServiceInterface::class, PosRoleService::class);
		$context->registerServiceAlias(PosStaffReportServiceInterface::class, PosStaffReportService::class);
		$context->registerServiceAlias(PosStaffServiceInterface::class, PosStaffService::class);
		$context->registerServiceAlias(PosTenderServiceInterface::class, PosTenderService::class);
		$context->registerServiceAlias(PosTransactionServiceInterface::class, PosTransactionService::class);
		$context->registerServiceAlias(ProductCatalogServiceInterface::class, ProductCatalogService::class);
		$context->registerServiceAlias(ProgrammeEstimationServiceInterface::class, ProgrammeEstimationService::class);
		$context->registerServiceAlias(ProgrammePortfolioServiceInterface::class, ProgrammePortfolioService::class);
		$context->registerServiceAlias(QuotaServiceInterface::class, QuotaService::class);
		$context->registerServiceAlias(RapportageServiceInterface::class, RapportageService::class);
		$context->registerServiceAlias(ReceiptDeliveryServiceInterface::class, ReceiptDeliveryService::class);
		$context->registerServiceAlias(RecurringRevenueServiceInterface::class, RecurringRevenueService::class);
		$context->registerServiceAlias(RedemptionServiceInterface::class, RedemptionService::class);
		$context->registerServiceAlias(RegisterAccessSyncInterface::class, RegisterAccessSync::class);
		$context->registerServiceAlias(RenewalEngineServiceInterface::class, RenewalEngineService::class);
		$context->registerServiceAlias(ReportingServiceInterface::class, ReportingService::class);
		$context->registerServiceAlias(RetentionMetadataBackfillServiceInterface::class, RetentionMetadataBackfillService::class);
		$context->registerServiceAlias(RoutingServiceInterface::class, RoutingService::class);
		$context->registerServiceAlias(SatisfactionAggregationServiceInterface::class, SatisfactionAggregationService::class);
		$context->registerServiceAlias(ScheduledTaskServiceInterface::class, ScheduledTaskService::class);
		$context->registerServiceAlias(SearchConsoleImportServiceInterface::class, SearchConsoleImportService::class);
		$context->registerServiceAlias(SearchQueryReportServiceInterface::class, SearchQueryReportService::class);
		$context->registerServiceAlias(SegmentServiceInterface::class, SegmentService::class);
		$context->registerServiceAlias(SemanticHandoffServiceInterface::class, SemanticHandoffService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(ShillinqStockReaderInterface::class, ShillinqStockReader::class);
		$context->registerServiceAlias(SlaAttainmentServiceInterface::class, SlaAttainmentService::class);
		$context->registerServiceAlias(SlaEngineServiceInterface::class, SlaEngineService::class);
		$context->registerServiceAlias(SlaEntitySweeperInterface::class, SlaEntitySweeper::class);
		$context->registerServiceAlias(SmsAdapterInterface::class, SmsAdapter::class);
		$context->registerServiceAlias(SnapshotGenerationServiceInterface::class, SnapshotGenerationService::class);
		$context->registerServiceAlias(SocialAccountServiceInterface::class, SocialAccountService::class);
		$context->registerServiceAlias(SocialMetricsServiceInterface::class, SocialMetricsService::class);
		$context->registerServiceAlias(SocialPostServiceInterface::class, SocialPostService::class);
		$context->registerServiceAlias(SubscriptionQueryServiceInterface::class, SubscriptionQueryService::class);
		$context->registerServiceAlias(SubscriptionServiceInterface::class, SubscriptionService::class);
		$context->registerServiceAlias(SurveyDispatchServiceInterface::class, SurveyDispatchService::class);
		$context->registerServiceAlias(SurveyResponseServiceInterface::class, SurveyResponseService::class);
		$context->registerServiceAlias(TaskNameBackfillServiceInterface::class, TaskNameBackfillService::class);
		$context->registerServiceAlias(TemplateApprovalSyncServiceInterface::class, TemplateApprovalSyncService::class);
		$context->registerServiceAlias(TicketServiceInterface::class, TicketService::class);
		$context->registerServiceAlias(TierServiceInterface::class, TierService::class);
		$context->registerServiceAlias(TimeBillingHandoffServiceInterface::class, TimeBillingHandoffService::class);
		$context->registerServiceAlias(TrackingLinkServiceInterface::class, TrackingLinkService::class);
		$context->registerServiceAlias(WalkInQueueServiceInterface::class, WalkInQueueService::class);
		$context->registerServiceAlias(WebFormBindingCheckInterface::class, WebFormBindingCheck::class);
		$context->registerServiceAlias(WebFormFinderInterface::class, WebFormFinder::class);
		$context->registerServiceAlias(WebFormIntakeServiceInterface::class, WebFormIntakeService::class);
		$context->registerServiceAlias(WebhookProcessorServiceInterface::class, WebhookProcessorService::class);
		$context->registerServiceAlias(WeeklyReviewServiceInterface::class, WeeklyReviewService::class);
		$context->registerServiceAlias(WhatsAppAdapterInterface::class, WhatsAppAdapter::class);
		$context->registerServiceAlias(WooRequestConversionServiceInterface::class, WooRequestConversionService::class);
		$context->registerServiceAlias(WorklistServiceInterface::class, WorklistService::class);
		$context->registerServiceAlias(XWikiServiceInterface::class, XWikiService::class);
		$context->registerServiceAlias(ZgwApiClientInterface::class, ZgwApiClient::class);
		$context->registerServiceAlias(ZgwRegisterAccessInterface::class, ZgwRegisterAccess::class);
		$context->registerServiceAlias(ZrcClientInterface::class, ZrcClient::class);
		$context->registerServiceAlias(ZtcClientInterface::class, ZtcClient::class);
	}//end register()
}//end class
