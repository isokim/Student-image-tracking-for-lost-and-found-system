import { cn } from "@/lib/cn";

function initials(name: string) {
  const parts = name.trim().split(/\s+/);
  return (parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "");
}

export function Avatar({
  name,
  src,
  size = "md",
}: {
  name: string;
  src?: string;
  size?: "sm" | "md" | "lg";
}) {
  const sizeClasses = { sm: "h-7 w-7 text-xs", md: "h-9 w-9 text-sm", lg: "h-12 w-12 text-base" }[size];

  if (src) {
    return (
      <img
        src={src}
        alt=""
        className={cn("rounded-full object-cover", sizeClasses)}
      />
    );
  }

  return (
    <span
      className={cn(
        "inline-flex items-center justify-center rounded-full bg-navy-100 font-medium text-navy-900",
        sizeClasses,
      )}
      aria-hidden="true"
    >
      {initials(name).toUpperCase()}
    </span>
  );
}
