"use client";

import content from "@/data/navbar.json";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { storefrontNavigation } from "@/config/navigation";
import { useCartStore } from "@/components/CartProvider";
import Logo from "@/components/atoms/Logo";

type IconName = "search" | "bag" | "menu" | "close" | "arrow";

function Icon({ name }: { name: IconName }) {
  return (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {name === "search" && <><circle cx="10.75" cy="10.75" r="6.25" /><path d="m15.5 15.5 4.25 4.25" /></>}
      {name === "bag" && <><path d="M5 8.5h14l-1 11H6l-1-11Z" /><path d="M9 9V6.5a3 3 0 0 1 6 0V9" /></>}
      {name === "menu" && <path d="M4 6.5h16M4 12h12M4 17.5h16" />}
      {name === "close" && <path d="m6 6 12 12M6 18 18 6" />}
      {name === "arrow" && <path d="M5 12h14m-5-5 5 5-5 5" />}
    </svg>
  );
}

// Shared storefront navigation with an inline product search.
export default function Navbar() {
  const pathname = usePathname();
  const router = useRouter();
  const { cartCount } = useCartStore();
  const [menuOpen, setMenuOpen] = useState(false);
  const [query, setQuery] = useState("");
  const menuButton = useRef<HTMLButtonElement>(null);
  const searchInput = useRef<HTMLInputElement>(null);
  const drawer = useRef<HTMLDialogElement>(null);
  const closeTimer = useRef<number | null>(null);

  useEffect(() => () => {
    if (closeTimer.current !== null) { window.clearTimeout(closeTimer.current); }
  }, []);

  // Close transient navigation when a Next.js route changes.
  useEffect(() => {
    closeDrawer();
  }, [pathname]);

  useEffect(() => {
    const mobile = window.matchMedia("(max-width: 63.999rem)");
    const closeOnDesktop = () => {
      if (!mobile.matches && drawer.current?.open) drawer.current.close();
    };
    mobile.addEventListener("change", closeOnDesktop);
    return () => mobile.removeEventListener("change", closeOnDesktop);
  }, []);

  useEffect(() => {
    if (!menuOpen) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.body.style.overflow = previousOverflow; };
  }, [menuOpen]);

  function openDrawer() {
    const mobileDrawer = drawer.current;
    if (!mobileDrawer || mobileDrawer.open) return;

    if (typeof mobileDrawer.showModal === "function") mobileDrawer.showModal();
    else mobileDrawer.setAttribute("open", "");
    setMenuOpen(true);
  }

  function closeDrawer() {
    const mobileDrawer = drawer.current;
    if (!mobileDrawer?.open || closeTimer.current !== null) return;

    const finish = () => {
      closeTimer.current = null;
      delete mobileDrawer.dataset.closing;
      if (typeof mobileDrawer.close === "function") mobileDrawer.close();
      else { mobileDrawer.removeAttribute("open"); setMenuOpen(false); }
    };
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { finish(); return; }
    mobileDrawer.style.setProperty("--drawer-exit-start", getComputedStyle(mobileDrawer).transform);
    mobileDrawer.style.setProperty("--drawer-backdrop-start", getComputedStyle(mobileDrawer, "::backdrop").opacity);
    mobileDrawer.dataset.closing = "true";
    closeTimer.current = window.setTimeout(finish, 240);
  }

  return (
    <header className="site-header" data-overlay={pathname === "/" || undefined}>
      {/* Hidden SVG filter used by the liquid-glass surface. */}
      <svg width="0" height="0" className="glass-filter" aria-hidden="true" focusable="false">
        <defs>
          <filter id="glass-distortion" x="0%" y="0%" width="100%" height="100%">
            <feTurbulence type="fractalNoise" baseFrequency="0.01 0.01" numOctaves="2" seed="92" result="noise" />
            <feGaussianBlur in="noise" stdDeviation="2" result="blurred" />
            <feDisplacementMap in="SourceGraphic" in2="blurred" scale="55" xChannelSelector="R" yChannelSelector="G" />
          </filter>
        </defs>
      </svg>
      <a className="skip-link" href="#main-content">{content.skipToContent}</a>
      <div className="navbar-shell">
        <div className="navbar liquid-glass-card">
          <Link href="/" className="navbar-brand" aria-label={content.ghorgochHome}><Logo /></Link>

          <nav className="navbar-links" aria-label={content.mainNavigation}>
            {storefrontNavigation.map((link) => {
              const active = pathname === link.href || pathname.startsWith(`${link.href}/`);
              return <Link key={link.href} href={link.href} className="navbar-link" aria-current={active ? "page" : undefined}>{link.label}</Link>;
            })}
          </nav>

          <form action={content.search} role="search" className="navbar-search-form" onSubmit={(event) => {
            event.preventDefault();
            const term = query.trim();
            if (!term) { searchInput.current?.focus(); return; }
            router.push(`${content.search}?q=${encodeURIComponent(term)}`);
          }}>
            <div className="navbar-search-field">
              <label className="sr-only" htmlFor="navbar-search">{content.searchProducts}</label>
              <input ref={searchInput} id="navbar-search" name="q" type="search" maxLength={150} value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => { if (event.key === "Escape") { setQuery(""); searchInput.current?.focus(); } }} placeholder={content.placeholder} autoComplete="off" required />
              {query && <button type="button" className="navbar-search-clear" aria-label={content.clear} onClick={() => { setQuery(""); searchInput.current?.focus(); }}><Icon name="close" /></button>}
              <button type="submit" className="navbar-search-submit" aria-label={content.submitSearch}><Icon name="search" /></button>
            </div>
          </form>

          <div className="navbar-actions">
            <Link href="/cart" className="navbar-icon" aria-label={content.accessibleLabel1.replace("{value1}", String(cartCount))}>
              <Icon name="bag" />
              {cartCount > 0 && <span className="navbar-badge" aria-hidden="true">{cartCount > 99 ? "99+" : cartCount}</span>}
            </Link>
            <button ref={menuButton} type="button" className="navbar-icon navbar-menu-toggle" aria-label={content.openNavigation} aria-expanded={menuOpen} aria-controls="mobile-navigation" aria-haspopup="dialog" onClick={openDrawer}>
              <Icon name="menu" />
            </button>
          </div>
        </div>


      </div>

      <dialog ref={drawer} id="mobile-navigation" className="navbar-drawer" aria-label={content.mobileNavigation} onClose={() => {
        if (closeTimer.current !== null) { window.clearTimeout(closeTimer.current); closeTimer.current = null; }
        if (drawer.current) {
          delete drawer.current.dataset.closing;
          drawer.current.style.removeProperty("--drawer-exit-start");
          drawer.current.style.removeProperty("--drawer-backdrop-start");
        }
        setMenuOpen(false);
        if (window.matchMedia("(max-width: 63.999rem)").matches) menuButton.current?.focus();
      }} onCancel={(event) => { event.preventDefault(); closeDrawer(); }} onClick={(event) => {
        if (event.target === event.currentTarget) {
          const bounds = event.currentTarget.getBoundingClientRect();
          if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDrawer();
        }
      }}>
        <div className="navbar-drawer-heading">
          <Logo />
          <button type="button" className="navbar-icon navbar-drawer-close" aria-label={content.closeNavigation} autoFocus onClick={closeDrawer}><Icon name="close" /></button>
        </div>
        <nav className="navbar-drawer-links" aria-label={content.mobileNavigation}>
          {storefrontNavigation.map((link) => <Link key={link.href} href={link.href} onClick={closeDrawer} className="navbar-link" aria-current={pathname === link.href || pathname.startsWith(`${link.href}/`) ? "page" : undefined}>{link.label}</Link>)}
        </nav>
        <div className="navbar-drawer-account">
          <Link href="/cart" onClick={closeDrawer} className="navbar-link"><Icon name="bag" /> {content.shoppingBag}{cartCount}{content.LinkText}</Link>
        </div>
      </dialog>
    </header>
  );
}
