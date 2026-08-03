import RecentActivitiesWidget from './views/widgets/RecentActivitiesWidget.vue'
import { registerDashboardWidget } from './mountDashboardWidget.js'

registerDashboardWidget('pipelinq_recent_activities_widget', RecentActivitiesWidget)
