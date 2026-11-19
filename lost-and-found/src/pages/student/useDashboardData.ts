import { useQuery } from "@tanstack/react-query";
import { lostItemService } from "@/features/lost-items/lostItemService";
import { matchService } from "@/features/matches/matchService";
import { claimService } from "@/features/claims/claimService";
import { notificationService } from "@/features/notifications/notificationService";

export function useDashboardData() {
  const lostItems = useQuery({ queryKey: ["lost-items", "mine"], queryFn: lostItemService.mine });
  const matches = useQuery({ queryKey: ["matches"], queryFn: matchService.list });
  const claims = useQuery({ queryKey: ["claims"], queryFn: claimService.list });
  const notifications = useQuery({ queryKey: ["notifications"], queryFn: notificationService.list });

  return { lostItems, matches, claims, notifications };
}
