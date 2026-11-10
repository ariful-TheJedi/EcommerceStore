"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { ArrowLeft, Plus, Trash2 } from "lucide-react";
import Button from "@/components/atoms/Button";
import productPageData from "@/data/products.json";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { AdminApiError } from "@/admin/services/request";
import { imageBody, productBody, variantBody, type Product, type ProductImage, type ProductVariant, type TaxonomyOptions } from "@/admin/services/products";
import ProductImageUploader from "./ProductImageUploader";
import { Field, productInput, productPanel, VariantFields, VariantSelect } from "./ProductFields";

export default function ProductEditor({ initial, taxonomy, taxonomyError, editable, onClose, onChanged }: {
  initial: Product | "new"; taxonomy: TaxonomyOptions | null; taxonomyError: string; editable: boolean; onClose: () => void; onChanged: () => void;
}) {
  const { request } = useAdminAuth();
  const [product, setProduct] = useState(initial);
  const [tab, setTab] = useState<"details" | "variants" | "images">("details");
  const [variant, setVariant] = useState<ProductVariant | "new" | null>(null);
  const [options, setOptions] = useState<{ name: string; value: string }[]>([]);
  const [image, setImage] = useState<ProductImage | "new" | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState("");
  const [dirty, setDirty] = useState(false);
  const [confirm, setConfirm] = useState<{ label: string; path: string; method: string } | null>(null);
  const lock = useRef(false);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, []);

  async function mutate(path: string, method: string, body?: object | FormData): Promise<boolean> {
    if (lock.current || !editable) { return false; }
    lock.current = true; setBusy(true); setError(""); setErrors({}); setNotice("");
    try {
      const data = await request<{ product?: Product }>(path, { method, body });
      if (data?.product) { setProduct(data.product); }
      else if (product !== "new") {
        try { setProduct((await request<{ product: Product }>(`/products/${product.id}`)).product); }
        catch { setError("The change was saved, but refreshed details could not be loaded. Reopen this product to refresh."); }
      }
      setNotice("Changes saved."); setDirty(false); onChanged(); return true;
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save changes.");
      if (failure instanceof AdminApiError) { setErrors(failure.errors); }
      return false;
    } finally { lock.current = false; setBusy(false); }
  }

  async function saveDetails(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      const body = productBody(new FormData(event.currentTarget), taxonomy !== null);
      await mutate(product === "new" ? "/products" : `/products/${product.id}`, product === "new" ? "POST" : "PATCH", body);
    } catch (failure) {
      const message = failure instanceof Error ? failure.message : "Check the product specifications.";
      setError(message); setErrors({ specifications: [message] });
    }
  }
  async function saveVariant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (product === "new" || !variant) { return; }
    try {
      const body = variantBody(new FormData(event.currentTarget), options);
      if (await mutate(`/products/${product.id}/variants${variant === "new" ? "" : "/" + variant.id}`, variant === "new" ? "POST" : "PATCH", body)) { setVariant(null); }
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Check the variant options."); }
  }
  async function saveImage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (product === "new" || !image || image === "new") { return; }
    const form = new FormData(event.currentTarget);
    if (await mutate(`/products/${product.id}/images/${image.id}`, "PATCH", imageBody(form))) { setImage(null); }
  }
  const selected = (kind: "categories" | "tags") => product === "new" ? [] : product[kind].map((item) => item.id);
  function canLeave(): boolean {
    return !dirty || window.confirm("Discard your unsaved changes?");
  }
  function editVariant(item: ProductVariant | "new") {
    if (!canLeave()) { return; }
    setDirty(false);
    setVariant(item); setErrors({}); setError(""); setOptions(item === "new" ? [] : Object.entries(item.attributes).map(([name, value]) => ({ name, value })));
  }

  return <div className="flex flex-col gap-5" style={{ fontFamily: "Arial, sans-serif" }}>
    <div className="flex flex-wrap items-center justify-between gap-3"><Button variant="ghost" disabled={busy} startIcon={<ArrowLeft size={16} />} onClick={() => { if (canLeave()) { onClose(); } }}>Back to products</Button>
      {product !== "new" && editable && <Button variant="outline" disabled={busy} onClick={() => product.status === "archived" ? void mutate(`/products/${product.id}`, "PATCH", { status: "draft" }) : setConfirm({ label: "Archive this product? It will be removed from the storefront while preserving its order history.", path: `/products/${product.id}/archive`, method: "POST" })}>{product.status === "archived" ? "Restore as draft" : "Archive product"}</Button>}</div>
    <div><p className="mb-2 text-[10px] font-semibold tracking-widest text-brown">CATALOG / PRODUCTS</p><h1 ref={heading} tabIndex={-1} className="font-[inherit] text-3xl font-semibold tracking-tight">{product === "new" ? "Add product" : product.name}</h1><p className="mt-2 text-sm text-brown">{product === "new" ? "Start with the details, then add pricing, variants, and images." : `Product #${product.id} · ${product.status}`}</p></div>
    {error && <div role="alert" className="rounded-xl border border-[#9b3528]/20 bg-[#fff0ec] p-4 text-sm text-[#9b3528]"><p>{error}</p>{Object.entries(errors).filter(([key]) => key.includes(".") || key === "identifier").map(([key, messages]) => <p key={key} className="mt-1">{key}: {messages.join(" ")}</p>)}</div>}
    {notice && <p role="status" className="rounded-xl bg-olive/10 p-4 text-sm text-olive">{notice}</p>}
    {confirm && <div role="alert" className={productPanel}><p className="mb-4 text-sm">{confirm.label}</p><div className="flex gap-2"><Button loading={busy} onClick={async () => { if (await mutate(confirm.path, confirm.method)) { setConfirm(null); } }}>Confirm</Button><Button variant="outline" disabled={busy} onClick={() => setConfirm(null)}>Cancel</Button></div></div>}
    <div role="group" aria-label="Product sections" className="flex flex-wrap gap-2 border-b border-cocoa/10 pb-4">{(["details", "variants", "images"] as const).map((key) => <Button key={key} variant={tab === key ? "primary" : "ghost"} aria-pressed={tab === key} disabled={busy || (product === "new" && key !== "details")} onClick={() => { if (tab === key || !canLeave()) { return; } setDirty(false); setTab(key); setVariant(null); setImage(null); setErrors({}); setError(""); setNotice(""); }}>{key === "details" ? "Product details" : key === "variants" ? "Variants & pricing" : "Images"}</Button>)}</div>
    {tab === "details" && <form onChange={() => setDirty(true)} onSubmit={saveDetails} className={productPanel}>
      <fieldset disabled={busy || !editable} className="grid min-w-0 gap-5 sm:grid-cols-2" key={product === "new" ? "new" : product.id + ":" + product.status}>
        <Field label="Product name" name="name" error={errors.name}><input id="name" name="name" defaultValue={product === "new" ? "" : product.name} required maxLength={200} className={productInput} aria-invalid={Boolean(errors.name)} /></Field>
        <Field label="URL slug" name="slug" error={errors.slug}><input id="slug" name="slug" defaultValue={product === "new" ? "" : product.slug} required maxLength={220} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="e.g. ceramic-coffee-cup" className={productInput} aria-invalid={Boolean(errors.slug)} /></Field>
        <div className="sm:col-span-2"><Field label="Description" name="description" error={errors.description}><textarea id="description" name="description" rows={5} maxLength={100000} defaultValue={product === "new" ? "" : product.description ?? ""} className={productInput} /></Field></div>
        <div className="sm:col-span-2"><Field label="Product specifications" name="specifications" error={errors.specifications}><textarea id="specifications" name="specifications" rows={8} spellCheck={false} defaultValue={product === "new" || !product.specifications ? "" : JSON.stringify(product.specifications, null, 2)} placeholder={'{\n  "Material": "Cotton",\n  "Dimensions": { "Width": "20 cm", "Height": "30 cm" },\n  "Features": ["Washable", "Reusable"]\n}'} className={`${productInput} font-mono text-sm`} aria-invalid={Boolean(errors.specifications)} aria-describedby="specifications-help" /></Field><p id="specifications-help" className="mt-2 text-xs leading-6 text-brown">Add named specifications as JSON. Values can include text, numbers, lists, or grouped details. These appear in Additional Information on the product page. Leave blank to remove specifications.</p></div>
        <Field label="Status" name="status" error={errors.status}><select id="status" name="status" defaultValue={product === "new" ? "draft" : product.status} className={productInput}><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></Field>
        <p className="self-center text-xs leading-relaxed text-brown">Keep the product as a draft until its variants, prices, and images are ready.</p>
        <div className="rounded-xl border border-cocoa/10 p-4 sm:col-span-2">
          <p className="mb-3 text-xs font-semibold text-brown">Product card pills</p>
          <label className="flex cursor-pointer items-center gap-3 text-sm"><input type="checkbox" name="is_new" defaultChecked={product !== "new" && product.is_new} className="size-4 accent-olive" aria-describedby="is-new-help" /><span>Show</span><span lang="bn" className="rounded-full border border-olive/15 bg-ivory px-3 py-1 font-semibold text-olive">{productPageData.card.spanText2}</span></label>
          <p id="is-new-help" className="mt-3 text-xs leading-5 text-brown">Tick to show this pill on product cards. Untick to remove it, then save the product. The Save % pill follows your sale price.</p>
          {errors.is_new && <p className="mt-2 text-xs text-[#9b3528]">{errors.is_new.join(" ")}</p>}
        </div>
        {(["categories", "tags"] as const).map((kind) => <div key={kind}><p className="mb-3 text-xs font-semibold text-brown">{kind === "categories" ? "Categories" : "Tags"}</p><div className="flex max-h-48 flex-col gap-3 overflow-y-auto rounded-xl border border-cocoa/10 p-4">{taxonomy ? taxonomy[kind].length ? taxonomy[kind].map((item) => <label key={item.id} className="flex items-center gap-2 text-sm"><input type="checkbox" name={kind === "categories" ? "category_ids" : "tag_ids"} value={item.id} defaultChecked={selected(kind).includes(item.id)} className="size-4 accent-olive" />{item.name}{kind === "categories" && item.is_active === false ? " (inactive)" : ""}</label>) : <p className="text-xs text-brown">No {kind} available.</p> : <p className="text-xs text-brown">{taxonomyError || "Loading options..."}</p>}</div>{errors[kind === "categories" ? "category_ids" : "tag_ids"] && <p className="mt-2 text-xs text-[#9b3528]">{errors[kind === "categories" ? "category_ids" : "tag_ids"].join(" ")}</p>}</div>)}
      </fieldset>
      {editable && <div className="mt-6 flex justify-end"><Button type="submit" loading={busy} loadingLabel="Saving...">Save product</Button></div>}
    </form>}
    {tab === "variants" && product !== "new" && <>
      <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-brown">Prices in {product.currency}. Stock adjustments are managed in Inventory.</p>{editable && <Button disabled={busy} startIcon={<Plus size={16} />} onClick={() => editVariant("new")}>Add variant</Button>}</div>
      {variant && <form onChange={() => setDirty(true)} onSubmit={saveVariant} className={productPanel} key={variant === "new" ? "new" : variant.id}><h2 className="mb-5 font-[inherit] text-lg font-semibold">{variant === "new" ? "Add variant" : "Edit " + variant.sku}</h2><fieldset disabled={busy} className="flex flex-col gap-5">
        <VariantFields variant={variant} errors={errors} />
        <div><p className="mb-3 text-xs font-semibold text-brown">Options (leave empty for a simple product)</p><div className="flex flex-col gap-2">{options.map((option, index) => <div key={index} className="flex items-center gap-2"><input aria-label={`Option ${index + 1} name`} placeholder="e.g. Color" value={option.name} maxLength={100} onChange={(event) => setOptions((items) => items.map((item, i) => i === index ? { ...item, name: event.target.value } : item))} className={productInput} /><input aria-label={`Option ${index + 1} value`} placeholder="e.g. Green" value={option.value} maxLength={100} onChange={(event) => setOptions((items) => items.map((item, i) => i === index ? { ...item, value: event.target.value } : item))} className={productInput} /><Button size="sm" variant="ghost" aria-label="Remove option" onClick={() => setOptions((items) => items.filter((_, i) => i !== index))}><Trash2 size={16} /></Button></div>)}</div><Button variant="ghost" size="sm" disabled={options.length >= 20} onClick={() => setOptions((items) => [...items, { name: "", value: "" }])}>Add option</Button></div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" defaultChecked={variant === "new" || variant.is_active} className="size-4 accent-olive" />Active variant</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_backorders" defaultChecked={variant !== "new" && variant.allow_backorders} className="size-4 accent-olive" />Allow purchases when out of stock</label>
      </fieldset><div className="mt-5 flex gap-2"><Button type="submit" loading={busy}>Save variant</Button><Button variant="outline" disabled={busy} onClick={() => { if (canLeave()) { setVariant(null); setDirty(false); } }}>Cancel</Button></div></form>}
      <div className="grid gap-4 lg:grid-cols-2">{product.variants.map((item) => <article key={item.id} className={productPanel}><div className="flex items-center justify-between gap-3"><h2 className="break-all font-[inherit] text-base font-semibold">{item.sku}</h2><span className="text-xs text-brown">{item.is_active ? "Active" : "Disabled"}</span></div><p className="mt-2 text-xs text-brown">{Object.entries(item.attributes).map(([key, value]) => key + ": " + value).join(" / ") || "Default variant"}</p><p className="my-4 text-xl font-semibold">{new Intl.NumberFormat("en", { style: "currency", currency: product.currency }).format(Number(item.effective_price))}</p><dl className="grid grid-cols-3 gap-2 border-t border-cocoa/10 pt-4 text-xs">{[["On hand", item.stock_quantity], ["Reserved", item.reserved_quantity], ["Available", item.available_stock]].map(([key, value]) => <div key={key}><dt className="text-brown">{key}</dt><dd className="mt-1 text-base font-semibold">{value}</dd></div>)}</dl>{editable && <div className="mt-5 flex flex-wrap gap-2"><Button size="sm" variant="outline" disabled={busy} onClick={() => editVariant(item)}>Edit</Button>{item.is_active && <Button size="sm" variant="ghost" disabled={busy} onClick={() => setConfirm({ label: "Disable " + item.sku + "? It will no longer be purchasable.", path: `/products/${product.id}/variants/${item.id}/disable`, method: "POST" })}>Disable</Button>}</div>}</article>)}</div>
      {!product.variants.length && <p className={productPanel}>No variants yet. Add a variant to set the product price.</p>}
    </>}
    {tab === "images" && product !== "new" && <>
      <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-brown">JPEG, PNG, or WebP. The server validates upload size and image dimensions.</p>{editable && <Button disabled={busy} startIcon={<Plus size={16} />} onClick={() => { if (canLeave()) { setDirty(false); setImage("new"); setErrors({}); } }}>Upload images</Button>}</div>
      {image === "new" && <ProductImageUploader product={product} onBusyChange={(value) => { lock.current = value; setBusy(value); }} onDirtyChange={setDirty} onCancel={() => { if (canLeave()) { setImage(null); setDirty(false); } }} onUploaded={(images) => {
        setProduct((current) => current === "new" ? current : { ...current, images: [...current.images.map((item) => images.some((uploaded) => uploaded.is_primary) ? { ...item, is_primary: false } : item), ...images].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id) });
        onChanged();
      }} />}
      {image && image !== "new" && <form onChange={() => setDirty(true)} onSubmit={saveImage} className={productPanel} key={image.id}><fieldset disabled={busy} className="grid gap-4 sm:grid-cols-2"><Field label="Alternative text" name="alt_text" error={errors.alt_text}><input id="alt_text" name="alt_text" maxLength={255} defaultValue={image.alt_text ?? ""} className={productInput} /></Field><VariantSelect product={product} value={image.variant_id} /><Field label="Display order" name="sort_order" error={errors.sort_order}><input id="sort_order" name="sort_order" type="number" min={0} max={1000000} step={1} defaultValue={image.sort_order} className={productInput} /></Field><label className="flex items-center gap-2 text-sm"><input name="is_primary" type="checkbox" defaultChecked={image.is_primary} className="size-4 accent-olive" />Primary image</label></fieldset><div className="mt-5 flex gap-2"><Button type="submit" loading={busy}>Save image</Button><Button variant="outline" disabled={busy} onClick={() => { if (canLeave()) { setImage(null); setDirty(false); } }}>Cancel</Button></div></form>}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{[...product.images].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id).map((item) => <article key={item.id} className={productPanel}><img src={item.url} alt={item.alt_text ?? product.name} width={300} height={220} className="h-44 w-full rounded-xl bg-ivory object-contain" /><p className="mt-3 text-sm font-medium">{item.is_primary ? "Primary image" : "Product image"}</p><p className="mt-1 text-xs text-brown">{item.variant_id ? product.variants.find((v) => v.id === item.variant_id)?.sku : "All variants"} · Order {item.sort_order}</p>{editable && <div className="mt-4 flex flex-wrap gap-2"><Button size="sm" variant="outline" disabled={busy} onClick={() => { if (canLeave()) { setDirty(false); setImage(item); setErrors({}); } }}>Edit details</Button><Button size="sm" variant="ghost" disabled={busy} onClick={() => setConfirm({ label: "Permanently remove this image?", path: `/products/${product.id}/images/${item.id}`, method: "DELETE" })}>Remove</Button></div>}</article>)}</div>
      {!product.images.length && <p className={productPanel}>No images yet. Upload a product image to get started.</p>}
    </>}
  </div>;
}


