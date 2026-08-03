import MyLeadsWidget from './views/widgets/MyLeadsWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_my_leads_widget', MyLeadsWidget)
