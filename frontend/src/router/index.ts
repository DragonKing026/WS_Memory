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
        // These two come BEFORE the document route: the slug parameter is greedy, so
        // `s/wiedza/umowy/edit` would otherwise be read as a document called
        // "umowy/edit". The suffix is matched explicitly for the same reason.
        path: 's/:space/:slug(.*)/edytuj',
        name: 'document-edit',
        component: () => import('@/pages/DocumentEditPage.vue'),
        meta: { title: 'Edycja dokumentu' },
      },
      {
        path: 's/:space/:slug(.*)/historia',
        name: 'history',
        component: () => import('@/pages/DocumentHistoryPage.vue'),
        meta: { title: 'Historia dokumentu' },
      },
      {
        // A slug may contain slashes (`umowy/najem`), so the parameter is greedy.
        // Declared after the space route, or `s/wiedza` itself would match here.
        path: 's/:space/:slug(.*)',
        name: 'document',
        component: () => import('@/pages/DocumentPage.vue'),
        meta: { title: 'Dokument' },
      },
      {
        path: 'memory',
        name: 'memory',
        component: () => import('@/pages/MemoryPage.vue'),
        meta: { title: 'Surowa pamięć' },
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
    // Administration sits under its own layout, not under the wiki one, and the split
    // is on purpose. The two answer different questions — „where is the knowledge"
    // and „is this installation healthy" — and hanging the second under the space list
    // put the machinery of the installation in front of everyone reading a document.
    //
    // Deliberately **not** guarded here, although it is for global administrators
    // only: a guard can only send somebody away, and being bounced to the home page is
    // how a person concludes they mistyped the address. The layout refuses in words,
    // and the API refuses with 403 — which is where the boundary actually is.
    path: '/admin',
    component: () => import('@/layouts/AdminLayout.vue'),
    children: [
      {
        // Accounts rather than dependencies: administration is opened about a person far
        // more often than about an image version.
        path: '',
        name: 'admin',
        redirect: { name: 'admin-users' },
      },
      {
        path: 'uzytkownicy',
        name: 'admin-users',
        component: () => import('@/pages/AdminUsersPage.vue'),
        meta: { title: 'Konta' },
      },
      {
        path: 'zaproszenia',
        name: 'admin-invitations',
        component: () => import('@/pages/AdminInvitationsPage.vue'),
        meta: { title: 'Zaproszenia' },
      },
      {
        path: 'przestrzenie',
        name: 'admin-spaces',
        component: () => import('@/pages/AdminSpacesPage.vue'),
        meta: { title: 'Przestrzenie' },
      },
      {
        path: 'maile',
        name: 'admin-mail-templates',
        component: () => import('@/pages/AdminMailTemplatesPage.vue'),
        meta: { title: 'Szablony maili' },
      },
      {
        path: 'maile/dziennik',
        name: 'admin-mail-log',
        component: () => import('@/pages/AdminMailLogPage.vue'),
        meta: { title: 'Dziennik maili' },
      },
      {
        path: 'audyt',
        name: 'admin-audit',
        component: () => import('@/pages/AdminAuditPage.vue'),
        meta: { title: 'Dziennik audytu' },
      },
      {
        path: 'zaleznosci',
        name: 'admin-dependencies',
        component: () => import('@/pages/AdminDependenciesPage.vue'),
        meta: { title: 'Zależności' },
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

/**
 * Recognises the one failure mode that leaves the application showing nothing:
 * the browser could not fetch a lazily loaded page.
 *
 * Matched on the message because there is no error type to match on — the browser
 * throws a plain `TypeError`, and the wording differs between engines.
 */
export function isModuleLoadFailure(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false
  }

  return /dynamically imported module|Importing a module script failed|error loading dynamically imported module/i.test(
    error.message,
  )
}

/**
 * A page whose code will not load is recovered by loading the page again, once.
 *
 * Pages are lazy, so navigating to one fetches a module. When that fetch fails the
 * navigation is abandoned mid-flight and the person is left looking at a blank
 * screen — no error, no layout, nothing to click.
 *
 * There are two ways to get here, and both are ordinary rather than exotic:
 *
 * - in development, Vite finds a dependency it had not pre-bundled, re-optimises, and
 *   answers the in-flight request with `504 Outdated Optimize Dep`;
 * - in production, a deployment replaces the built files while somebody has the old
 *   page open, and the chunk their copy asks for is no longer there.
 *
 * In both cases a fresh load of the same address fixes it, because the newly served
 * `index.html` points at files that exist. Hence a reload rather than an error screen.
 *
 * It happens **once** per address. If the second attempt fails too, the cause is not
 * a stale file and reloading again would only spin — so the error is left to the
 * console, where it can be diagnosed.
 */
const RELOAD_MARKER = 'ws:przeladowanie-po-bledzie-modulu'

function alreadyRetried(target: string): boolean {
  try {
    return window.sessionStorage.getItem(RELOAD_MARKER) === target
  } catch {
    // Storage can be unavailable (private mode, blocked cookies). Without it we
    // cannot remember the attempt, so we do not make one: a reload we cannot count
    // is a reload that could repeat forever.
    return true
  }
}

function rememberRetry(target: string): void {
  try {
    window.sessionStorage.setItem(RELOAD_MARKER, target)
  } catch {
    // Handled by `alreadyRetried` returning true when storage does not work.
  }
}

router.onError((error, to) => {
  if (!isModuleLoadFailure(error) || alreadyRetried(to.fullPath)) {
    return
  }

  rememberRetry(to.fullPath)
  window.location.assign(to.fullPath)
})

router.afterEach(() => {
  // A navigation finished, so whatever went wrong is over. Forgetting the attempt
  // means somebody who meets the problem again later — after the next deployment,
  // say — gets the same single automatic recovery, instead of being told they have
  // already had their turn.
  try {
    window.sessionStorage.removeItem(RELOAD_MARKER)
  } catch {
    // Nothing to forget if storage does not work.
  }
})
