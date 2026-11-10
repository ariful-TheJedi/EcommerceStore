"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Truck, X } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { AdminApiError } from "@/admin/services/request";
import type { DeliveryCharges } from "@/services/checkout";
import { Field, productInput } from "./ProductFields";

function DeliveryChargeModal({ onClose }: { onClose: () => void }) {
  const { request } = useAdminAuth();
  const dialog = useRef<HTMLDialogElement>(null);
  const locked = useRef(false);
  const [charges, setCharges] = useState<DeliveryCharges | null>(null);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  useEffect(() => {
    const element = dialog.current;
    element?.showModal();
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { element?.close(); document.body.style.overflow = previous; };
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    setCharges(null); setError("");
    request<DeliveryCharges>("/orders/delivery-charges", { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) })
      .then((data) => { if (!controller.signal.aborted) { setCharges(data); } })
      .catch((failure) => { if (!controller.signal.aborted) { setError(failure instanceof Error ? failure.message : "Unable to load delivery charges."); } });
    return () => controller.abort();
  }, [request, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!charges || locked.current) { return; }
    const form = new FormData(event.currentTarget);
    locked.current = true; setBusy(true); setError(""); setNotice("");
    try {
      const data = await request<DeliveryCharges>("/orders/delivery-charges", { method: "PATCH", body: { inside_dhaka: String(form.get("inside_dhaka")), outside_dhaka: String(form.get("outside_dhaka")), version: charges.version } });
      setCharges(data); setNotice("Delivery charges saved. Checkout will use these rates.");
    } catch (failure) { setError(failure instanceof AdminApiError && failure.status === 409 ? "Charges changed since this window opened. Reload the current rates before saving." : failure instanceof Error ? failure.message : "Unable to save delivery charges."); }
    finally { locked.current = false; setBusy(false); }
  }
  return <dialog ref={dialog} aria-labelledby="delivery-charges-title" className="taxonomy-dialog taxonomy-action-dialog" onClose={(event) => { if (event.target === event.currentTarget && !event.currentTarget.open) { onClose(); } }} onCancel={(event) => { if (busy) { event.preventDefault(); } }}>
    <header className="mb-5 flex items-center justify-between gap-3"><div><h2 id="delivery-charges-title" className="text-xl font-semibold">Delivery charges</h2><p className="mt-2 text-sm text-brown">Set the shipping charge customers pay at checkout.</p></div><Button size="sm" variant="ghost" aria-label="Close delivery charges" disabled={busy} onClick={() => dialog.current?.close()}><X size={18} aria-hidden="true" /></Button></header>
    {error && <div role="alert" className="mb-4 rounded-xl bg-[#9b3528]/5 p-3 text-sm text-[#9b3528]">{error}<Button size="sm" variant="ghost" disabled={busy} onClick={() => setRevision((value) => value + 1)}>Reload rates</Button></div>}
    {notice && <p role="status" className="mb-4 rounded-xl bg-olive/8 p-3 text-sm text-olive">{notice}</p>}
    {charges ? <form key={charges.version} onSubmit={(event) => void save(event)} className="flex flex-col gap-5">{[
      { name: "inside_dhaka" as const, label: "Inside Dhaka" }, { name: "outside_dhaka" as const, label: "Outside Dhaka" },
    ].map((field) => <Field key={field.name} label={`${field.label} (${charges.currency})`} name={`charge-${field.name}`}><input id={`charge-${field.name}`} name={field.name} type="number" min="0" max="9999999999.99" step="0.01" required disabled={busy} defaultValue={charges[field.name]} className={productInput} /></Field>)}<p className="text-xs leading-6 text-brown">New checkout reviews use these rates immediately. Existing orders keep their original delivery charges.</p><Button type="submit" loading={busy}>Save delivery charges</Button></form> : !error && <p role="status" className="py-8 text-center text-sm text-brown">Loading charges...</p>}
  </dialog>;
}

export default function DeliveryChargeButton() {
  const { user } = useAdminAuth();
  const [open, setOpen] = useState(false);
  if (user?.role !== "admin") { return null; }
  return <><Button variant="outline" size="sm" startIcon={<Truck size={16} />} onClick={() => setOpen(true)}>Delivery charges</Button>{open && <DeliveryChargeModal onClose={() => setOpen(false)} />}</>;
}