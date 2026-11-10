"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Plus, Search, Trash2 } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { orderMoney, type OrderDetail } from "@/admin/services/orders";
import { Field, productInput, productPanel } from "./ProductFields";

type Variant = { id: number; sku: string; effective_price: string; available: boolean; currency: string };
type Product = { id: number; name: string; variants: Variant[] };
type Line = Variant & { name: string; quantity: number };

export default function ManualOrderForm({ onCreated }: { onCreated: (order: OrderDetail) => void }) {
  const { request } = useAdminAuth();
  const [search, setSearch] = useState("");
  const [products, setProducts] = useState<Product[]>([]);
  const [lines, setLines] = useState<Line[]>([]);
  const [searching, setSearching] = useState(false);
  const [lookupError, setLookupError] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [shipping, setShipping] = useState("0.00");
  const submissionKey = useRef("");
  const [lookup, setLookup] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    setSearching(true); setLookupError("");
    request<{ products: Product[] }>(`/catalog/products?per_page=10${lookup ? "&search=" + encodeURIComponent(lookup) : ""}`, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) })
      .then((data) => { if (!controller.signal.aborted) { setProducts(data.products); } })
      .catch((failure) => { if (!controller.signal.aborted) { setLookupError(failure instanceof Error ? failure.message : "Unable to load products."); } })
      .finally(() => { if (!controller.signal.aborted) { setSearching(false); } });
    return () => controller.abort();
  }, [request, lookup]);

  function add(product: Product, variant: Variant) {
    setLines((current) => current.some((line) => line.id === variant.id) ? current.map((line) => line.id === variant.id ? { ...line, quantity: Math.min(100, line.quantity + 1) } : line) : [...current, { ...variant, name: product.name, quantity: 1 }]);
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!lines.length) { setError("Add at least one product to this order."); return; }
    const form = new FormData(event.currentTarget);
    const value = (key: string) => String(form.get(key) || "").trim();
    if (!submissionKey.current) { submissionKey.current = crypto.randomUUID(); }
    setBusy(true); setError("");
    try {
      const data = await request<{ order: OrderDetail }>("/orders", { method: "POST", body: {
        idempotency_key: submissionKey.current,
        contact: { name: value("name"), phone: value("phone"), ...(value("email") ? { email: value("email") } : {}) },
        delivery: { recipient_name: value("name"), phone: value("phone"), address_line1: value("address"), city: value("city"), district: value("district"), division: value("division"), country_code: "BD" },
        shipping_fee: shipping,
        customer_note: value("note") || null,
        items: lines.map((line) => ({ variant_id: line.id, quantity: line.quantity })),
      } });
      onCreated(data.order);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to create order.");
    } finally { setBusy(false); }
  }
  const currency = lines[0]?.currency || "BDT";
  const subtotal = lines.reduce((total, line) => total + Math.round(Number(line.effective_price) * 100) * line.quantity, 0);

  return <div className="grid items-start gap-5 xl:grid-cols-[1fr_1.1fr]">
    <section className={productPanel}><h2 className="font-semibold">1. Add products</h2><p className="mt-2 text-xs text-brown">Search the catalog and select a product variant.</p><form className="mt-5 flex gap-2" onSubmit={(event) => { event.preventDefault(); setLookup(search.trim()); }}><label htmlFor="manual-product-search" className="sr-only">Search products</label><input id="manual-product-search" className={productInput} value={search} maxLength={150} onChange={(event) => setSearch(event.target.value)} placeholder="Product name or SKU" /><Button type="submit" variant="outline" aria-label="Find products" disabled={busy || searching}><Search size={17} aria-hidden="true" /></Button></form>
      <div className="mt-4 flex max-h-[32rem] flex-col gap-3 overflow-y-auto">{lookupError ? <p role="alert" className="text-sm text-[#9b3528]">{lookupError}</p> : searching ? <p role="status" className="p-5 text-sm text-brown">Loading products...</p> : products.length ? products.map((product) => <div key={product.id} className="rounded-xl border border-cocoa/10 p-3"><h3 className="text-sm font-semibold">{product.name}</h3><div className="mt-2 flex flex-col gap-2">{product.variants.map((variant) => <div key={variant.id} className="flex items-center justify-between gap-3"><div><p className="break-all text-xs text-brown">{variant.sku}</p><p className="mt-1 text-xs font-medium">{orderMoney(variant.effective_price, variant.currency)}</p></div><Button variant="outline" size="sm" disabled={busy || !variant.available || lines.length >= 50} startIcon={<Plus size={14} />} onClick={() => add(product, variant)}>{variant.available ? "Add" : "Unavailable"}</Button></div>)}</div></div>) : <p className="p-5 text-sm text-brown">No products found. Try another search.</p>}</div>
    </section>
    <form onSubmit={(event) => void submit(event)} className={`${productPanel} flex flex-col gap-5`}><h2 className="font-semibold">2. Customer & order details</h2>
      {error && <p role="alert" className="rounded-xl bg-[#9b3528]/5 p-3 text-sm leading-6 text-[#9b3528]">{error}</p>}
      <fieldset disabled={busy} className="grid gap-4 sm:grid-cols-2"><legend className="sr-only">Customer delivery details</legend>{[
        { name: "name", label: "Customer name", required: true, maxLength: 150 },
        { name: "phone", label: "Phone (+8801...)", required: true, type: "tel", pattern: "\\+8801[3-9][0-9]{8}", maxLength: 14 },
        { name: "email", label: "Email (optional)", type: "email", maxLength: 255 },
        { name: "address", label: "Delivery address", required: true, maxLength: 255 },
        { name: "city", label: "City", required: true, maxLength: 100 },
        { name: "district", label: "District", required: true, maxLength: 100 },
        { name: "division", label: "Division", required: true, maxLength: 100 },
      ].map(({ label, name, ...props }) => <Field key={name} label={label} name={`manual-${name}`}><input id={`manual-${name}`} name={name} className={productInput} {...props} /></Field>)}</fieldset>
      <section className="border-t border-cocoa/10 pt-4"><h3 className="mb-3 text-sm font-semibold">Selected products</h3>{lines.length ? <div className="flex flex-col gap-3">{lines.map((line) => <div key={line.id} className="flex flex-wrap items-center gap-3 rounded-xl bg-ivory p-3"><div className="min-w-0 flex-1"><p className="text-sm font-medium">{line.name}</p><p className="mt-1 break-all text-xs text-brown">{line.sku}</p></div><label className="flex items-center gap-2 text-xs text-brown">Qty<input type="number" min={1} max={100} required disabled={busy} value={line.quantity} onChange={(event) => setLines((current) => current.map((item) => item.id === line.id ? { ...item, quantity: Number(event.target.value) } : item))} className={`${productInput} w-20`} /></label><Button variant="ghost" size="sm" disabled={busy} aria-label={`Remove ${line.name}`} onClick={() => setLines((current) => current.filter((item) => item.id !== line.id))}><Trash2 size={16} aria-hidden="true" /></Button></div>)}</div> : <p className="rounded-xl bg-ivory p-4 text-sm text-brown">Choose products from the catalog to get started.</p>}</section>
      <Field label={`Shipping charged (${currency})`} name="manual-shipping"><input id="manual-shipping" type="number" min="0" max="9999999999.99" step="0.01" required disabled={busy} value={shipping} onChange={(event) => setShipping(event.target.value)} className={productInput} /></Field>
      <Field label="Order note (optional)" name="manual-note"><textarea id="manual-note" name="note" maxLength={1000} rows={2} disabled={busy} className={productInput} /></Field>
      <div className="flex items-center justify-between gap-3 border-t border-cocoa/10 pt-4"><span className="text-sm text-brown">Estimated total</span><strong className="text-xl tabular-nums">{orderMoney(((subtotal + Math.round(Number(shipping || 0) * 100)) / 100).toFixed(2), currency)}</strong></div><p className="text-xs leading-5 text-brown">Cash on delivery. Current product prices and stock are checked when the order is created.</p><Button type="submit" loading={busy} disabled={!lines.length}>Create new order</Button>
    </form>
  </div>;
}
