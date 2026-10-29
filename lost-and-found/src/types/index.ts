// ---------------------------------------------------------------------------
// Core domain types. These mirror the shape the Laravel API is expected to
// return. Keep this file the single source of truth for shared shapes;
// feature-specific request/response DTOs live in each feature's types file.
// ---------------------------------------------------------------------------

export type UserRole = "student" | "staff" | "admin";

export interface User {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  admissionNumber?: string; // students only
  department?: string; // staff/admin
  avatarUrl?: string;
  emailVerifiedAt?: string | null;
  createdAt: string;
}

export type ItemCategory =
  | "electronics"
  | "documents"
  | "clothing"
  | "bags"
  | "accessories"
  | "keys"
  | "books"
  | "other";

export const ITEM_CATEGORIES: { value: ItemCategory; label: string }[] = [
  { value: "electronics", label: "Electronics" },
  { value: "documents", label: "Documents & ID" },
  { value: "clothing", label: "Clothing" },
  { value: "bags", label: "Bags" },
  { value: "accessories", label: "Accessories" },
  { value: "keys", label: "Keys" },
  { value: "books", label: "Books & stationery" },
  { value: "other", label: "Other" },
];

export type LostItemStatus = "active" | "matched" | "resolved" | "closed";
export type FoundItemStatus =
  | "pending_verification"
  | "verified"
  | "claimed"
  | "returned"
  | "rejected";

export interface ItemPhoto {
  id: string;
  url: string;
  thumbnailUrl?: string;
}

export interface LostItemReport {
  id: string;
  title: string;
  category: ItemCategory;
  description: string;
  colour?: string;
  /**
   * Where the reporter says they lost it, in their own words. This is
   * what the report form collects and the only location there is.
   */
  locationText?: string | null;
  lostAt: string; // ISO date
  photo?: ItemPhoto;
  additionalInfo?: string;
  status: LostItemStatus;
  reportedBy: Pick<User, "id" | "name">;
  createdAt: string;
}

export interface FoundItemReport {
  id: string;
  title: string;
  category: ItemCategory;
  description: string; // public-facing description
  privateVerificationInfo?: string; // never shown publicly
  colour?: string;
  /** See `LostItemReport.locationText`. */
  locationText?: string | null;
  foundAt: string;
  photo?: ItemPhoto;
  additionalInfo?: string;
  status: FoundItemStatus;
  reportedBy: Pick<User, "id" | "name" | "role">;
  verifiedBy?: Pick<User, "id" | "name">;
  createdAt: string;
}

export interface MatchScoreBreakdown {
  // Every field is optional because not every signal is measured on
  // every result. An image search compares a photo against items with
  // no corresponding lost report, so there is no description, category
  // or location to compare - the API omits those keys rather than
  // sending zeros, which would read as "measured, and bad".
  visualSimilarity?: number; // 0-100, only when an image was compared
  descriptionSimilarity?: number; // 0-100
  categoryMatch?: boolean;
  colourMatch?: boolean;
  locationProximity?: "same" | "close" | "different";
}

export interface ItemMatch {
  id: string;
  lostItem: LostItemReport;
  foundItem: FoundItemReport;
  overallConfidence: "possible" | "likely"; // never "guaranteed"
  scores: MatchScoreBreakdown;
  status: "pending_review" | "dismissed" | "claim_started";
  createdAt: string;
}

export type ClaimStatus =
  | "draft"
  | "pending_review"
  | "approved"
  | "rejected"
  | "completed";

export interface ClaimVerificationAnswer {
  question: string;
  answer: string;
}

export interface Claim {
  id: string;
  foundItem: FoundItemReport;
  lostItem?: LostItemReport;
  claimant: Pick<User, "id" | "name">;
  identifyingInformation: string;
  verificationAnswers: ClaimVerificationAnswer[];
  status: ClaimStatus;
  reviewedBy?: Pick<User, "id" | "name">;
  reviewNotes?: string;
  createdAt: string;
  updatedAt: string;
}

export type NotificationType =
  | "possible_match"
  | "claim_approved"
  | "claim_rejected"
  | "found_item_verified"
  | "item_returned"
  | "staff_message";

export interface AppNotification {
  id: string;
  type: NotificationType;
  title: string;
  body: string;
  readAt: string | null;
  relatedResourceUrl?: string;
  createdAt: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
  };
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}
