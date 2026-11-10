import type { AdminUser } from "@/admin/services/useAdminAuth";

export default function ProfileAvatar({ user, large = false }: { user: Pick<AdminUser, "name" | "avatar_url">; large?: boolean }) {
  const size = large ? "size-20 text-2xl" : "size-10 text-sm";
  return user.avatar_url ? <img src={user.avatar_url} alt="" width={large ? 80 : 40} height={large ? 80 : 40} className={`${size} shrink-0 rounded-full border border-cocoa/10 object-cover`} /> : <span aria-hidden="true" className={`${size} flex shrink-0 items-center justify-center rounded-full bg-olive font-semibold text-ivory`}>{user.name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase()}</span>;
}