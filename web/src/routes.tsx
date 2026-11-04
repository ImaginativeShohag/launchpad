import { lazy } from 'react'
import { createBrowserRouter } from 'react-router'
import { AppShell, AuthShell } from '@/components/layout/AppShell'
import { RequireRole } from '@/components/layout/RequireRole'
import { RouteError } from '@/components/layout/RouteError'

const SignInPage = lazy(() => import('@/pages/auth/SignInPage'))
const SignUpPage = lazy(() => import('@/pages/auth/SignUpPage'))
const VerifyEmailPage = lazy(() => import('@/pages/auth/VerifyEmailPage'))
const ForgotPasswordPage = lazy(() => import('@/pages/auth/ForgotPasswordPage'))
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'))
const ConfirmEmailChangePage = lazy(() => import('@/pages/auth/ConfirmEmailChangePage'))
const CancelEmailChangePage = lazy(() => import('@/pages/auth/CancelEmailChangePage'))

const SettingsPage = lazy(() => import('@/pages/account/SettingsPage'))
const NotificationsPage = lazy(() => import('@/pages/account/NotificationsPage'))
const UnsubscribePage = lazy(() => import('@/pages/account/UnsubscribePage'))

const HomePage = lazy(() => import('@/pages/discover/HomePage'))
const DayPage = lazy(() => import('@/pages/discover/DayPage'))
const LeaderboardPage = lazy(() => import('@/pages/discover/LeaderboardPage'))
const TopicsPage = lazy(() => import('@/pages/discover/TopicsPage'))
const TopicPage = lazy(() => import('@/pages/discover/TopicPage'))
const SearchPage = lazy(() => import('@/pages/discover/SearchPage'))
const FeedPage = lazy(() => import('@/pages/discover/FeedPage'))
const NotFoundPage = lazy(() => import('@/pages/discover/NotFoundPage'))

const ProductPage = lazy(() => import('@/pages/product/ProductPage'))
const ProfilePage = lazy(() => import('@/pages/product/ProfilePage'))

const SubmitPage = lazy(() => import('@/pages/founder/SubmitPage'))
const MyProductsPage = lazy(() => import('@/pages/founder/MyProductsPage'))
const DashboardPage = lazy(() => import('@/pages/founder/DashboardPage'))

const StaffLayout = lazy(() => import('@/pages/staff/StaffLayout'))
const ReviewQueuePage = lazy(() => import('@/pages/staff/ReviewQueuePage'))
const ReviewDetailPage = lazy(() => import('@/pages/staff/ReviewDetailPage'))
const ReportsPage = lazy(() => import('@/pages/staff/ReportsPage'))
const ReportDetailPage = lazy(() => import('@/pages/staff/ReportDetailPage'))
const RiskPage = lazy(() => import('@/pages/staff/RiskPage'))
const MemberRiskPage = lazy(() => import('@/pages/staff/MemberRiskPage'))
const IntegrityPage = lazy(() => import('@/pages/staff/IntegrityPage'))
const MembersPage = lazy(() => import('@/pages/staff/MembersPage'))
const StaffProductsPage = lazy(() => import('@/pages/staff/StaffProductsPage'))
const AuditLogPage = lazy(() => import('@/pages/staff/AuditLogPage'))

const members = ['member', 'reviewer', 'admin'] as const
const staff = ['reviewer', 'admin'] as const

export const router = createBrowserRouter([
  {
    element: <AuthShell />,
    errorElement: <RouteError />,
    children: [
      { path: '/signin', element: <SignInPage /> },
      { path: '/signup', element: <SignUpPage /> },
      { path: '/verify-email', element: <VerifyEmailPage /> },
      { path: '/forgot-password', element: <ForgotPasswordPage /> },
      { path: '/reset-password', element: <ResetPasswordPage /> },
      { path: '/confirm-email-change', element: <ConfirmEmailChangePage /> },
      { path: '/cancel-email-change', element: <CancelEmailChangePage /> },
      { path: '/unsubscribe', element: <UnsubscribePage /> },
    ],
  },
  {
    element: <AppShell />,
    errorElement: <RouteError />,
    children: [
      { path: '/', element: <HomePage /> },
      { path: '/day/:date', element: <DayPage /> },
      { path: '/leaderboard', element: <LeaderboardPage /> },
      { path: '/topics', element: <TopicsPage /> },
      { path: '/topics/:slug', element: <TopicPage /> },
      { path: '/search', element: <SearchPage /> },
      { path: '/feed', element: <RequireRole roles={[...members]} verified><FeedPage /></RequireRole> },
      { path: '/p/:slug', element: <ProductPage /> },
      { path: '/u/:handle', element: <ProfilePage /> },
      { path: '/settings', element: <RequireRole roles={[...members]}><SettingsPage /></RequireRole> },
      { path: '/notifications', element: <RequireRole roles={[...members]}><NotificationsPage /></RequireRole> },
      // One route for both: the wizard's first save moves /submit to /submit/:id and the page must not remount.
      { path: '/submit/:id?', element: <RequireRole roles={[...members]} verified><SubmitPage /></RequireRole> },
      { path: '/my/products', element: <RequireRole roles={[...members]} verified><MyProductsPage /></RequireRole> },
      { path: '/my/products/:id/dashboard', element: <RequireRole roles={[...members]} verified><DashboardPage /></RequireRole> },
      {
        path: '/staff',
        element: <RequireRole roles={[...staff]} verified><StaffLayout /></RequireRole>,
        children: [
          { index: true, element: <ReviewQueuePage /> },
          { path: 'review/:id', element: <ReviewDetailPage /> },
          { path: 'reports', element: <ReportsPage /> },
          { path: 'reports/:type/:id', element: <ReportDetailPage /> },
          { path: 'products', element: <StaffProductsPage /> },
          { path: 'products/:id/integrity', element: <IntegrityPage /> },
          { path: 'risk', element: <RiskPage /> },
          { path: 'risk/members/:id', element: <MemberRiskPage /> },
          // Admin only: the API refuses anyone else (403) as well.
          { path: 'members', element: <RequireRole roles={['admin']}><MembersPage /></RequireRole> },
          { path: 'audit', element: <RequireRole roles={['admin']}><AuditLogPage /></RequireRole> },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
