import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    children: [
      {
        path: '',
        component: () => import('@/pages/IndexPage.vue'),
        meta: { title: 'Overview', requiresSession: true },
      },
      {
        path: 'calendar',
        component: () => import('@/pages/CalendarPage.vue'),
        meta: { title: 'Calendar preview', requiresSession: true },
      },
      {
        path: 'account',
        component: () => import('@/pages/AccountPage.vue'),
        meta: { title: 'Your account', requiresSession: true },
      },
      {
        path: 'sign-in',
        component: () => import('@/pages/SignInPage.vue'),
        meta: { title: 'Sign in' },
      },
    ],
  },
  {
    path: '/:catchAll(.*)*',
    component: () => import('@/layouts/MainLayout.vue'),
    children: [
      {
        path: '',
        component: () => import('@/pages/ErrorNotFound.vue'),
        meta: { title: 'Page not found' },
      },
    ],
  },
];
export default routes;
