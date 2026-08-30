import DealsOverviewWidget from './views/widgets/DealsOverviewWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_deals_overview_widget', DealsOverviewWidget)
