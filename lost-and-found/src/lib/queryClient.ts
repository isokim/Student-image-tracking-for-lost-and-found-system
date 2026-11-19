import { QueryClient } from "@tanstack/react-query";

/**
 * The one React Query client, shared by the app and the API client.
 *
 * There are no live (websocket) updates, so the site keeps itself current
 * in three ways:
 *  - data is only treated as fresh for a few seconds, so moving between
 *    pages re-checks the server;
 *  - open pages re-check every 20 seconds while the tab is visible, so a
 *    change made by someone else (staff approving a claim) shows up on
 *    its own;
 *  - every successful change refreshes everything (see apiClient.ts).
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 5_000,
      refetchInterval: 20_000,
      // Polling pauses while the tab is hidden; it re-checks on focus.
      refetchIntervalInBackground: false,
      refetchOnWindowFocus: true,
    },
  },
});
