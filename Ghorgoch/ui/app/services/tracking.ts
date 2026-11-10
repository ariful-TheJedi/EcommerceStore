import { api } from "@/components/catalog";

export type SavedOrder = { id: number; number: string; token?: string };
export type TrackedOrder = {
  id: number; order_number: string; status: string; payment_status: string; delivery_status: string;
  currency: string; total_amount: string; created_at: string; courier_name: string | null; tracking_reference: string | null;
  delivery: Record<string, string | null> | null;
  items: { product_id: number | null; product_name: string; sku: string; quantity: number; line_total: string }[];
  history: { action: string; new_status: string; created_at: string }[];
};

const storageKey = "ghorgoch.orders.v1";

export function savedOrders(): SavedOrder[] {
  try {
    const stored: unknown = JSON.parse(localStorage.getItem(storageKey) || "[]");
    return Array.isArray(stored) ? stored.filter((entry): entry is SavedOrder => entry && Number.isSafeInteger(entry.id) && entry.id > 0 && typeof entry.number === "string" && entry.number.length <= 100 && (entry.token === undefined || typeof entry.token === "string" && /^[a-f0-9]{64}$/i.test(entry.token))).slice(0, 20) : [];
  } catch { return []; }
}

export function rememberOrder(order: SavedOrder): void {
  try {
    const previous = savedOrders();
    const existing = previous.find((entry) => entry.number === order.number);
    const saved = { ...existing, ...order, token: order.token || existing?.token };
    localStorage.setItem(storageKey, JSON.stringify([saved, ...previous.filter((entry) => entry.number !== order.number)].slice(0, 20)));
  } catch { /* Tracking with the access code still works without browser storage. */ }
}

export async function trackOrder(number: string, token: string, customerOrderId?: number, signal?: AbortSignal): Promise<TrackedOrder> {
  const { order } = token
    ? await api<{ order: TrackedOrder }>(`/guest/orders/${encodeURIComponent(number)}`, { headers: { "X-Guest-Order-Token": token }, signal })
    : await api<{ order: TrackedOrder }>(`/customer/orders/${customerOrderId}`, { signal });
  return order;
}
