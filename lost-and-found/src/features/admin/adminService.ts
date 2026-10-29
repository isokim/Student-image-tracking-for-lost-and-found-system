import { apiDelete, apiGet, apiPost, apiPut } from "@/lib/apiClient";
import type { PaginatedResponse, User } from "@/types";

export interface AuditLogEntry {
  id: string;
  /** Null for events with no signed-in user, such as a failed login. */
  actor: Pick<User, "id" | "name" | "role"> | null;
  action: string;
  targetType: string | null;
  targetId: string | null;
  description?: string | null;
  ipAddress?: string | null;
  createdAt: string;
}

export interface SystemStats {
  activeLostReports: number;
  pendingFoundVerifications: number;
  pendingClaims: number;
  itemsReturnedThisMonth: number;
}

export interface Category {
  id: string;
  slug: string;
  name: string;
  isActive: boolean;
}

export interface CreateStaffPayload {
  name: string;
  email: string;
  role: "staff" | "admin";
  departmentId: string;
  password: string;
  passwordConfirmation: string;
}

export const adminService = {
  // --- users ---------------------------------------------------------
  async listUsers(params?: { role?: string; status?: string; search?: string; page?: number }): Promise<PaginatedResponse<User>> {
    return apiGet<PaginatedResponse<User>>("/admin/users", { params });
  },

  async getUser(id: string): Promise<User> {
    return apiGet<User>(`/admin/users/${id}`);
  },

  async suspendUser(id: string): Promise<User> {
    return apiPost<User>(`/admin/users/${id}/suspend`);
  },

  async reinstateUser(id: string): Promise<User> {
    return apiPost<User>(`/admin/users/${id}/reinstate`);
  },

  async changeUserRole(id: string, role: User["role"]): Promise<User> {
    return apiPost<User>(`/admin/users/${id}/role`, { role });
  },

  async sendPasswordResetEmail(email: string): Promise<{ message: string }> {
    return apiPost<{ message: string }>("/forgot-password", { email });
  },

  /**
   * The controlled process for privileged accounts. Students register
   * themselves; staff and admins can only be created here (or through
   * `php artisan app:create-staff` on the server).
   */
  async createStaff(payload: CreateStaffPayload): Promise<User> {
    return apiPost<User>("/admin/staff", payload);
  },

  // --- categories ----------------------------------------------------
  async listCategories(): Promise<Category[]> {
    return apiGet<Category[]>("/admin/categories");
  },

  async createCategory(payload: { slug: string; name: string }): Promise<Category> {
    return apiPost<Category>("/admin/categories", payload);
  },

  async updateCategory(id: string, payload: { name?: string; isActive?: boolean }): Promise<Category> {
    return apiPut<Category>(`/admin/categories/${id}`, payload);
  },

  async deactivateCategory(id: string): Promise<void> {
    await apiDelete<{ message: string }>(`/admin/categories/${id}`);
  },

  // --- audit + stats -------------------------------------------------
  async auditLogs(params?: { page?: number; action?: string; userId?: string; entityType?: string }): Promise<PaginatedResponse<AuditLogEntry>> {
    return apiGet<PaginatedResponse<AuditLogEntry>>("/admin/audit-logs", { params });
  },

  async stats(): Promise<SystemStats> {
    return apiGet<SystemStats>("/admin/stats");
  },
};
