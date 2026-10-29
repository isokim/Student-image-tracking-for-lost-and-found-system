import { apiGet, apiPost } from "@/lib/apiClient";
import type { ItemMatch } from "@/types";

export const matchService = {
  /**
   * The signed-in student's own suggestions, strongest first.
   *
   * Matches belong to whoever filed the lost report, so the API scopes
   * this automatically - dismissed ones are already excluded.
   */
  async list(): Promise<ItemMatch[]> {
    return apiGet<ItemMatch[]>("/matches");
  },

  async get(id: string): Promise<ItemMatch> {
    return apiGet<ItemMatch>(`/matches/${id}`);
  },

  /** "That is not my item." Only the owning student can say this. */
  async dismiss(id: string): Promise<ItemMatch> {
    return apiPost<ItemMatch>(`/matches/${id}/dismiss`);
  },
};
