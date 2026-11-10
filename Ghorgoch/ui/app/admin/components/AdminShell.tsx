"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { Menu, X } from "lucide-react";
import Button from "@/components/atoms/Button";
import ProfileAvatar from "./ProfileAvatar";
import { adminNavigation, sidebarItems } from "@/admin/components/sidebar";
import { useAdminAuth } from "../services/useAdminAuth";

export default function AdminShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, checking, logout } = useAdminAuth();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const isLogin = pathname === adminNavigation.routes.login;

  useEffect(() => { setOpen(false); }, [pathname]);
  useEffect(() => {
    if (!isLogin && !checking && !user) { router.replace(adminNavigation.routes.login); }
  }, [isLogin, checking, user, router]);

  async function signOut() {
    setBusy(true); setError("");
    try { await logout(); router.replace(adminNavigation.routes.login); }
    catch (failure) { setError(failure instanceof Error ? failure.message : adminNavigation.labels.signOutError); }
    finally { setBusy(false); }
  }

  if (isLogin) { return children; }
  if (checking || !user) {
    return <main id="main-content" lang="en" className="flex min-h-screen flex-col items-center justify-center gap-4 p-6">
      <p role="status">{checking ? adminNavigation.labels.checkingSession : adminNavigation.labels.openingLogin}</p>
    </main>;
  }

  const current = sidebarItems.find((item) => item.href === pathname);
  return <div lang="en" className="min-h-screen bg-ivory text-cocoa lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
    <a href="#main-content" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:bg-ivory focus:p-3">{adminNavigation.labels.skipToContent}</a>
    <aside className="border-b border-cocoa/10 bg-rose/20 lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:border-r lg:border-b-0">
      <div className="flex min-h-20 items-center justify-between gap-4 border-b border-cocoa/10 px-6">
        <Link href={adminNavigation.routes.dashboard} aria-label={adminNavigation.brand.label}><span className="font-heading text-xl font-semibold text-cocoa">{adminNavigation.brand.name}</span><span className="mt-1 block text-xs tracking-widest text-brown uppercase">{adminNavigation.brand.subtitle}</span></Link>
        <button type="button" className="flex size-11 items-center justify-center rounded-card focus-visible:outline-2 focus-visible:outline-olive lg:hidden" aria-label={open ? adminNavigation.labels.closeMenu : adminNavigation.labels.openMenu} aria-expanded={open} aria-controls="admin-sidebar-navigation" onClick={() => setOpen((value) => !value)}>{open ? <X size={22} /> : <Menu size={22} />}</button>
      </div>
      <nav id="admin-sidebar-navigation" aria-label={adminNavigation.labels.navigation} className={`${open ? "flex" : "hidden"} flex-col gap-2 p-4 lg:flex lg:flex-1 lg:overflow-y-auto`}>
        {sidebarItems.filter((item) => !item.action && item.group !== "account").map((item) => {
          if (!item.href) { return null; }
          const Icon = item.icon;
          const active = item.href === pathname;
          const classes = `flex min-h-12 items-center gap-3 rounded-card px-4 py-3 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive motion-reduce:transition-none ${active ? "bg-olive text-ivory" : "text-cocoa hover:bg-cocoa/5"}`;
          return <Link key={`${item.href}:${item.label}`} href={item.href} aria-current={active ? "page" : undefined} onClick={() => setOpen(false)} className={classes}><Icon size={19} aria-hidden="true" />{item.label}</Link>;
        })}
        <div className="mt-6 border-t border-cocoa/10 pt-4 lg:mt-auto">
          <div className="flex flex-col gap-2">
            {sidebarItems.filter((item) => item.group === "account").map((item) => {
              if (!item.href) { return null; }
              const Icon = item.icon;
              const active = item.href === pathname;
              return <Link key={`${item.href}:${item.label}`} href={item.href} aria-current={active ? "page" : undefined} onClick={() => setOpen(false)} className={`flex min-h-12 items-center gap-3 rounded-card px-4 py-3 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive motion-reduce:transition-none ${active ? "bg-olive text-ivory" : "text-cocoa hover:bg-cocoa/5"}`}><Icon size={19} aria-hidden="true" />{item.label}</Link>;
            })}
          </div>
          {sidebarItems.filter((item) => item.action === "logout").map((item) => {
            const Icon = item.icon;
            return <Button key={item.action} variant="ghost" fullWidth className="justify-start" onClick={signOut} loading={busy} loadingLabel={adminNavigation.labels.signingOut} startIcon={<Icon size={19} />}>{item.label}</Button>;
          })}
          {error && <p role="alert" className="px-4 text-sm">{error}</p>}
        </div>
      </nav>
    </aside>
    <div className="min-w-0">
      <header className="flex min-h-20 flex-wrap items-center justify-between gap-3 border-b border-cocoa/10 px-6 py-4 lg:px-10">
        <p className="text-sm font-medium">{current?.label || adminNavigation.labels.defaultTitle}</p>
        <div className="ml-auto flex min-w-0 max-w-full items-center gap-3">
          <div className="min-w-0 text-right"><p className="truncate text-sm font-medium">{user.name}</p><p className="mt-1 truncate text-xs text-brown">{user.email}</p></div>
          <ProfileAvatar user={user} />
        </div>
      </header>
      <main id="main-content" tabIndex={-1} className="p-6 lg:p-10">{children}</main>
    </div>
  </div>;
}
