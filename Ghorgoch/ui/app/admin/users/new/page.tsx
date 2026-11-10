import { redirect } from "next/navigation";

export default function AddUserPage() {
  redirect("/admin/users?new=1");
}
