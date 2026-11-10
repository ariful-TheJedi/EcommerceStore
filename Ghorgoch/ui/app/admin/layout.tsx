import pageData from "@/data/admin/header.json";

import type { Metadata } from "next";
import type { ReactNode } from "react";
import { AdminAuthProvider } from "@/admin/services/useAdminAuth";
import AdminShell from "@/admin/components/AdminShell";

const content = pageData.metadata;

export const metadata: Metadata = { title: content.administrationGhorgoch, robots: { index: false, follow: false } };
export default function AdminLayout({ children }: { children: ReactNode }) { return <AdminAuthProvider><AdminShell>{children}</AdminShell></AdminAuthProvider>; }
