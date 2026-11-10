import { Activity, Boxes, ClipboardList, LayoutDashboard, LogOut, Package, PackagePlus, PencilLine, Settings, Users, UserPlus, type LucideIcon } from "lucide-react";

import header from "@/data/admin/header.json";
import sidebar from "@/data/admin/sidebar.json";

export const adminNavigation = header;

type SidebarItem = { label: string; icon: LucideIcon; group?: "account" } & (
  { href: string; action?: never } | { action: "logout"; href?: never }
);

const icons: Record<string, LucideIcon> = { Activity, Boxes, ClipboardList, LayoutDashboard, LogOut, Package, PackagePlus, PencilLine, Settings, Users, UserList: Users, UserPlus };

export const sidebarItems: SidebarItem[] = sidebar.items.map((item) => ({ ...item, icon: icons[item.icon] ?? LayoutDashboard })) as SidebarItem[];
