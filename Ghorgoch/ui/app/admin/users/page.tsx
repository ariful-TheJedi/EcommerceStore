import content from "@/data/admin/users.json";

import AdminManager from "@/admin/components/AdminManager";

export default async function UsersPage({ searchParams }: { searchParams: Promise<{ new?: string }> }) {
  const params = await searchParams;
  return <AdminManager kind="users" content={content} initialCreate={params.new === "1"} />;
}
