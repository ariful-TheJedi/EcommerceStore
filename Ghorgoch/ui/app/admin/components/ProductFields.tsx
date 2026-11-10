"use client";

import { useState, type ReactNode } from "react";
import productPageData from "@/data/products.json";
import type { Product, ProductVariant } from "@/admin/services/products";
import { localDate } from "@/admin/services/products";

export const productInput = "min-h-11 w-full rounded-xl border border-cocoa/15 bg-[#fffdfb] px-3 py-2.5 text-sm text-cocoa focus-visible:outline-2 focus-visible:outline-olive disabled:opacity-60";
export const productPanel = "rounded-2xl border border-cocoa/10 bg-[#fffdfb] p-5 sm:p-6";

export function Field({ label, name, children, error }: { label: string; name: string; children: ReactNode; error?: string[] }) {
  return <div className="flex min-w-0 flex-col gap-2"><label htmlFor={name} className="text-xs font-semibold text-brown">{label}</label>{children}{error && <p id={`${name}-error`} className="text-xs text-[#9b3528]">{error.join(" ")}</p>}</div>;
}

export function VariantSelect({ product, value }: { product: Product; value?: number | null }) {
  return <Field label="Assign to variant" name="variant_id"><select id="variant_id" name="variant_id" defaultValue={value ?? ""} className={productInput}><option value="">All variants (product image)</option>{product.variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.sku}</option>)}</select></Field>;
}

const variantFields = [
  { name: "sku", label: "SKU", type: "text", required: true, maxLength: 100, pattern: "[a-zA-Z0-9._-]+" },
  { name: "price", label: "Regular price", type: "number", required: true, min: 0, max: 9999999999.99, step: "0.01" },
  { name: "sale_price", label: "Sale price", type: "number", min: 0, max: 9999999999.99, step: "0.01" },
  { name: "cost_price", label: "Cost price (internal)", type: "number", min: 0, max: 9999999999.99, step: "0.01" },
  { name: "sale_starts_at", label: "Sale starts (your local time)", type: "datetime-local", step: "1" },
  { name: "sale_ends_at", label: "Sale ends (your local time)", type: "datetime-local", step: "1" },
  { name: "barcode", label: "Barcode", type: "text", maxLength: 100 },
  { name: "weight_grams", label: "Weight (grams)", type: "number", min: 0, max: 100000000, step: "1" },
  { name: "low_stock_threshold", label: "Low-stock threshold", type: "number", min: 0, max: 100000000, step: "1" },
] as const;

export function VariantFields({ variant, errors }: { variant: ProductVariant | "new"; errors: Record<string, string[]> }) {
  const [regularPrice, setRegularPrice] = useState(variant === "new" ? "" : variant.price);
  const [salePrice, setSalePrice] = useState(variant === "new" ? "" : variant.sale_price || "");
  const saving = regularPrice && salePrice && Number(salePrice) >= 0 && Number(salePrice) < Number(regularPrice) ? Math.round((1 - Number(salePrice) / Number(regularPrice)) * 100) : null;
  return <div className="grid gap-4 sm:grid-cols-2">{variantFields.map(({ label, name, ...props }) => {
    const raw = variant === "new" ? name === "low_stock_threshold" ? 0 : "" : variant[name];
    const value = name === "sale_starts_at" || name === "sale_ends_at" ? localDate(raw as string | null) : raw ?? "";
    return <Field key={name} label={label} name={name} error={errors[name]}><input {...props} id={name} name={name} onChange={(event) => { if (name === "price") { setRegularPrice(event.target.value); } if (name === "sale_price") { setSalePrice(event.target.value); } }} defaultValue={String(value)} aria-invalid={Boolean(errors[name])} aria-describedby={errors[name] ? `${name}-error` : undefined} className={productInput} /></Field>;
  })}<div className="flex flex-wrap items-center gap-3 rounded-xl bg-ivory p-3 sm:col-span-2"><span className="text-xs text-brown">Product card badge:</span>{saving !== null ? <span className="inline-flex items-center rounded-full border border-[#e8cda2] bg-[#fff4df] px-2.5 py-0.5 text-xs font-semibold leading-5 text-[#71451d] shadow-sm">{productPageData.card.savingsPill.replace("{percent}", new Intl.NumberFormat("bn-BD").format(saving))}</span> : <span className="text-xs text-brown">Set a sale price below the regular price to show a saving.</span>}<p className="w-full text-xs leading-5 text-brown">The saving appears while the sale is active. Manage the নতুন badge using the checkbox in product details.</p></div></div>;
}
