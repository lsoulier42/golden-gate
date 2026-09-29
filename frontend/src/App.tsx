import { Navigate, Route, Routes } from 'react-router';
import { AlertTriangle } from 'lucide-react';
import { useAuth } from './auth/useAuth';
import { RequireAuth, RequireRole } from './auth/guards';
import { AppLayout } from './layouts/AppLayout';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { ProfilePage } from './pages/ProfilePage';
import { UsersPage } from './pages/admin/UsersPage';
import { DashboardPage } from './pages/admin/DashboardPage';
import { ApiKeysPage } from './pages/admin/ApiKeysPage';
import { ModelsPage } from './pages/admin/ModelsPage';
import { UsagePage } from './pages/admin/UsagePage';

/** Redirects the root path according to the authentication state. */
export function RootRedirect() {
  const { isAuthenticated } = useAuth();
  return <Navigate to={isAuthenticated ? '/profile' : '/login'} replace />;
}

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-app px-4 text-center">
      <AlertTriangle className="h-10 w-10 text-warning" aria-hidden />
      <h1 className="text-2xl font-bold text-ink">Page introuvable</h1>
      <p className="text-sm text-muted">La page demandée n'existe pas.</p>
    </div>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route path="/profile" element={<ProfilePage />} />
        <Route
          path="/admin/dashboard"
          element={
            <RequireRole role="ROLE_ADMIN">
              <DashboardPage />
            </RequireRole>
          }
        />
        <Route
          path="/admin/api-keys"
          element={
            <RequireRole role="ROLE_ADMIN">
              <ApiKeysPage />
            </RequireRole>
          }
        />
        <Route
          path="/admin/models"
          element={
            <RequireRole role="ROLE_ADMIN">
              <ModelsPage />
            </RequireRole>
          }
        />
        <Route
          path="/admin/usage"
          element={
            <RequireRole role="ROLE_ADMIN">
              <UsagePage />
            </RequireRole>
          }
        />
        <Route
          path="/admin/users"
          element={
            <RequireRole role="ROLE_ADMIN">
              <UsersPage />
            </RequireRole>
          }
        />
      </Route>
      <Route path="/" element={<RootRedirect />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}