import { createPinia } from 'pinia'
import ui from '@nuxt/ui/vue-plugin'
import { createApp } from 'vue'

import App from './App.vue'
import { useApi } from './api/client'
import { router } from './router'
import { useAuthStore } from './stores/auth'

import './assets/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(ui)

/**
 * Wires the HTTP client to the session, once, here.
 *
 * The client must not import the router or the store — it would then be untestable
 * without both. Instead this is the one place that knows about all three, and it is
 * three lines long.
 */
const auth = useAuthStore()

useApi().onSessionLost((problem) => {
  auth.sessionLost(router.currentRoute.value.fullPath, problem.message)

  if (router.currentRoute.value.meta.public !== true) {
    void router.replace({
      name: 'login',
      query: { powrot: router.currentRoute.value.fullPath },
    })
  }
})

app.mount('#app')
