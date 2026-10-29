import { apiGet, apiPost } from "@/lib/apiClient";
import type { Claim, ClaimVerificationAnswer } from "@/types";

export interface CreateClaimPayload {
  foundItemId: string;
  /** The AI suggestion that prompted the claim, if there was one. */
  matchId?: string;
  identifyingInformation: string;
  verificationAnswers: ClaimVerificationAnswer[];
}

export const claimService = {
  /**
   * A student gets their own claims; staff get the review queue. The
   * API scopes this by role - there is no client-side filtering to
   * bypass.
   */
  async list(): Promise<Claim[]> {
    return apiGet<Claim[]>("/claims");
  },

  /**
   * Staff-only variant, filtered by status.
   *
   * Kept separate from list() because list() is passed directly as a
   * react-query queryFn, which would call it with a query context as
   * its first argument.
   */
  async listByStatus(status: string): Promise<Claim[]> {
    return apiGet<Claim[]>("/claims", { params: { status } });
  },

  async get(id: string): Promise<Claim> {
    return apiGet<Claim>(`/claims/${id}`);
  },

  async create(payload: CreateClaimPayload): Promise<Claim> {
    return apiPost<Claim>("/claims", payload);
  },

  /** Staff only. */
  async approve(id: string, notes?: string): Promise<Claim> {
    return apiPost<Claim>(`/claims/${id}/approve`, { notes });
  },

  /** Staff only. Notes are required so the student knows why. */
  async reject(id: string, notes: string): Promise<Claim> {
    return apiPost<Claim>(`/claims/${id}/reject`, { notes });
  },

  /** Staff only. Records that the item was physically collected. */
  async markReturned(id: string): Promise<Claim> {
    return apiPost<Claim>(`/claims/${id}/return`);
  },
};
