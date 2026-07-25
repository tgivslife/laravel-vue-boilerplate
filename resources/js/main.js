import { createApp } from 'vue'
import App from './App.vue'
import { registerPlugins } from '@core/utils/plugins'

import '@css/main.css'

const app = createApp(App)

registerPlugins(app)

app.mount('#app')

