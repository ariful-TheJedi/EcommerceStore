"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { X } from "lucide-react";
import Button from "@/components/atoms/Button";

export default function OrderDrawer({ children, busy, onClose }: { children: ReactNode; busy: boolean; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const element = dialog.current;
    if (!element) { return; }
    element.showModal();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { element.close(); document.body.style.overflow = previousOverflow; };
  }, []);

  return <dialog
    ref={dialog}
    aria-labelledby={titleId}
    onClose={(event) => { if (event.target === event.currentTarget && !event.currentTarget.open) { onClose(); } }}
    onCancel={(event) => { if (busy) { event.preventDefault(); } }}
    onClick={(event) => {
      if (busy || event.target !== event.currentTarget) { return; }
      const bounds = event.currentTarget.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) { dialog.current?.close(); }
    }}
    className="fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-dvh w-full max-w-2xl overflow-y-auto overscroll-contain border-0 border-l border-cocoa/10 bg-[#fffdfb] p-0 text-cocoa shadow-2xl backdrop:bg-cocoa/35 backdrop:backdrop-blur-sm"
    style={{ fontFamily: "Arial, sans-serif" }}
  >
    <header className="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-cocoa/10 bg-[#fffdfb] px-5 py-3 sm:px-7">
      <h2 id={titleId} className="text-sm font-semibold">Order details</h2>
      <Button variant="ghost" size="sm" disabled={busy} aria-label="Close order details" onClick={() => dialog.current?.close()}><X size={19} aria-hidden="true" /></Button>
    </header>
    <div className="p-5 sm:p-7">{children}</div>
  </dialog>;
}
