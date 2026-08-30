import CreateLeadWidget from './views/widgets/CreateLeadWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_create_lead_widget', CreateLeadWidget)
