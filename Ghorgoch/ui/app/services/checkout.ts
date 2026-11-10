import { api, ApiError, lowestVariant, type Product } from "@/components/catalog";
import type { CartItem } from "@/components/CartProvider";

export type DeliveryCharges = { inside_dhaka: string; outside_dhaka: string; currency: string; version: number };
export type CheckoutQuote = {
  review_token: string; subtotal: string; shipping_fee: string; total_amount: string; currency: string;
  items: { variant_id: number; name: string; sku: string; quantity: number; unit_price: string; line_total: string }[];
};
export type PlacedOrder = { id: number; order_number: string; total_amount: string; currency: string; status: string };

export async function shopRequest<T>(path: string, body: object): Promise<T> {
  async function send(retry: boolean): Promise<T> {
    const { csrf_token } = await api<{ csrf_token: string }>("/auth/csrf-token");
    try { return await api<T>(path, { method: "POST", headers: { "X-CSRF-TOKEN": csrf_token }, body: JSON.stringify(body) }); }
    catch (error) { if (retry && error instanceof ApiError && error.status === 419) { return send(false); } throw error; }
  }
  return send(true);
}

export async function checkoutItems(items: CartItem[]): Promise<{ variant_id: number; quantity: number }[]> {
  const resolved = await Promise.all(items.map(async (item) => {
    let variantId = item.variantId || (item.id.startsWith("variant-") ? Number(item.id.slice(8)) : undefined);
    if (!variantId) {
      const productId = item.href.startsWith("/products/") ? item.href.slice("/products/".length).split("?")[0] : "";
      if (!/^[1-9]\d*$/.test(productId)) { throw new Error("পণ্যটি আবার ব্যাগে যোগ করুন।"); }
      const { product } = await api<{ product: Product }>(`/catalog/products/${productId}`);
      const variant = lowestVariant({ ...product, variants: product.variants.filter((entry) => entry.available) });
      if (!variant) { throw new Error(`${item.name}: পণ্যটি এখন উপলব্ধ নয়।`); }
      variantId = variant.id;
    }
    return { variant_id: variantId, quantity: item.quantity };
  }));
  const combined = new Map<number, number>();
  resolved.forEach((item) => combined.set(item.variant_id, (combined.get(item.variant_id) || 0) + item.quantity));
  return Array.from(combined, ([variant_id, quantity]) => ({ variant_id, quantity }));
}