import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Every address in the application, in one readable list.
 *
 * **Not file-based routing**, although `docs/07-frontend.md` called for it: the plugin
 * that provides it (`unplugin-vue-router`) requires `vue-router ^4.6`, and the stack
 * specifies vue-router 5. Pinning the router a major version back to keep a
 * build-time convention would be a migration debt taken on day one, so the routes are
 * written out instead (D-027). For an application of a dozen screens, one explicit
 * table is arguably easier to read than a directory tree anyway.
 *
 * Pages are lazy — a person who only ever signs in should not download the editor.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/LoginPage.vue'),
    meta: { public: true, title: 'Logowanie' },
  },
  {
    // The link from an invitation. Public of necessity: whoever opens it has no
    // account yet, which is the whole point.
    path: '/zaproszenie/:token',
    name: 'invitation',
    component: () => import('@/pages/InvitationPage.vue'),
    meta: { public: true, title: 'Zaproszenie' },
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    children: [
      {
        path: '',
        name: 'home',
        component: () => import('@/pages/HomePage.vue'),
        meta: { title: 'Baza wiedzy' },
      },
      {
        path: 's/:space',
        name: 'space',
        component: () => import('@/pages/SpacePage.vue'),
        meta: { title: 'Przestrzeń' },
      },
      {
        path: 'settings/tokens',
        name: 'tokens',
        component: () => import('@/pages/TokensPage.vue'),
        meta: { title: 'Tokeny agentów' },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFoundPage.vue'),
    meta: { public: true, title: 'Nie ma takiej strony' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

/**
 * One guard, and it is the only place that decides whether a page may be seen.
 *
 * It restores the session at most once per page load: without that, opening the
 * application on a deep link would either bounce to sign-in despite a valid token, or
 * ask `/api/me` again on every navigation.
 */
let restored = false

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!restored) {
    restored = true
    await auth.restore()
  }

  if (to.meta.public === true) {
    // Somebody already signed in has no business on the sign-in page.
    return to.name === 'login' && auth.signedIn ? { name: 'home' } : true
  }

  if (!auth.signedIn) {
    // `fullPath`, not `path`: a query or a hash is part of where the person was
    // trying to get to, and dropping it sends them somewhere almost right.
    return { name: 'login', query: { powrot: to.fullPath } }
  }

  return true
})

router.afterEach((to) => {
  const title = typeof to.meta.title === 'string' ? to.meta.title : null
  document.title = title === null ? 'WS_Memory' : `${title} · WS_Memory`
})
