"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { ArrowDown, ArrowUp, X } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { uploadProductImages, type Product, type ProductImage } from "@/admin/services/products";
import { Field, productInput, productPanel, VariantSelect } from "./ProductFields";

type QueuedImage = { key: string; file: File; altText: string };

function ImagePreview({ file }: { file: File }) {
  const [url, setUrl] = useState("");
  useEffect(() => {
    const preview = URL.createObjectURL(file); setUrl(preview);
    return () => URL.revokeObjectURL(preview);
  }, [file]);
  return url ? <img src={url} alt="" width={120} height={100} className="h-24 w-24 shrink-0 rounded-xl bg-ivory object-contain p-2" /> : null;
}

export default function ProductImageUploader({ product, onUploaded, onBusyChange, onDirtyChange, onCancel }: {
  product: Product; onUploaded: (images: ProductImage[]) => void; onBusyChange: (busy: boolean) => void;
  onDirtyChange: (dirty: boolean) => void; onCancel: () => void;
}) {
  const { request } = useAdminAuth();
  const [queue, setQueue] = useState<QueuedImage[]>([]);
  const [primary, setPrimary] = useState("");
  const [startOrder, setStartOrder] = useState(Math.max(-1, ...product.images.map((image) => image.sort_order)) + 1);
  const [busy, setBusy] = useState(false);
  const [completed, setCompleted] = useState(0);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const lock = useRef(false);

  function moveImage(index: number, direction: number) {
    setQueue((current) => {
      const next = [...current];
      const destination = index + direction;
      if (destination < 0 || destination >= next.length) { return current; }
      [next[index], next[destination]] = [next[destination], next[index]];
      return next;
    });
    onDirtyChange(true);
  }

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (lock.current || !queue.length) { return; }
    if (!Number.isInteger(startOrder) || startOrder < 0 || startOrder + queue.length - 1 > 1000000) {
      setError("Image display orders must be between 0 and 1000000."); return;
    }
    const form = new FormData(event.currentTarget);
    const variantId = form.get("variant_id") ? Number(form.get("variant_id")) : null;
    lock.current = true; setBusy(true); onBusyChange(true); setCompleted(0); setError(""); setNotice("");
    try {
      const result = await uploadProductImages(request, product.id, queue.map((item, index) => ({ ...item, sortOrder: startOrder + index, primary: item.key === primary, variantId })), setCompleted);
      const uploadedKeys = new Set(result.uploaded.map((item) => item.key));
      const remaining = queue.filter((item) => !uploadedKeys.has(item.key));
      setQueue(remaining); setStartOrder(startOrder + result.uploaded.length);
      if (uploadedKeys.has(primary)) { setPrimary(""); }
      onDirtyChange(remaining.length > 0);
      if (result.uploaded.length) { onUploaded(result.uploaded.map((item) => item.image)); }
      setNotice(`${result.uploaded.length} image${result.uploaded.length === 1 ? "" : "s"} uploaded.`);
      if (result.failure) { setError(`${queue.find((item) => item.key === result.failure!.key)?.file.name}: ${result.failure.message} The remaining files are ready to retry.`); }
    } finally { lock.current = false; setBusy(false); onBusyChange(false); }
  }

  return <form onSubmit={upload} className={productPanel}>
    <h2 className="mb-2 font-[inherit] text-lg font-semibold">Upload product images</h2>
    <p className="mb-5 text-xs leading-6 text-brown">Select multiple images, arrange their order, and choose a primary image. Uploads are added after existing images by default.</p>
    {error && <p role="alert" className="mb-4 rounded-xl bg-[#fff0ec] p-4 text-sm text-[#9b3528]">{error}</p>}
    {notice && <p role="status" className="mb-4 text-sm text-olive">{notice}</p>}
    <fieldset disabled={busy} className="flex flex-col gap-5">
      <Field label="Select images" name="product-image-files"><input id="product-image-files" type="file" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" className={productInput} onChange={(event) => {
        const files = Array.from(event.target.files || []);
        setQueue((current) => [...current, ...files.map((file) => ({ key: crypto.randomUUID(), file, altText: "" }))]);
        if (files.length) { onDirtyChange(true); }
        setError(""); event.target.value = "";
      }} /></Field>
      <div className="grid gap-4 sm:grid-cols-2"><Field label="Starting display order" name="image-start-order"><input id="image-start-order" type="number" min={0} max={1000000} step={1} required value={Number.isFinite(startOrder) ? startOrder : ""} onChange={(event) => { setStartOrder(event.target.valueAsNumber); onDirtyChange(true); }} className={productInput} /></Field><VariantSelect product={product} value={null} /></div>
      {queue.length > 0 && <>
        <label className="flex items-center gap-2 text-sm"><input type="radio" name="batch-primary" checked={!primary} onChange={() => { setPrimary(""); onDirtyChange(true); }} className="accent-olive" />Keep the existing primary image</label>
        <ol className="flex flex-col gap-3">{queue.map((item, index) => <li key={item.key} className="flex flex-wrap items-start gap-4 rounded-xl border border-cocoa/10 p-4">
          <ImagePreview file={item.file} />
          <div className="min-w-0 flex-1 basis-40"><p className="break-all text-sm font-medium">{item.file.name}</p><p className="mt-1 text-xs text-brown">Order {startOrder + index} · {(item.file.size / 1024 / 1024).toFixed(2)} MB</p><label className="mt-3 block text-xs text-brown">Alternative text<input maxLength={255} value={item.altText} onChange={(event) => { setQueue((current) => current.map((entry) => entry.key === item.key ? { ...entry, altText: event.target.value } : entry)); onDirtyChange(true); }} className={productInput + " mt-2"} /></label><label className="mt-3 flex items-center gap-2 text-xs"><input type="radio" name="batch-primary" checked={primary === item.key} onChange={() => { setPrimary(item.key); onDirtyChange(true); }} className="accent-olive" />Primary image</label></div>
          <div className="flex gap-1"><Button variant="ghost" size="sm" disabled={index === 0} aria-label={"Move " + item.file.name + " earlier"} onClick={() => moveImage(index, -1)}><ArrowUp size={16} aria-hidden="true" /></Button><Button variant="ghost" size="sm" disabled={index === queue.length - 1} aria-label={"Move " + item.file.name + " later"} onClick={() => moveImage(index, 1)}><ArrowDown size={16} aria-hidden="true" /></Button><Button variant="ghost" size="sm" aria-label={"Remove " + item.file.name + " from upload queue"} onClick={() => { const remaining = queue.filter((entry) => entry.key !== item.key); setQueue(remaining); if (primary === item.key) { setPrimary(""); } onDirtyChange(remaining.length > 0); }}><X size={16} aria-hidden="true" /></Button></div>
        </li>)}</ol>
      </>}
    </fieldset>
    {busy && <p role="status" className="mt-4 text-sm text-olive">Uploaded {completed} of {queue.length} images…</p>}
    <div className="mt-5 flex flex-wrap gap-2"><Button type="submit" loading={busy} disabled={!queue.length}>Upload {queue.length || ""} image{queue.length === 1 ? "" : "s"}</Button><Button variant="outline" disabled={busy} onClick={onCancel}>Cancel</Button></div>
  </form>;
}
