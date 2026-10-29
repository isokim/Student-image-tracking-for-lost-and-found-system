import { apiDelete, apiGet, apiPost, apiPut } from "@/lib/apiClient";
import type { ItemMatch, LostItemReport, PaginatedResponse } from "@/types";

export interface CreateLostItemPayload {
  title: string;
  category: string;
  description: string;
  colour?: string;
  /** Where the owner last had it, typed in their own words. */
  location: string;
  /** Date only, `YYYY-MM-DD`. The time of day is no longer collected. */
  lostAt: string;
  additionalInfo?: string;
  photo?: File;
}

export type UpdateLostItemPayload = Partial<Omit<CreateLostItemPayload, "photo">>;

/**
 * Builds the multipart body for endpoints that accept a photo.
 *
 * Undefined values are skipped rather than sent as the string
 * "undefined", which is what FormData would otherwise do to them.
 */
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

export const lostItemService = {
  /** The board: everyone's open lost reports, paginated. */
  async list(params?: { page?: number; status?: string }): Promise<PaginatedResponse<LostItemReport>> {
    return apiGet<PaginatedResponse<LostItemReport>>("/lost-items", { params });
  },

  /** Only the signed-in user's own reports. */
  async mine(): Promise<LostItemReport[]> {
    return apiGet<LostItemReport[]>("/lost-items/mine");
  },

  async get(id: string): Promise<LostItemReport> {
    return apiGet<LostItemReport>(`/lost-items/${id}`);
  },

  async create(payload: CreateLostItemPayload): Promise<LostItemReport> {
    return apiPost<LostItemReport>("/lost-items", toFormData(payload), {
      headers: { "Content-Type": "multipart/form-data" },
    });
  },

  async update(id: string, payload: UpdateLostItemPayload): Promise<LostItemReport> {
    return apiPut<LostItemReport>(`/lost-items/${id}`, payload);
  },

  /**
   * Closes the report. Pass resolved: true when the owner got the item
   * back, so it is recorded as resolved rather than abandoned.
   */
  async close(id: string, resolved = false): Promise<LostItemReport> {
    return apiPost<LostItemReport>(`/lost-items/${id}/close`, { resolved });
  },

  async findMatches(id: string): Promise<ItemMatch[]> {
    return apiPost<ItemMatch[]>(`/lost-items/${id}/find-matches`);
  },

  async remove(id: string): Promise<void> {
    await apiDelete<{ message: string }>(`/lost-items/${id}`);
  },
};
