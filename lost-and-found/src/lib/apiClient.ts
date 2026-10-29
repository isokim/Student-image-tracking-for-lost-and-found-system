import axios, { type AxiosError, type AxiosRequestConfig } from "axios";
import type { ApiError } from "@/types";

// Single axios instance the whole app shares. Feature services build on
// top of this rather than importing axios directly, so auth headers,
// error shape, and base URL only live in one place.
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "/api",
  withCredentials: true, // required for Laravel Sanctum's SPA cookie auth
  // The API runs on a different port (5173 -> 8002), so it is a different
  // origin. Since axios 1.6 the X-XSRF-TOKEN header is no longer attached
  // automatically on cross-origin requests, which made every state-changing
  // request fail with 419 ("session expired"). This opts back in.
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
  },
});

// Laravel Sanctum SPA auth needs the CSRF cookie fetched once before any
// state-changing request. Call this before login/register.
export async function ensureCsrfCookie() {
  const base = import.meta.env.VITE_API_URL?.replace(/\/api\/?$/, "") ?? "";
  await axios.get(`${base}/sanctum/csrf-cookie`, { withCredentials: true });
}

/**
 * The auth layer subscribes here, so a dead session is noticed wherever
 * it surfaces - a background refetch is as likely as a click.
 */
let onUnauthenticated: (() => void) | null = null;

export function setUnauthenticatedHandler(handler: (() => void) | null) {
  onUnauthenticated = handler;
}

type RetryableConfig = AxiosRequestConfig & { csrfRetried?: boolean };

/**
 * Repairs a stale CSRF token, and reports a genuinely dead session.
 *
 * 419 means the token the browser sent does not match the session it was
 * sent with. That happens for ordinary reasons: the tab sat open past
 * SESSION_LIFETIME, or signing out in another tab rotated the token. The
 * fix is always the same - fetch a fresh cookie and send the request
 * again - so the app does it rather than showing the user a CSRF error
 * they can do nothing about.
 *
 * 401 is different: the session is gone. There is nothing to retry, so
 * the auth layer is told and it drops the user to the login page, which
 * is much better than leaving them on a page whose data will never load.
 */
apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const status = error.response?.status;
    const config = error.config as RetryableConfig | undefined;

    if (status === 419 && config && !config.csrfRetried) {
      // Once only, or a request that keeps 419-ing would loop.
      config.csrfRetried = true;

      try {
        await ensureCsrfCookie();
        return await apiClient.request(config);
      } catch {
        // The refresh itself failed (offline, server down); fall through
        // and report the original error rather than this one.
      }
    }

    if (status === 401) {
      onUnauthenticated?.();
    }

    return Promise.reject(error);
  },
);

export function normalizeApiError(error: unknown): ApiError {
  const axiosError = error as AxiosError<ApiError>;
  if (axiosError.response?.data) {
    return axiosError.response.data;
  }
  if (axiosError.message === "Network Error") {
    return { message: "Can't reach the server. Check your connection and try again." };
  }
  return { message: "Something went wrong. Please try again." };
}

export async function apiGet<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const res = await apiClient.get<T>(url, config);
  return res.data;
}

export async function apiPost<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await apiClient.post<T>(url, body, config);
  return res.data;
}

export async function apiPut<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await apiClient.put<T>(url, body, config);
  return res.data;
}

export async function apiDelete<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const res = await apiClient.delete<T>(url, config);
  return res.data;
}
