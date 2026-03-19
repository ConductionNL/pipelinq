import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import RecentActivitiesWidget from './views/widgets/RecentActivitiesWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_recent_activities_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(RecentActivitiesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
