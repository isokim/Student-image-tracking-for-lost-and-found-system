import { apiGet, apiPost } from "@/lib/apiClient";
import type { FoundItemReport, MatchScoreBreakdown } from "@/types";

export interface SearchFilters {
  query?: string;
  category?: string;
  colour?: string;
  /** Free-text place, matched against what reporters typed. */
  location?: string;
  dateFrom?: string;
  dateTo?: string;
}

/**
 * One image-search hit.
 *
 * Deliberately NOT `extends ItemMatch`. An ItemMatch pairs a lost report
 * with a found item, and an image search has no lost report - the student
 * searched with a photo instead of filing one. Extending it required a
 * `lostItem` the API cannot supply, which is why the previous version had
 * to be cast into existence.
 *
 * `scores` may be near-empty: only the signals actually measured are
 * present (visual similarity for an image search, nothing for a keyword
 * search).
 */
export interface ImageSearchResult {
  id: string;
  matchedItem: FoundItemReport;
  scores: MatchScoreBreakdown;
  overallConfidence: "possible" | "likely";
}

export const searchService = {
  /** Keyword and filter search across verified found items. */
  async search(filters: SearchFilters): Promise<FoundItemReport[]> {
    return apiGet<FoundItemReport[]>("/search", { params: filters });
  },

  /**
   * AI-assisted image search.
   *
   * The photo is embedded by the AI service and compared against the
   * index; it is not stored as a report. Results still come back through
   * Laravel's visibility rules, so unverified items never appear here
   * however similar they look.
   *
   * Throws a 503 (not an empty list) when the AI service is unavailable,
   * so the UI can say "image search is down" rather than "no results".
   */
  async searchByImage(image: File, filters?: SearchFilters): Promise<ImageSearchResult[]> {
    const form = new FormData();
    form.append("image", image);

    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value === undefined || value === null) return;
        form.append(key, String(value));
      });
    }

    return apiPost<ImageSearchResult[]>("/search/image", form, {
      headers: { "Content-Type": "multipart/form-data" },
    });
  },
};
