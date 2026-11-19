import { QueryClientProvider } from "@tanstack/react-query";
import { queryClient } from "@/lib/queryClient";
import { RouterProvider } from "react-router-dom";
import { AuthProvider } from "@/features/auth/AuthContext";
import { ToastProvider } from "@/components/ui/Toast";
import { router } from "@/routes/router";

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </AuthProvider>
    </QueryClientProvider>
  );
}
