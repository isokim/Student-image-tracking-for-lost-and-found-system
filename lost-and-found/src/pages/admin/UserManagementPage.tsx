import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Breadcrumbs } from "@/components/layout/Breadcrumbs";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { LoadingState } from "@/components/ui/LoadingState";
import { ErrorState } from "@/components/ui/ErrorState";
import { Pagination } from "@/components/ui/Pagination";
import { Avatar } from "@/components/ui/Avatar";
import { Button } from "@/components/ui/Button";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { Dropdown } from "@/components/ui/Dropdown";
import { Mail, MoreHorizontal } from "lucide-react";
import { adminService } from "@/features/admin/adminService";
import { useToast } from "@/components/ui/Toast";
import type { User, UserRole } from "@/types";

export function UserManagementPage({ role, title, breadcrumbLabel }: { role?: UserRole; title: string; breadcrumbLabel: string }) {
  const [page, setPage] = useState(1);
  const [suspendTarget, setSuspendTarget] = useState<User | null>(null);
  const [selectedStudentIds, setSelectedStudentIds] = useState<string[]>([]);
  const [resetTargets, setResetTargets] = useState<User[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const queryClient = useQueryClient();
  const { show } = useToast();
  const canSendResetEmails = role === "student";

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ["admin", "users", role, page],
    queryFn: () => adminService.listUsers({ role, page }),
  });

  async function handleSuspend() {
    if (!suspendTarget) return;
    setIsSubmitting(true);
    try {
      await adminService.suspendUser(suspendTarget.id);
      queryClient.invalidateQueries({ queryKey: ["admin", "users"] });
      show({ tone: "success", title: `${suspendTarget.name} suspended` });
    } finally {
      setIsSubmitting(false);
      setSuspendTarget(null);
    }
  }

  function toggleStudentSelection(userId: string) {
    setSelectedStudentIds((current) =>
      current.includes(userId) ? current.filter((id) => id !== userId) : [...current, userId],
    );
  }

  function selectVisibleStudents(users: User[]) {
    const visibleStudentIds = users.filter((user) => user.role === "student").map((user) => user.id);
    const allVisibleSelected = visibleStudentIds.every((id) => selectedStudentIds.includes(id));

    setSelectedStudentIds((current) =>
      allVisibleSelected
        ? current.filter((id) => !visibleStudentIds.includes(id))
        : Array.from(new Set([...current, ...visibleStudentIds])),
    );
  }

  function openBulkResetDialog(users: User[]) {
    setResetTargets(users.filter((user) => selectedStudentIds.includes(user.id) && user.role === "student"));
  }

  async function handleSendResetEmails() {
    if (resetTargets.length === 0) return;

    setIsSubmitting(true);
    try {
      await Promise.all(resetTargets.map((user) => adminService.sendPasswordResetEmail(user.email)));
      show({
        tone: "success",
        title: resetTargets.length === 1 ? "Reset email sent" : "Reset emails sent",
        description:
          resetTargets.length === 1
            ? `Sent to ${resetTargets[0].email}.`
            : `Sent to ${resetTargets.length} students.`,
      });
      setSelectedStudentIds((current) => current.filter((id) => !resetTargets.some((user) => user.id === id)));
    } finally {
      setIsSubmitting(false);
      setResetTargets([]);
    }
  }

  const columns: DataTableColumn<User>[] = [
    ...(canSendResetEmails
      ? [
          {
            key: "select",
            header: "",
            render: (row: User) =>
              row.role === "student" ? (
                <input
                  type="checkbox"
                  aria-label={`Select ${row.name}`}
                  checked={selectedStudentIds.includes(row.id)}
                  onChange={() => toggleStudentSelection(row.id)}
                  className="h-4 w-4 rounded border-border-strong"
                />
              ) : null,
          } satisfies DataTableColumn<User>,
        ]
      : []),
    {
      key: "name",
      header: "Name",
      render: (row) => (
        <div className="flex items-center gap-2">
          <Avatar name={row.name} src={row.avatarUrl} size="sm" />
          <span>{row.name}</span>
        </div>
      ),
    },
    { key: "email", header: "Email", render: (row) => row.email },
    { key: "role", header: "Role", render: (row) => <span className="capitalize">{row.role}</span> },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <Dropdown
          trigger={
            <span className="rounded-sm p-1.5 text-text-muted hover:bg-surface-sunken hover:text-text" aria-label={`Actions for ${row.name}`}>
              <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
            </span>
          }
          items={[
            ...(row.role === "student"
              ? [{ label: "Send reset email", onSelect: () => setResetTargets([row]) }]
              : []),
            { label: "Suspend account", onSelect: () => setSuspendTarget(row), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs items={[{ label: breadcrumbLabel }]} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-text">{title}</h1>
        {canSendResetEmails && data && data.data.length > 0 && (
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => selectVisibleStudents(data.data)}>
              Select page
            </Button>
            <Button
              type="button"
              onClick={() => openBulkResetDialog(data.data)}
              disabled={selectedStudentIds.length === 0}
            >
              <Mail className="h-4 w-4" aria-hidden="true" />
              Send reset email
            </Button>
          </div>
        )}
      </div>

      {isLoading ? (
        <LoadingState rows={5} />
      ) : isError ? (
        <ErrorState onRetry={refetch} description="Couldn't load users right now." />
      ) : data && data.data.length > 0 ? (
        <>
          <DataTable columns={columns} rows={data.data} rowKey={(row) => row.id} caption={title} />
          <Pagination currentPage={data.meta.currentPage} lastPage={data.meta.lastPage} onPageChange={setPage} />
        </>
      ) : (
        <EmptyState title="No users found" />
      )}

      <ConfirmationDialog
        isOpen={!!suspendTarget}
        onClose={() => setSuspendTarget(null)}
        onConfirm={handleSuspend}
        title="Suspend this account?"
        description={suspendTarget ? `${suspendTarget.name} will lose access until reinstated.` : ""}
        confirmLabel="Suspend"
        tone="danger"
        isLoading={isSubmitting}
      />

      <ConfirmationDialog
        isOpen={resetTargets.length > 0}
        onClose={() => setResetTargets([])}
        onConfirm={handleSendResetEmails}
        title={resetTargets.length === 1 ? "Send password reset email?" : "Send password reset emails?"}
        description={
          resetTargets.length === 1
            ? `A reset link will be emailed to ${resetTargets[0].email}.`
            : `Reset links will be emailed to ${resetTargets.length} selected students.`
        }
        confirmLabel="Send email"
        isLoading={isSubmitting}
      />
    </div>
  );
}
