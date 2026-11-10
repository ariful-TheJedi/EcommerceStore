import content from "@/data/admin/inventory.json";

import AdminPlaceholder from "@/admin/components/AdminPlaceholder";

export default function InventoryPage() {
  return <AdminPlaceholder title={content.inventory} />;
}
