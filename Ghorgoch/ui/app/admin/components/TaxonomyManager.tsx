"use client";

import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Package, Plus, X } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth } from "@/admin/services/useAdminAuth";
import { AdminApiError } from "@/admin/services/request";
import { loadTaxonomy, taxonomyBody, type CmsTerm } from "@/admin/services/products";
import type { Pagination } from "@/admin/services/dashboard";
import { Field, productInput, productPanel } from "./ProductFields";

function TaxonomyActionDialog({ title, busy, onClose, children }: { title: string; busy: boolean; onClose: () => void; children: ReactNode }) {
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
  return <dialog ref={dialog} className="taxonomy-dialog taxonomy-action-dialog" aria-labelledby={titleId} aria-busy={busy} onClose={(event) => { if (event.target === event.currentTarget && !event.currentTarget.open) { onClose(); } }} onCancel={(event) => { if (busy) { event.preventDefault(); } }} onClick={(event) => {
    if (busy || event.target !== event.currentTarget) { return; }
    const bounds = event.currentTarget.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) { event.currentTarget.close(); }
  }}>
    <div className="mb-5 flex items-center justify-between gap-4 border-b border-cocoa/10 pb-4"><h2 id={titleId} className="font-[inherit] text-xl font-semibold">{title}</h2><Button variant="ghost" size="sm" aria-label="Close dialog" disabled={busy} onClick={() => dialog.current?.close()}><X size={18} aria-hidden="true" /></Button></div>
    {children}
  </dialog>;
}

export default function TaxonomyManager({ kind, revision, onChanged, onBrowse, rootOnly = false, parentId }: {
  rootOnly?: boolean; parentId?: number; kind: "categories" | "tags"; revision: number; onChanged: () => void; onBrowse: (kind: "category" | "tag", slug: string) => void;
}) {
  const { request } = useAdminAuth();
  const [listing, setListing] = useState<{ items: CmsTerm[]; pagination: Pagination } | null>(null);
  const [parents, setParents] = useState<CmsTerm[]>([]);
  const [parentsReady, setParentsReady] = useState(false);
  const [editable, setEditable] = useState(false);
  const [editor, setEditor] = useState<CmsTerm | "new" | null>(null);
  const [removeIcon, setRemoveIcon] = useState(false);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState("");
  const [iconPreview, setIconPreview] = useState<{ url: string; name: string } | null>(null);
  const [pending, setPending] = useState<CmsTerm | null>(null);
  const [selectedTaxonomy, setSelectedTaxonomy] = useState<CmsTerm | null>(null);
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [optionError, setOptionError] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState("");
  const lock = useRef(false);
  const formHeading = useRef<HTMLHeadingElement>(null);
  const iconInput = useRef<HTMLInputElement>(null);
  const categoryDialog = useRef<HTMLDialogElement>(null);
  const singular = rootOnly ? "taxonomy" : kind === "categories" ? "category" : "tag";
  const title = rootOnly ? "Taxonomy" : kind === "categories" ? "Categories" : "Tags";
  const fieldPrefix = rootOnly ? "taxonomy" : parentId ? `children-${parentId}` : kind;

  useEffect(() => {
    if (!selectedTaxonomy) { return; }
    const dialog = categoryDialog.current;
    if (!dialog) { return; }
    dialog.showModal();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { dialog.close(); document.body.style.overflow = previousOverflow; };
  }, [selectedTaxonomy]);

  useEffect(() => {
    if (!imageFile) { setImagePreview(""); return; }
    const url = URL.createObjectURL(imageFile); setImagePreview(url);
    return () => URL.revokeObjectURL(url);
  }, [imageFile]);

  useEffect(() => { if (editor) { formHeading.current?.focus(); } }, [editor]);
  useEffect(() => {
    const controller = new AbortController();
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(20000)]);
    setOptionError(""); setParentsReady(false);
    request<{ user: { role: string; permissions: string[] } }>("/users/me", { signal }).then(({ user }) => {
      if (!controller.signal.aborted) { setEditable(user.role === "admin" || user.permissions.includes("catalog.manage")); }
    }).catch(() => { if (!controller.signal.aborted) { setOptionError("Editing permissions unavailable. Refresh to retry."); } });
    if (kind === "categories") {
      loadTaxonomy(request, "categories", signal).then((items) => { if (!controller.signal.aborted) { setParents(items); setParentsReady(true); } })
        .catch(() => { if (!controller.signal.aborted) { setOptionError("Parent categories could not be loaded. Retry before editing categories."); } });
    } else { setParentsReady(true); }
    return () => controller.abort();
  }, [request, kind, revision, retry]);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError("");
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]);
    const listingRequest = kind === "categories" ? loadTaxonomy(request, "categories", signal).then((items) => {
      const filtered = items.filter((item) => rootOnly ? item.is_taxonomy === true : parentId ? item.parent_id === parentId : item.is_taxonomy !== true);
      return { items: filtered.slice((page - 1) * 15, page * 15), pagination: { current_page: page, per_page: 15, total: filtered.length, last_page: Math.max(1, Math.ceil(filtered.length / 15)) } };
    }) : request<{ items: CmsTerm[]; pagination: Pagination }>(`/${kind}?page=${page}&per_page=15`, { signal });
    listingRequest
      .then((data) => {
        if (controller.signal.aborted) { return; }
        if (!Array.isArray(data?.items) || !Number.isSafeInteger(data?.pagination?.total)) { throw new Error("Unexpected taxonomy response."); }
        if (page > data.pagination.last_page) { setPage(Math.max(1, data.pagination.last_page)); return; }
        setListing(data);
      }).catch((failure) => { if (!controller.signal.aborted) { setListing(null); setError(failure instanceof Error ? failure.message : "Unable to load terms."); } })
      .finally(() => { if (!controller.signal.aborted) { setLoading(false); } });
    return () => controller.abort();
  }, [request, kind, page, revision, retry, rootOnly, parentId]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editor || lock.current || !editable || !parentsReady) { return; }
    if (imageFile && (imageFile.size > 2 * 1024 * 1024 || !/\.(svg|png|jpe?g)$/i.test(imageFile.name))) { setErrors({ image: ["Choose an SVG, PNG, JPG or JPEG up to 2 MB."] }); return; }
    const form = new FormData(event.currentTarget);
    if (kind === "categories" && !rootOnly && (editor === "new" || form.get("parent_id")) && !parents.some((item) => item.is_taxonomy === true && item.id === Number(form.get("parent_id")))) { setErrors({ parent_id: ["Select a taxonomy for this category."] }); return; }
    if (rootOnly) { form.set("parent_id", ""); }
    const body = { ...taxonomyBody(form, kind), ...(kind === "categories" ? { is_taxonomy: rootOnly } : {}) };
    lock.current = true; setBusy(true); setError(""); setErrors({}); setNotice("");
    try {
      const saved = await request<{ item: CmsTerm }>(editor === "new" ? "/" + kind : `/${kind}/${editor.id}`, { method: editor === "new" ? "POST" : "PATCH", body });
      if (kind === "categories" && imageFile) {
        setEditor(saved.item);
        onChanged();
        const upload = new FormData(); upload.set("image", imageFile);
        await request("/categories/" + saved.item.id + "/image", { method: "POST", body: upload });
      }
      if (kind === "categories" && removeIcon && !imageFile) {
        setEditor(saved.item);
        await request("/categories/" + saved.item.id + "/image", { method: "DELETE" });
      }
      setRemoveIcon(false); setImageFile(null);
      setEditor(null); setNotice("Saved successfully."); onChanged();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save.");
      if (failure instanceof AdminApiError) { setErrors(failure.errors); }
    } finally { lock.current = false; setBusy(false); }
  }
  async function assignCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!parentId || !editable || !parentsReady || lock.current) { return; }
    const categoryId = Number(new FormData(event.currentTarget).get("category_id"));
    const category = parents.find((item) => item.id === categoryId && item.is_taxonomy !== true && item.parent_id !== parentId);
    if (!category || !parents.some((item) => item.id === parentId && item.is_taxonomy === true)) { return; }
    lock.current = true; setBusy(true); setError(""); setNotice("");
    try {
      await request("/categories/" + category.id, { method: "PATCH", body: { parent_id: parentId } });
      setNotice("Category moved into this taxonomy. Products and category details have been preserved."); onChanged();
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to assign category."); }
    finally { lock.current = false; setBusy(false); }
  }

  async function remove() {
    if (!pending || lock.current || !editable) { return; }
    lock.current = true; setBusy(true); setError(""); setNotice("");
    try {
      if (parentId) {
        await request("/categories/" + pending.id, { method: "PATCH", body: { parent_id: null } });
      } else {
        await request(`/${kind}/${pending.id}`, { method: "DELETE" });
      }
      setPending(null); setNotice(parentId ? "Removed from taxonomy. The category is now independent; its products have been preserved." : "Removed successfully. Products have been preserved."); onChanged();
    } catch (failure) {
      setError(failure instanceof AdminApiError && failure.status === 409 ? "This category has child categories. Reassign or remove them first." : failure instanceof Error ? failure.message : "Unable to remove.");
    } finally { lock.current = false; setBusy(false); }
  }

  return <section className="flex flex-col gap-5" aria-label={title}>
    {!parentId && <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-[inherit] text-xl font-semibold">{title}</h2><p className="mt-2 text-xs text-brown">{kind === "categories" ? rootOnly ? "Create parent taxonomies and manage their child categories." : "Choose a taxonomy when creating a category. Removed categories remain independent." : "Create reusable labels and assign them to products."}</p></div>{editable && <Button disabled={busy || !parentsReady || (kind === "categories" && !rootOnly && !parents.some((item) => item.is_taxonomy === true))} startIcon={<Plus size={15} />} onClick={() => { setRemoveIcon(false); setImageFile(null); setEditor("new"); setPending(null); setErrors({}); setError(""); setNotice(""); }}>Add {singular}</Button>}</div>}
    {(error || optionError) && <div role="alert" className="rounded-xl border border-[#9b3528]/20 bg-[#fff0ec] p-4 text-sm text-[#9b3528]"><p>{error || optionError}</p><Button variant="outline" size="sm" disabled={busy} onClick={() => setRetry((value) => value + 1)}>Retry</Button></div>}
    {notice && <p role="status" className="rounded-xl bg-olive/10 p-4 text-sm text-olive">{notice}</p>}
    {kind === "categories" && !rootOnly && parentsReady && !parents.some((item) => item.is_taxonomy === true) && <p role="status" className="rounded-xl bg-olive/10 p-4 text-sm text-olive">Create a parent in the Taxonomy tab before adding categories.</p>}
    {parentId && editable && parentsReady && <form onSubmit={assignCategory} className={productPanel}>
      <h3 className="mb-2 text-sm font-semibold">Add an existing category</h3>
      <p className="mb-4 text-xs leading-6 text-brown">Add an independent category or move a category from another taxonomy. Its products and details are preserved.</p>
      <div className="flex flex-wrap items-end gap-3"><div className="min-w-0 flex-1"><Field label="Category" name={fieldPrefix + "-existing"}><select id={fieldPrefix + "-existing"} name="category_id" required disabled={busy} defaultValue="" className={productInput}><option value="" disabled>Select a category to move here</option>{parents.filter((item) => item.is_taxonomy !== true && item.parent_id !== parentId).map((item) => <option key={item.id} value={item.id}>{item.name} ({parents.find((parent) => parent.id === item.parent_id)?.name ?? "Independent"})</option>)}</select></Field></div><Button type="submit" loading={busy}>Move into taxonomy</Button></div>
    </form>}
    {editor && <TaxonomyActionDialog title={(editor === "new" ? "Add " : "Edit ") + singular} busy={busy} onClose={() => setEditor(null)}>{error && <p role="alert" className="mb-4 rounded-xl bg-[#fff0ec] p-4 text-sm text-[#9b3528]">{error}</p>}<form onSubmit={save} className={productPanel} key={editor === "new" ? "new" : editor.id}>
      <h3 ref={formHeading} tabIndex={-1} className="mb-5 font-[inherit] text-lg font-semibold">{editor === "new" ? "Add " : "Edit "}{singular}</h3>
      <fieldset disabled={busy || !parentsReady} className="grid gap-4 sm:grid-cols-2">
        <Field label="Name" name={fieldPrefix + "-name"} error={errors.name}><input id={fieldPrefix + "-name"} name="name" required maxLength={kind === "categories" ? 150 : 100} defaultValue={editor === "new" ? "" : editor.name} className={productInput} aria-invalid={Boolean(errors.name)} /></Field>
        <Field label="URL slug" name={fieldPrefix + "-slug"} error={errors.slug}><input id={fieldPrefix + "-slug"} name="slug" required maxLength={kind === "categories" ? 180 : 120} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" defaultValue={editor === "new" ? "" : editor.slug} className={productInput} aria-invalid={Boolean(errors.slug)} /></Field>
        {kind === "categories" && <>
          <div className="sm:col-span-2"><Field label="Category icon / photo" name={fieldPrefix + "-image"} error={errors.image}><div className="flex flex-wrap items-center gap-4">{(imagePreview || (editor !== "new" && !removeIcon && editor.image_url)) ? <img src={imagePreview || (editor !== "new" ? editor.image_url || "" : "")} alt="Category icon preview" width={64} height={64} className="size-16 rounded-xl border border-cocoa/10 bg-ivory object-contain p-2" /> : <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory text-olive" role="img" aria-label="Default category icon"><Package size={30} strokeWidth={1.5} aria-hidden="true" /></span>}<div className="flex min-w-0 flex-1 items-center gap-2"><input ref={iconInput} id={fieldPrefix + "-image"} type="file" accept=".svg,.png,.jpg,.jpeg,image/svg+xml,image/png,image/jpeg" className={productInput} aria-invalid={Boolean(errors.image)} onChange={(event) => { const file = event.target.files?.[0] || null; if (file) { setRemoveIcon(false); } setImageFile(file); setErrors((current) => { const next = { ...current }; delete next.image; if (file && (file.size > 2 * 1024 * 1024 || !/\.(svg|png|jpe?g)$/i.test(file.name))) { next.image = ["Choose an SVG, PNG, JPG or JPEG up to 2 MB."]; } return next; }); }} />{imageFile && <button type="button" aria-label="Clear selected icon" title="Clear selected icon" className="inline-flex size-10 shrink-0 items-center justify-center rounded-full text-brown transition-colors hover:bg-rose/40 hover:text-cocoa focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive motion-reduce:transition-none" onClick={() => { setImageFile(null); setErrors((current) => { const next = { ...current }; delete next.image; return next; }); if (iconInput.current) { iconInput.current.value = ""; iconInput.current.focus(); } }}><X size={18} aria-hidden="true" /></button>}</div></div><p className="mt-2 text-xs text-brown">SVG, PNG, JPG or JPEG. Maximum 2 MB.{imageFile ? " Selected: " + imageFile.name : ""}</p>{editor !== "new" && editor.image_url && <div className="mt-3 flex flex-wrap items-center gap-3"><Button variant="outline" size="sm" onClick={() => { setRemoveIcon((current) => !current); setImageFile(null); }}>{removeIcon ? "Keep current icon" : "Remove icon"}</Button>{removeIcon && <span role="status" className="text-xs text-brown">The icon will be removed when you save.</span>}</div>}</Field></div>
          {!rootOnly && <Field label="Taxonomy" name={fieldPrefix + "-parent"} error={errors.parent_id}><select id={fieldPrefix + "-parent"} name="parent_id" required={editor === "new"} defaultValue={editor === "new" ? parentId ?? "" : editor.parent_id ?? ""} className={productInput} aria-invalid={Boolean(errors.parent_id)}><option value="" disabled={editor === "new"}>{editor === "new" ? "Select a taxonomy" : "Independent category"}</option>{parents.filter((item) => item.is_taxonomy === true && (editor === "new" || item.id !== editor.id)).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></Field>}
          <Field label="Display order" name="sort_order" error={errors.sort_order}><input type="number" id="sort_order" name="sort_order" min={0} max={1000000} step={1} defaultValue={editor === "new" ? 0 : editor.sort_order ?? 0} className={productInput} /></Field>
          <div className="sm:col-span-2"><Field label="Description" name="description" error={errors.description}><textarea id="description" name="description" maxLength={100000} rows={4} defaultValue={editor === "new" ? "" : editor.description ?? ""} className={productInput} /></Field></div>
          <label className="flex items-center gap-2 text-sm"><input name="is_active" type="checkbox" defaultChecked={editor === "new" || editor.is_active} className="size-4 accent-olive" />Active on storefront</label>
        </>}
      </fieldset><div className="mt-5 flex gap-2"><Button type="submit" loading={busy} disabled={!parentsReady}>Save {singular}</Button><Button variant="outline" disabled={busy} onClick={() => setEditor(null)}>Cancel</Button></div>
    </form></TaxonomyActionDialog>}
    {pending && <TaxonomyActionDialog title={parentId ? "Remove from taxonomy" : "Delete " + singular} busy={busy} onClose={() => setPending(null)}>{error && <p role="alert" className="mb-4 rounded-xl bg-[#fff0ec] p-4 text-sm text-[#9b3528]">{error}</p>}<div role="alert" className={productPanel}><p className="text-sm">{parentId ? "Remove from this taxonomy: " : "Delete "}<strong>{pending.name}</strong>? {parentId ? "The category will become independent. Its products and details will be preserved." : "Product assignments will be detached. Products will be preserved."}{kind === "categories" && !parentId ? " Categories with children must be reorganized first." : ""}</p><div className="mt-4 flex gap-2"><Button loading={busy} onClick={() => void remove()}>{parentId ? "Remove from taxonomy" : "Delete " + singular}</Button><Button variant="outline" disabled={busy} onClick={() => setPending(null)} autoFocus>Cancel</Button></div></div></TaxonomyActionDialog>}
    <div className="overflow-hidden rounded-2xl border border-cocoa/10 bg-[#fffdfb]">
      {loading ? <p role="status" className="p-10 text-center text-sm text-brown">Loading {kind}...</p> : listing && <>
        <div className="overflow-x-auto" role="region" aria-label={title + " list"} tabIndex={0}><table className="w-full min-w-160 text-left text-sm"><caption className="sr-only">{title}</caption><thead className="bg-ivory text-xs text-brown"><tr><th scope="col" className="px-5 py-3">Name / slug</th>{kind === "categories" && <><th scope="col" className="px-5 py-3">{rootOnly ? "Child categories" : "Taxonomy"}</th><th scope="col" className="px-5 py-3">Status / order</th></>}<th scope="col" className="px-5 py-3">Actions</th></tr></thead><tbody className="divide-y divide-cocoa/8">{listing.items.map((item) => <tr key={item.id}><td className="max-w-72 px-5 py-4"><div className="flex items-center gap-3">{item.image_url ? <button type="button" className="inline-flex size-12 shrink-0 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory p-1.5 transition-colors hover:border-olive/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-olive motion-reduce:transition-none" aria-label={"Preview icon for " + item.name} title="Preview icon" onClick={() => setIconPreview({ url: item.image_url!, name: item.name })}><img src={item.image_url} alt="" width={48} height={48} loading="lazy" className="size-full object-contain" /></button> : kind === "categories" ? <span className="inline-flex size-12 shrink-0 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory text-olive" role="img" aria-label="Default category icon"><Package size={24} strokeWidth={1.5} aria-hidden="true" /></span> : null}<p className="break-words font-semibold">{item.name}</p></div><p className="mt-1 break-all text-xs text-brown">{item.slug}</p></td>{kind === "categories" && <><td className="px-5 py-4 text-xs">{rootOnly ? parents.filter((child) => child.parent_id === item.id).map((child) => child.name).join(", ") || "No categories yet" : (item.parent_id == null ? "Independent category" : parents.find((parent) => parent.id === item.parent_id)?.name ?? "#" + item.parent_id)}</td><td className="px-5 py-4 text-xs">{item.is_active ? "Active" : "Inactive"} / {item.sort_order ?? 0}</td></>}<td className="px-5 py-4"><div className="flex flex-wrap gap-2">{rootOnly && <Button variant="outline" size="sm" disabled={busy} onClick={() => setSelectedTaxonomy(item)}>Manage categories</Button>}<Button variant="ghost" size="sm" disabled={busy} onClick={() => onBrowse(kind === "categories" ? "category" : "tag", item.slug)}>Products</Button>{editable && <><Button size="sm" variant="outline" disabled={busy || !parentsReady} onClick={() => { setRemoveIcon(false); setImageFile(null); setEditor(item); setPending(null); setErrors({}); setError(""); }}>Edit</Button><Button size="sm" variant="ghost" disabled={busy} onClick={() => { setPending(item); setEditor(null); setError(""); }}>{parentId ? "Remove from taxonomy" : "Delete"}</Button></>}</div></td></tr>)}{!listing.items.length && <tr><td colSpan={kind === "categories" ? 4 : 2} className="p-10 text-center text-brown">No {kind} added yet.</td></tr>}</tbody></table></div>
        <nav aria-label={title + " pagination"} className="flex flex-wrap items-center justify-between gap-3 border-t border-cocoa/8 p-5"><p className="text-xs text-brown">{listing.pagination.total} {kind} · Page {page} of {listing.pagination.last_page}</p><div className="flex gap-2"><Button size="sm" variant="outline" disabled={busy || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</Button><Button size="sm" variant="outline" disabled={busy || page >= listing.pagination.last_page} onClick={() => setPage((value) => value + 1)}>Next</Button></div></nav>
      </>}
    </div>
    {iconPreview && <TaxonomyActionDialog title={iconPreview.name + " icon"} busy={false} onClose={() => setIconPreview(null)}><div className="flex min-h-64 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory p-6"><img src={iconPreview.url} alt={iconPreview.name + " category icon"} width={384} height={384} className="max-h-[55dvh] w-full max-w-96 object-contain" /></div><div className="mt-5 flex justify-end"><Button variant="outline" onClick={() => setIconPreview(null)}>Close preview</Button></div></TaxonomyActionDialog>}
    {rootOnly && <dialog ref={categoryDialog} className="taxonomy-dialog" aria-label={selectedTaxonomy ? "Categories in " + selectedTaxonomy.name : "Category manager"} onClose={(event) => { if (event.target === event.currentTarget && !event.currentTarget.open) { setSelectedTaxonomy(null); } }} onClick={(event) => {
      if (event.target !== event.currentTarget) { return; }
      const bounds = event.currentTarget.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) { event.currentTarget.close(); }
    }}>
      {selectedTaxonomy && <div className="relative [&>section>div:first-child]:pr-14">
        <div className="absolute right-0 top-0 z-10">
          <Button variant="ghost" size="sm" aria-label="Close category manager" autoFocus onClick={() => categoryDialog.current?.close()}><X size={18} aria-hidden="true" /></Button>
        </div>
        <TaxonomyManager key={selectedTaxonomy.id} kind="categories" parentId={selectedTaxonomy.id} revision={revision} onChanged={onChanged} onBrowse={(kind, slug) => { categoryDialog.current?.close(); onBrowse(kind, slug); }} />
      </div>}
    </dialog>}
  </section>;
}
