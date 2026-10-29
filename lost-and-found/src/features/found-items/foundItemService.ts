import { apiDelete, apiGet, apiPost, apiPut } from "@/lib/apiClient";
import type { FoundItemReport, PaginatedResponse } from "@/types";

export interface CreateFoundItemPayload {
  title: string;
  category: string;
  description: string;
  /**
   * The detail only the true owner would know. The API never returns
   * this to anyone but the finder and staff - it is what claims are
   * checked against.
   */
  privateVerificationInfo?: string;
  colour?: string;
  /** Where the finder picked it up, typed in their own words. */
  location: string;
  /** Date only, `YYYY-MM-DD`. The time of day is no longer collected. */
  foundAt: string;
  additionalInfo?: string;
  photo?: File;
}

export type UpdateFoundItemPayload = Partial<Omit<CreateFoundItemPayload, "photo">>;

function toFormData(payload: object): FormData {
  const form = new FormData();

  // Cast here rather than adding an index signature to the payload
  // interfaces, which would let any stray key through unnoticed.
  Object.entries(payload as Record<string, unknown>).forEach(([key, value]) => {
    if (value === undefined || value === null) return;
    form.append(key, value instanceof File ? value : String(value));
  });

  return form;
}

export const foundItemService = {
  /**
   * Students see public found reports here; staff see everything,
   * including what is still awaiting verification. The API decides
   * which, based on the caller's role.
   */
  async list(params?: { page?: number; status?: string }): Promise<PaginatedResponse<FoundItemReport>> {
    return apiGet<PaginatedResponse<FoundItemReport>>("/found-items", { params });
  },

  /** Items the signed-in user handed in. */
  async mine(): Promise<FoundItemReport[]> {
    return apiGet<FoundItemReport[]>("/found-items/mine");
  },

  async get(id: string): Promise<FoundItemReport> {
    return apiGet<FoundItemReport>(`/found-items/${id}`);
  },

  async create(payload: CreateFoundItemPayload): Promise<FoundItemReport> {
    return apiPost<FoundItemReport>("/found-items", toFormData(payload), {
      headers: { "Content-Type": "multipart/form-data" },
    });
  },

  async update(id: string, payload: UpdateFoundItemPayload): Promise<FoundItemReport> {
    return apiPut<FoundItemReport>(`/found-items/${id}`, payload);
  },

  /** Staff only. Makes the item searchable and eligible for matching. */
  async verify(id: string): Promise<FoundItemReport> {
    return apiPost<FoundItemReport>(`/found-items/${id}/verify`);
  },

  /** Staff only. A reason is required - the finder is told why. */
  async reject(id: string, reason: string): Promise<FoundItemReport> {
    return apiPost<FoundItemReport>(`/found-items/${id}/reject`, { reason });
  },

  async remove(id: string): Promise<void> {
    await apiDelete<{ message: string }>(`/found-items/${id}`);
  },
};
