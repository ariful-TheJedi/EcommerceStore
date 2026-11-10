import "server-only";
import type { Product, ProductListing } from "@/components/catalog";
import { serverRequest } from "./request";

export async function getProducts({ limit = 4, sort = "id", category }: { limit?: number; sort?: "id" | "-created_at"; category?: string } = {}): Promise<ProductListing> {
  const query = new URLSearchParams({ limit: String(limit), sort });
  if (category) { query.set("category", category); }
  return serverRequest<ProductListing>(`/catalog/products?${query}`);
}

export async function getProduct(id: string): Promise<Product> {
  const { product } = await serverRequest<{ product: Product }>(`/catalog/products/${encodeURIComponent(id)}`);
  return product;
}
