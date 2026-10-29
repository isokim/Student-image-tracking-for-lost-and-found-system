import { Link } from "react-router-dom";
import { ImageOff, MapPin } from "lucide-react";
import { formatDate, formatLocation } from "@/lib/format";
import { FoundItemStatusBadge, LostItemStatusBadge } from "@/components/common/ItemStatusBadge";
import type { FoundItemReport, LostItemReport } from "@/types";

interface ItemCardProps {
  item: LostItemReport | FoundItemReport;
  kind: "lost" | "found";
  href: string;
}

export function ItemCard({ item, kind, href }: ItemCardProps) {
  const dateLabel = kind === "lost" ? formatDate((item as LostItemReport).lostAt) : formatDate((item as FoundItemReport).foundAt);

  return (
    <Link
      to={href}
      className="flex gap-3 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-border-strong focus-visible:outline-2 focus-visible:outline-navy-600"
    >
      <div className="h-16 w-16 shrink-0 overflow-hidden rounded-md bg-surface-sunken">
        {item.photo ? (
          <img src={item.photo.thumbnailUrl ?? item.photo.url} alt="" className="h-full w-full object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-text-faint">
            <ImageOff className="h-5 w-5" aria-hidden="true" />
          </div>
        )}
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-2">
          <h3 className="truncate text-sm font-medium text-text">{item.title}</h3>
          {kind === "lost" ? (
            <LostItemStatusBadge status={(item as LostItemReport).status} />
          ) : (
            <FoundItemStatusBadge status={(item as FoundItemReport).status} />
          )}
        </div>
        <p className="mt-0.5 line-clamp-1 text-sm text-text-muted">{item.description}</p>
        <div className="mt-1.5 flex items-center gap-3 text-xs text-text-faint">
          <span className="flex items-center gap-1">
            <MapPin className="h-3 w-3" aria-hidden="true" />
            {formatLocation(item)}
          </span>
          <span>{dateLabel}</span>
        </div>
      </div>
    </Link>
  );
}
