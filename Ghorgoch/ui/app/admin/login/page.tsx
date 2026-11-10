"use client";

import content from "@/data/admin/login.json";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import AdminLogin from "@/admin/components/AdminLogin";
import { useAdminAuth } from "@/admin/services/useAdminAuth";

export default function AdminLoginPage() {
  const router = useRouter();
  const { user, checking } = useAdminAuth();

  useEffect(() => {
    if (user) { router.replace("/admin"); }
  }, [user, router]);

  return <main id="main-content" tabIndex={-1} lang="en" className="page-container flex min-h-[70vh] flex-col justify-center gap-4 py-10 sm:py-16">
    {checking || user ? <p role="status" className="mx-auto text-brown">{content.pText}</p> : <AdminLogin />}
  </main>;
}
