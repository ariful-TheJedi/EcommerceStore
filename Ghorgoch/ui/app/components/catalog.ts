import pageData from "@/data/messages.json";
import { lowestPurchasableVariant } from "../services/product-variants";

const content = pageData.catalog;

export type Category = { id: number; name: string; slug: string; parent_id: number | null; description?: string | null };
export type ProductImage = { id: number; url: string; alt_text: string | null; is_primary: boolean; variant_id: number | null };
export type Variant = {
  id: number; sku: string; price: string; effective_price: string; sale_price: string | null;
  attributes: Record<string, string>; is_active: boolean; available: boolean;
  available_stock: number; stock_quantity?: number; allow_backorders?: boolean; images: ProductImage[];
  inventory_enabled?: boolean;
};
export type SpecificationValue = string | number | boolean | null | SpecificationValue[] | { [key: string]: SpecificationValue };
export type ProductSpecifications = Record<string, SpecificationValue>;

export type Product = {
  specifications: ProductSpecifications | null;
  is_new?: boolean;
  id: number; name: string; slug: string; description: string | null; status: string;
  currency: string; categories: Category[]; variants: Variant[]; images: ProductImage[];
};
export type ProductListing = { products: Product[]; pagination: { current_page: number; last_page: number; total: number } };

export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body && !(init.body instanceof FormData)) { headers.set("Content-Type", "application/json"); }
  const response = await fetch(`/api${path}`, { ...init, headers, credentials: "same-origin", cache: "no-store" });
  const body = await response.json().catch(() => null);
  if (!response.ok) {
    const errors = body?.errors ? Object.values(body.errors).flat().join(" ") : "";
    throw new ApiError(response.status, errors || body?.message || content.unableToConnectPleaseTryAgain);
  }
  return body.data as T;
}

export const money = (value: string | number, currency = "BDT") => new Intl.NumberFormat("bn-BD", { style: "currency", currency }).format(Number(value));
export const productImage = (product: Product) => product.images.find((image) => image.is_primary)?.url || product.images[0]?.url;
export const lowestVariant = lowestPurchasableVariant;
