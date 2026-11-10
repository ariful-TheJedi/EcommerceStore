import { orderStatuses } from "@/admin/services/orders";

const statusColors: Record<string, string> = {
  pending: "bg-[#fff3da] text-[#815a17]",
  confirmed: "bg-[#edf0fc] text-[#4e5d92]",
  processing: "bg-[#f1eafa] text-[#775294]",
  shipped: "bg-[#e8f1fa] text-[#36678a]",
  delivered: "bg-olive/10 text-olive",
  returned: "bg-[#fbebe5] text-[#a15135]",
  cancelled: "bg-cocoa/6 text-brown",
};

export default function OrderStatusBadge({ status }: { status: string }) {
  return <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold ${statusColors[status] || statusColors.cancelled}`}>
    <span aria-hidden="true" className="size-1.5 rounded-full bg-current" />
    {orderStatuses[status] || status}
  </span>;
}
