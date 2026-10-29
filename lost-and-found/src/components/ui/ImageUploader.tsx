import { useRef, useState, type ChangeEvent, type DragEvent } from "react";
import { ImagePlus, Loader2, Trash2, UploadCloud } from "lucide-react";
import { cn } from "@/lib/cn";
import { formatFileSize } from "@/lib/format";

const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_SIZE_BYTES = 8 * 1024 * 1024; // 8MB

interface ImageUploaderProps {
  value?: File | null;
  onChange: (file: File | null) => void;
  isUploading?: boolean;
  error?: string;
  label?: string;
}

export function ImageUploader({ value, onChange, isUploading, error, label = "Photograph" }: ImageUploaderProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [localError, setLocalError] = useState<string | null>(null);
  const [isDragging, setIsDragging] = useState(false);

  const previewUrl = value ? URL.createObjectURL(value) : null;
  const displayError = error ?? localError ?? undefined;

  function validateAndSet(file: File | undefined) {
    if (!file) return;
    if (!ACCEPTED_TYPES.includes(file.type)) {
      setLocalError("Use a JPG, PNG, or WEBP file.");
      return;
    }
    if (file.size > MAX_SIZE_BYTES) {
      setLocalError("File is larger than 8MB. Choose a smaller image.");
      return;
    }
    setLocalError(null);
    onChange(file);
  }

  function handleDrop(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setIsDragging(false);
    validateAndSet(e.dataTransfer.files[0]);
  }

  function handleChange(e: ChangeEvent<HTMLInputElement>) {
    validateAndSet(e.target.files?.[0]);
  }

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-sm font-medium text-text">{label}</span>

      {value && previewUrl ? (
        <div className="flex items-center gap-3 rounded-md border border-border-strong bg-surface p-3">
          <img
            src={previewUrl}
            alt={`Preview of selected ${label.toLowerCase()}`}
            className="h-16 w-16 rounded-md object-cover"
          />
          <div className="flex-1 min-w-0">
            <p className="truncate text-sm text-text">{value.name}</p>
            <p className="text-xs text-text-muted">{formatFileSize(value.size)}</p>
            {isUploading && (
              <p className="mt-1 flex items-center gap-1.5 text-xs text-info">
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
                Uploading…
              </p>
            )}
          </div>
          <div className="flex gap-1">
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              className="rounded-sm p-1.5 text-text-muted hover:bg-surface-sunken hover:text-text"
              aria-label="Replace photo"
            >
              <ImagePlus className="h-4 w-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              onClick={() => onChange(null)}
              className="rounded-sm p-1.5 text-text-muted hover:bg-error-bg hover:text-error"
              aria-label="Remove photo"
            >
              <Trash2 className="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        </div>
      ) : (
        <div
          onDragOver={(e) => {
            e.preventDefault();
            setIsDragging(true);
          }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={handleDrop}
          className={cn(
            "flex flex-col items-center justify-center gap-2 rounded-md border border-dashed px-6 py-8 text-center",
            isDragging ? "border-navy-600 bg-navy-50" : "border-border-strong",
          )}
        >
          <UploadCloud className="h-5 w-5 text-text-faint" aria-hidden="true" />
          <p className="text-sm text-text-muted">Drag a photo here, or</p>
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="text-sm font-medium text-navy-700 underline underline-offset-2 hover:text-navy-900"
          >
            choose a file
          </button>
          <p className="text-xs text-text-faint">JPG, PNG, or WEBP — up to 8MB</p>
        </div>
      )}

      <input
        ref={inputRef}
        type="file"
        accept={ACCEPTED_TYPES.join(",")}
        onChange={handleChange}
        className="sr-only"
        aria-label={label}
      />

      {displayError && (
        <p className="text-xs text-error" role="alert">
          {displayError}
        </p>
      )}
    </div>
  );
}
