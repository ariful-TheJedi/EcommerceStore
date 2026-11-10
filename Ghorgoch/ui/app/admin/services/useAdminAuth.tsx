"use client";

import pageData from "@/data/admin/messages.json";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { adminRequest, AdminApiError } from "./request";

const content = pageData.auth;

export type AdminUser = { id: number; name: string; email: string; role?: "admin" | "staff"; avatar_url?: string | null };
type AdminAuth = {
  user: AdminUser | null;
  checking: boolean;
  error: string;
  refresh: () => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  request: typeof adminRequest;
  updateUser: (user: AdminUser) => void;
};
const AdminAuthContext = createContext<AdminAuth | null>(null);

export function AdminAuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AdminUser | null>(null);
  const [checking, setChecking] = useState(true);
  const [error, setError] = useState("");

  const request: typeof adminRequest = useCallback(async (path, options) => {
    try { return await adminRequest(path, options); }
    catch (failure) {
      if (failure instanceof AdminApiError && failure.status === 401) { setUser(null); }
      throw failure;
    }
  }, []);

  const refresh = useCallback(async () => {
    setChecking(true); setError("");
    try {
      const data = await request<{ user: AdminUser }>("/auth/staff/me", { signal: AbortSignal.timeout(10000) });
      setUser(data.user);
    } catch (failure) {
      setUser(null);
      if (!(failure instanceof AdminApiError && failure.status === 401)) { setError(failure instanceof Error ? failure.message : content.theRequestCouldNotBeCompleted); }
    } finally { setChecking(false); }
  }, [request]);

  useEffect(() => { void refresh(); }, [refresh]);

  async function login(email: string, password: string) {
    const data = await request<{ user: AdminUser }>("/auth/staff/login", { method: "POST", body: { email, password } });
    setUser(data.user); setError("");
  }

  async function logout() {
    try { await request("/auth/staff/logout", { method: "POST" }); }
    catch (failure) { if (!(failure instanceof AdminApiError && failure.status === 401)) { throw failure; } }
    setUser(null); setError("");
  }

  return <AdminAuthContext.Provider value={{ user, checking, error, refresh, login, logout, request, updateUser: setUser }}>{children}</AdminAuthContext.Provider>;
}

export function useAdminAuth(): AdminAuth {
  const context = useContext(AdminAuthContext);
  if (!context) { throw new Error("useAdminAuth must be used within AdminAuthProvider."); }
  return context;
}
