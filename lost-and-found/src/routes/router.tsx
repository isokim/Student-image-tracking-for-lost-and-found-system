import { createBrowserRouter } from "react-router-dom";
import { ProtectedRoute } from "./ProtectedRoute";
import { useAuth } from "@/features/auth/AuthContext";
import { StudentLayout } from "@/layouts/StudentLayout";
import { StaffLayout } from "@/layouts/StaffLayout";
import { AdminLayout } from "@/layouts/AdminLayout";

import { LandingPage } from "@/pages/public/LandingPage";
import { LoginPage } from "@/pages/public/LoginPage";
import { RegisterPage } from "@/pages/public/RegisterPage";
import { ForgotPasswordPage } from "@/pages/public/ForgotPasswordPage";
import { ResetPasswordPage } from "@/pages/public/ResetPasswordPage";

import { StudentDashboardPage } from "@/pages/student/StudentDashboardPage";
import { LostItemsPage } from "@/pages/student/LostItemsPage";
import { ReportLostItemPage } from "@/pages/student/ReportLostItemPage";
import { LostItemDetailPage } from "@/pages/student/LostItemDetailPage";
import { FoundItemsPage } from "@/pages/student/FoundItemsPage";
import { ReportFoundItemPage } from "@/pages/student/ReportFoundItemPage";
import { FoundItemDetailPage } from "@/pages/student/FoundItemDetailPage";
import { SearchPage } from "@/pages/student/SearchPage";
import { AdvancedSearchPage } from "@/pages/student/AdvancedSearchPage";
import { MatchesPage } from "@/pages/student/MatchesPage";
import { MatchDetailPage } from "@/pages/student/MatchDetailPage";
import { ClaimsPage } from "@/pages/student/ClaimsPage";
import { NewClaimPage } from "@/pages/student/NewClaimPage";
import { ClaimDetailPage } from "@/pages/student/ClaimDetailPage";
import { NotificationsPage } from "@/pages/student/NotificationsPage";
import { ProfilePage } from "@/pages/student/ProfilePage";
import { SettingsPage } from "@/pages/student/SettingsPage";

import { StaffDashboardPage } from "@/pages/staff/StaffDashboardPage";
import { StaffFoundItemsPage } from "@/pages/staff/StaffFoundItemsPage";
import { StaffClaimsPage } from "@/pages/staff/StaffClaimsPage";
import { StaffItemDetailPage } from "@/pages/staff/StaffItemDetailPage";
import { StaffClaimDetailPage } from "@/pages/staff/StaffClaimDetailPage";

import { AdminDashboardPage } from "@/pages/admin/AdminDashboardPage";
import { AdminUsersPage } from "@/pages/admin/AdminUsersPage";
import { AdminStudentsPage } from "@/pages/admin/AdminStudentsPage";
import { AdminStaffPage } from "@/pages/admin/AdminStaffPage";
import { AdminLostItemsPage } from "@/pages/admin/AdminLostItemsPage";
import { AdminFoundItemsPage } from "@/pages/admin/AdminFoundItemsPage";
import { AdminClaimsPage } from "@/pages/admin/AdminClaimsPage";
import { AdminMatchesPage } from "@/pages/admin/AdminMatchesPage";
import { AdminCategoriesPage } from "@/pages/admin/AdminCategoriesPage";
import { AdminAuditLogsPage } from "@/pages/admin/AdminAuditLogsPage";

import { NotFoundState } from "@/components/ui/ErrorState";

/**
 * The chrome for pages that belong to every signed-in role.
 *
 * Notifications, profile and settings are linked from the shared header
 * of all three layouts, and the backend sends notifications to staff and
 * admins as well as students. They were previously declared inside the
 * student-only group, which meant the bell icon led staff and admins
 * straight to "You don't have access to this page".
 *
 * Declaring them once here - rather than three times under each role's
 * guard - keeps the route definitions from drifting apart, and the
 * sidebar still matches whoever is looking at it.
 */
function RoleLayout() {
  const { user } = useAuth();

  if (user?.role === "admin") return <AdminLayout />;
  if (user?.role === "staff") return <StaffLayout />;

  return <StudentLayout />;
}

export const router = createBrowserRouter([
  // Public
  { path: "/", element: <LandingPage /> },
  { path: "/login", element: <LoginPage /> },
  { path: "/register", element: <RegisterPage /> },
  { path: "/forgot-password", element: <ForgotPasswordPage /> },
  { path: "/reset-password", element: <ResetPasswordPage /> },

  // Student
  {
    element: <ProtectedRoute allowedRoles={["student"]} />,
    children: [
      {
        element: <StudentLayout />,
        children: [
          { path: "/dashboard", element: <StudentDashboardPage /> },
          { path: "/lost-items", element: <LostItemsPage /> },
          { path: "/lost-items/report", element: <ReportLostItemPage /> },
          { path: "/lost-items/:id", element: <LostItemDetailPage /> },
          { path: "/found-items", element: <FoundItemsPage /> },
          { path: "/found-items/report", element: <ReportFoundItemPage /> },
          { path: "/found-items/:id", element: <FoundItemDetailPage /> },
          { path: "/search", element: <SearchPage /> },
          { path: "/search/advanced", element: <AdvancedSearchPage /> },
          { path: "/matches", element: <MatchesPage /> },
          { path: "/matches/:id", element: <MatchDetailPage /> },
          { path: "/claims", element: <ClaimsPage /> },
          { path: "/claims/new", element: <NewClaimPage /> },
          { path: "/claims/:id", element: <ClaimDetailPage /> },
        ],
      },
    ],
  },

  /**
   * Any signed-in role. ProtectedRoute with no allowedRoles only checks
   * that a session exists; the pages themselves are role-agnostic.
   */
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <RoleLayout />,
        children: [
          { path: "/notifications", element: <NotificationsPage /> },
          { path: "/profile", element: <ProfilePage /> },
          { path: "/settings", element: <SettingsPage /> },
        ],
      },
    ],
  },

  // Staff
  {
    element: <ProtectedRoute allowedRoles={["staff"]} />,
    children: [
      {
        element: <StaffLayout />,
        children: [
          { path: "/staff/dashboard", element: <StaffDashboardPage /> },
          { path: "/staff/found-items", element: <StaffFoundItemsPage /> },
          { path: "/staff/found-items/report", element: <ReportFoundItemPage /> },
          { path: "/staff/claims", element: <StaffClaimsPage /> },
          { path: "/staff/claims/:id", element: <StaffClaimDetailPage /> },
          { path: "/staff/items/:id", element: <StaffItemDetailPage /> },
        ],
      },
    ],
  },

  // Admin
  {
    element: <ProtectedRoute allowedRoles={["admin"]} />,
    children: [
      {
        element: <AdminLayout />,
        children: [
          { path: "/admin/dashboard", element: <AdminDashboardPage /> },
          { path: "/admin/users", element: <AdminUsersPage /> },
          { path: "/admin/students", element: <AdminStudentsPage /> },
          { path: "/admin/staff", element: <AdminStaffPage /> },
          { path: "/admin/lost-items", element: <AdminLostItemsPage /> },
          { path: "/admin/lost-items/:id", element: <LostItemDetailPage /> },
          { path: "/admin/found-items", element: <AdminFoundItemsPage /> },
          { path: "/admin/found-items/:id", element: <StaffItemDetailPage /> },
          { path: "/admin/claims", element: <AdminClaimsPage /> },
          { path: "/admin/claims/:id", element: <StaffClaimDetailPage /> },
          { path: "/admin/matches", element: <AdminMatchesPage /> },
          { path: "/admin/matches/:id", element: <MatchDetailPage /> },
          { path: "/admin/categories", element: <AdminCategoriesPage /> },
          { path: "/admin/audit-logs", element: <AdminAuditLogsPage /> },
        ],
      },
    ],
  },

  { path: "*", element: <NotFoundState resource="page" /> },
]);
