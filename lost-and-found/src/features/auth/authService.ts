import { apiGet, apiPost, ensureCsrfCookie } from "@/lib/apiClient";
import type { User } from "@/types";

export interface LoginPayload {
  email: string;
  password: string;
  remember?: boolean;
}

export interface RegisterPayload {
  name: string;
  email: string;
  admissionNumber: string;
  password: string;
  passwordConfirmation: string;
}

export const authService = {
  async login(payload: LoginPayload): Promise<User> {
    await ensureCsrfCookie();
    return apiPost<User>("/login", payload);
  },

  async register(payload: RegisterPayload): Promise<User> {
    await ensureCsrfCookie();
    return apiPost<User>("/register", payload);
  },

  async logout(): Promise<void> {
    await apiPost<void>("/logout");
  },

  async me(): Promise<User> {
    return apiGet<User>("/user");
  },

  async requestPasswordReset(email: string): Promise<void> {
    await ensureCsrfCookie();
    await apiPost<void>("/forgot-password", { email });
  },

  async resetPassword(payload: {
    token: string;
    email: string;
    password: string;
    passwordConfirmation: string;
  }): Promise<void> {
    await ensureCsrfCookie();
    await apiPost<void>("/reset-password", payload);
  },
};
