export const orderStatuses: Record<string, string> = {
  pending: "New order", confirmed: "Confirmed", processing: "Packed", shipped: "Go for delivery",
  delivered: "Delivered", returned: "Returned", cancelled: "Cancelled",
};

export type OrderSummary = {
  id: number; order_number: string; status: string; payment_status: string; currency: string;
  total_amount: string; created_at: string; contact: { name?: string; phone?: string; email?: string } | null; item_count: number;
  can_process?: boolean; delivery?: Record<string, string> | null;
  items?: { product_name: string; sku: string; quantity: number; unit_price: string; line_total: string }[];
};
export type OrderDetail = OrderSummary & {
  subtotal: string; shipping_fee: string; tax_amount: string; discount_amount: string;
  product_cost: string | null; expense_amount: string; return_restocked: boolean; can_process: boolean;
  allowed_transitions: string[]; customer_note: string | null; delivery: Record<string, string> | null;
  items: { product_name: string; sku: string; quantity: number; unit_price: string; line_total: string }[];
  history: { id: number; action: string; previous_status: string | null; new_status: string; note: string | null; created_at: string }[];
};
export type OrderListing = { orders: OrderSummary[]; pagination: { current_page: number; last_page: number; total: number } };
export type OrderReport = {
  total_orders: number; counts: Record<string, number>; date_basis: string;
  currencies: { currency: string; delivered_items: number; returned_items: number; income: string; expenses: string;
    operating_expenses: string; product_expenses: string; profit: string | null; missing_cost_orders: number }[];
};
export function orderMoney(value: string, currency: string): string {
  return new Intl.NumberFormat("en-BD", { style: "currency", currency }).format(Number(value));
}
export function orderDate(value: string): string {
  return new Intl.DateTimeFormat("en-GB", { dateStyle: "medium", timeStyle: "short", timeZone: "UTC" }).format(new Date(value));
}
