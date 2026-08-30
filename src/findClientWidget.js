import FindClientWidget from './views/widgets/FindClientWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_find_client_widget', FindClientWidget)
