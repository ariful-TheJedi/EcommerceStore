"use client";

import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

export default function StorefrontFrame({ children, navigation, footer }: { children: ReactNode; navigation: ReactNode; footer: ReactNode }) {
  const pathname = usePathname();
  const isAdmin = pathname === "/admin" || pathname.startsWith("/admin/");
  return <>{!isAdmin && navigation}{children}{!isAdmin && footer}</>;
}
