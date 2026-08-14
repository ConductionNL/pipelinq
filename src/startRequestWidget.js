import StartRequestWidget from './views/widgets/StartRequestWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_start_request_widget', StartRequestWidget)
