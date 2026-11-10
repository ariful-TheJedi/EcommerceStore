import type { adminRequest } from "./request";
import type { Pagination, TaxonomyItem } from "./dashboard";
import type { ProductSpecifications } from "@/components/catalog";

export type ProductImage = { id: number; url: string; alt_text: string | null; variant_id: number | null; is_primary: boolean; sort_order: number };
export type ProductVariant = {
  id: number; sku: string; attributes: Record<string, string>; price: string; sale_price: string | null;
  effective_price: string; cost_price: string | null; sale_starts_at: string | null; sale_ends_at: string | null;
  barcode: string | null; weight_grams: number | null; is_active: boolean; allow_backorders: boolean;
  low_stock_threshold: number; stock_quantity: number; reserved_quantity: number; available_stock: number;
};
export type Product = {
  specifications: ProductSpecifications | null;
  is_new: boolean;
  id: number; name: string; slug: string; description: string | null; status: "draft" | "published" | "archived";
  currency: string; categories: TaxonomyItem[]; tags: TaxonomyItem[]; variants: ProductVariant[]; images: ProductImage[];
};
export type ProductListing = { products: Product[]; pagination: Pagination };
export type TaxonomyOptions = { categories: TaxonomyItem[]; tags: TaxonomyItem[] };
export type CmsTerm = TaxonomyItem & { description?: string | null; sort_order?: number };

export function taxonomyBody(form: FormData, kind: "categories" | "tags"): object {
  return {
    name: String(form.get("name") ?? "").trim(), slug: String(form.get("slug") ?? "").trim(),
    ...(kind === "categories" ? {
      description: String(form.get("description") ?? "") || null,
      parent_id: form.get("parent_id") ? Number(form.get("parent_id")) : null,
      is_active: form.has("is_active"), sort_order: Number(form.get("sort_order") || 0),
    } : {}),
  };
}

export async function loadTaxonomy(request: typeof adminRequest, kind: "categories" | "tags", signal: AbortSignal): Promise<TaxonomyItem[]> {
  const items: TaxonomyItem[] = [];
  let page = 1;
  let last = 1;
  do {
    const data = await request<{ items: TaxonomyItem[]; pagination: Pagination }>(`/${kind}?per_page=100&page=${page}`, { signal });
    if (!Array.isArray(data?.items) || !Number.isSafeInteger(data?.pagination?.last_page) || data.pagination.last_page < page) {
      throw new Error("Unable to read category or tag options.");
    }
    items.push(...data.items); last = data.pagination.last_page; page += 1;
  } while (page <= last);
  return items;
}

export function productBody(form: FormData, includeTaxonomy: boolean): object {
  const raw = String(form.get("specifications") ?? "").trim();
  let specifications: ProductSpecifications | null = null;
  if (raw) {
    let parsed: unknown;
    try { parsed = JSON.parse(raw); }
    catch { throw new Error("Specifications must be valid JSON. Check the quotes, commas, and brackets."); }
    if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
      throw new Error("Specifications must contain named properties, for example: {\"Material\": \"Cotton\"}.");
    }
    specifications = parsed as ProductSpecifications;
  }
  return {
    specifications,
    name: String(form.get("name") ?? "").trim(), slug: String(form.get("slug") ?? "").trim(),
    description: String(form.get("description") ?? "") || null, status: String(form.get("status") ?? "draft"),
    is_new: form.has("is_new"),
    ...(includeTaxonomy ? { category_ids: form.getAll("category_ids").map(Number), tag_ids: form.getAll("tag_ids").map(Number) } : {}),
  };
}

export function variantBody(form: FormData, options: { name: string; value: string }[]): object {
  const attributes: Record<string, string> = Object.create(null);
  for (const option of options) {
    const name = option.name.trim(); const value = option.value.trim();
    if (!name && !value) { continue; }
    if (!name || !value) { throw new Error("Each option needs both a name and a value."); }
    if (Object.keys(attributes).some((key) => key.toLowerCase() === name.toLowerCase())) { throw new Error("Option names must be unique."); }
    attributes[name] = value;
  }
  const nullable = (key: string) => String(form.get(key) ?? "").trim() || null;
  const date = (key: string) => nullable(key) ? new Date(String(form.get(key))).toISOString() : null;
  return {
    sku: String(form.get("sku") ?? "").trim().toUpperCase(), price: String(form.get("price") ?? ""),
    sale_price: nullable("sale_price"), cost_price: nullable("cost_price"), barcode: nullable("barcode"),
    weight_grams: nullable("weight_grams") === null ? null : Number(form.get("weight_grams")),
    low_stock_threshold: Number(form.get("low_stock_threshold") || 0), attributes,
    sale_starts_at: date("sale_starts_at"), sale_ends_at: date("sale_ends_at"),
    is_active: form.has("is_active"), allow_backorders: form.has("allow_backorders"),
  };
}

export function localDate(value: string | null | undefined): string {
  if (!value) { return ""; }
  const date = new Date(value);
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19);
}

export function imageBody(form: FormData): object {
  return { alt_text: String(form.get("alt_text") ?? "") || null, variant_id: form.get("variant_id") ? Number(form.get("variant_id")) : null,
    sort_order: Number(form.get("sort_order") || 0), is_primary: form.has("is_primary") };
}

export function imageUploadBody(form: FormData): FormData {
  form.set("is_primary", form.has("is_primary") ? "1" : "0");
  if (!form.get("variant_id")) { form.delete("variant_id"); }
  return form;
}

export type QueuedProductImage = { key: string; file: File; altText: string; sortOrder: number; primary: boolean; variantId: number | null };

export async function uploadProductImages(request: typeof adminRequest, productId: number, images: QueuedProductImage[], onProgress: (completed: number) => void) {
  const uploaded: { key: string; image: ProductImage }[] = [];
  for (const item of images) {
    const body = new FormData();
    body.set("image", item.file);
    body.set("alt_text", item.altText);
    body.set("sort_order", String(item.sortOrder));
    if (item.primary) { body.set("is_primary", "on"); }
    if (item.variantId !== null) { body.set("variant_id", String(item.variantId)); }
    try {
      const data = await request<{ image: ProductImage }>(`/products/${productId}/images`, { method: "POST", body: imageUploadBody(body) });
      uploaded.push({ key: item.key, image: data.image });
      onProgress(uploaded.length);
    } catch (failure) {
      return { uploaded, failure: { key: item.key, message: failure instanceof Error ? failure.message : "Unable to upload this image." } };
    }
  }
  return { uploaded, failure: null };
}

export function priceLabel(product: Product): string {
  const values = product.variants.filter((variant) => variant.is_active).map((variant) => Number(variant.effective_price));
  if (!values.length) { return "No active variants"; }
  const format = (value: number) => new Intl.NumberFormat("en", { style: "currency", currency: product.currency, maximumFractionDigits: 2 }).format(value);
  const min = Math.min(...values); const max = Math.max(...values);
  return min === max ? format(min) : `${format(min)} – ${format(max)}`;
}
