"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Check, Eye, EyeOff, LockKeyhole, ShieldCheck, UserRound } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth, type AdminUser } from "@/admin/services/useAdminAuth";
import { AdminApiError } from "@/admin/services/request";
import { Field, productInput, productPanel } from "./ProductFields";
import ProfileAvatar from "./ProfileAvatar";
import ProfilePhotoSettings from "./ProfilePhotoSettings";

type Section = "profile" | "password" | "role" | "photo";
type Account = AdminUser & { role: "admin" | "staff" };

function PasswordInput({ id, name, disabled, autoComplete, required = true, minLength }: { id: string; name: string; disabled: boolean; autoComplete: string; required?: boolean; minLength?: number }) {
  const [visible, setVisible] = useState(false);
  return <div className="relative"><input id={id} name={name} type={visible ? "text" : "password"} required={required} minLength={minLength} maxLength={1000} disabled={disabled} autoComplete={autoComplete} className={`${productInput} pr-12`} /><button type="button" disabled={disabled} aria-label={visible ? "Hide password" : "Show password"} aria-pressed={visible} onClick={() => setVisible((value) => !value)} className="absolute top-0 right-0 flex size-11 items-center justify-center rounded-xl text-brown focus-visible:outline-2 focus-visible:outline-olive">{visible ? <EyeOff size={17} aria-hidden="true" /> : <Eye size={17} aria-hidden="true" />}</button></div>;
}

export default function AccountSettings() {
  const { request, updateUser } = useAdminAuth();
  const [account, setAccount] = useState<Account | null>(null);
  const [loadError, setLoadError] = useState("");
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState<Section | null>(null);
  const [notice, setNotice] = useState<{ section: Section; message: string } | null>(null);
  const [failure, setFailure] = useState<{ section: Section; message: string; fields: Record<string, string[]> } | null>(null);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("admin");
  const locked = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    setLoadError("");
    request<{ user: Account }>("/users/me", { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]) })
      .then((data) => { if (!controller.signal.aborted) { setAccount(data.user); setEmail(data.user.email); setRole(data.user.role); updateUser(data.user); } })
      .catch((error) => { if (!controller.signal.aborted) { setLoadError(error instanceof Error ? error.message : "Unable to load settings."); } });
    return () => controller.abort();
  }, [request, revision, updateUser]);

  async function save(event: FormEvent<HTMLFormElement>, section: Section) {
    event.preventDefault();
    if (locked.current || !account) { return; }
    const form = event.currentTarget;
    const values = new FormData(form);
    const value = (key: string) => String(values.get(key) || "");
    const body = section === "profile" ? { name: value("name").trim(), email: email.trim(), ...(email.trim().toLowerCase() !== account.email ? { current_password: value("current_password") } : {}) }
      : section === "password" ? { current_password: value("current_password"), password: value("password"), password_confirmation: value("password_confirmation") }
      : { role, current_password: value("current_password") };
    locked.current = true; setBusy(section); setNotice(null); setFailure(null);
    try {
      const data = await request<{ user: Account }>("/users/me/settings", { method: "PATCH", body });
      setAccount(data.user); setEmail(data.user.email); setRole(data.user.role); updateUser(data.user);
      form.reset();
      setNotice({ section, message: section === "profile" ? "Profile updated successfully." : section === "password" ? "Password updated. You are still signed in." : "Your role has been updated." });
    } catch (error) {
      setFailure({ section, message: error instanceof AdminApiError && error.status === 409 ? "The last active administrator cannot switch to Staff. Another active admin must remain." : error instanceof Error ? error.message : "Unable to save settings.", fields: error instanceof AdminApiError ? error.errors : {} });
    } finally { locked.current = false; setBusy(null); }
  }
  function feedback(section: Section) {
    return <>{notice?.section === section && <p role="status" className="flex items-start gap-2 rounded-xl bg-olive/8 p-3 text-sm leading-6 text-olive"><Check size={17} className="mt-1 shrink-0" aria-hidden="true" />{notice.message}</p>}{failure?.section === section && <p role="alert" className="rounded-xl bg-[#9b3528]/5 p-3 text-sm leading-6 text-[#9b3528]">{failure.message}</p>}</>;
  }
  const fields = (section: Section) => failure?.section === section ? failure.fields : {};
  const emailChanged = account !== null && email.trim().toLowerCase() !== account.email;

  if (loadError) { return <div role="alert" className={`${productPanel} flex flex-col gap-4`}><p className="text-sm text-[#9b3528]">{loadError}</p><Button variant="outline" onClick={() => setRevision((value) => value + 1)}>Retry loading settings</Button></div>; }
  if (!account) { return <p role="status" className="py-12 text-center text-sm text-brown">Loading your settings...</p>; }

  return <div className="mx-auto flex max-w-5xl flex-col gap-6" style={{ fontFamily: "Arial, sans-serif" }}>
    <header><h1 className="text-2xl font-semibold">Account settings</h1><p className="mt-2 text-sm text-brown">Manage your profile, password, and account access.</p></header>
    <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-cocoa/10 bg-olive/5 p-5"><ProfileAvatar user={account} /><div className="min-w-0 flex-1"><p className="font-semibold">{account.name}</p><p className="mt-1 break-all text-sm text-brown">{account.email}</p></div><span className="rounded-full bg-olive/10 px-3 py-1.5 text-xs font-semibold capitalize text-olive">{account.role}</span></div>

    <ProfilePhotoSettings user={account} disabled={busy !== null} onBusyChange={(value) => { locked.current = value; setBusy(value ? "photo" : null); }} onUpdated={(updated) => { setAccount(updated); updateUser(updated); }} />

    <section className={`${productPanel} grid gap-6 md:grid-cols-[13rem_1fr]`}><div><h2 className="flex items-center gap-2 text-base font-semibold"><UserRound size={18} className="text-olive" aria-hidden="true" />Profile</h2><p className="mt-2 text-xs leading-6 text-brown">Update your display name and sign-in email.</p></div><form key={account.name + ":" + account.email} onSubmit={(event) => void save(event, "profile")} className="flex flex-col gap-4">{feedback("profile")}<Field label="Name" name="settings-name" error={fields("profile").name}><input id="settings-name" name="name" defaultValue={account.name} required maxLength={150} disabled={busy !== null} autoComplete="name" className={productInput} /></Field><Field label="Email address" name="settings-email" error={fields("profile").email}><input id="settings-email" name="email" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} maxLength={255} disabled={busy !== null} autoComplete="email" className={productInput} /></Field><p className="text-xs text-brown">Email changes take effect immediately. No verification email is required.</p>{emailChanged && <Field label="Current password to confirm email change" name="profile-current-password" error={fields("profile").current_password}><PasswordInput id="profile-current-password" name="current_password" disabled={busy !== null} autoComplete="current-password" /></Field>}<div className="pt-1"><Button type="submit" loading={busy === "profile"} disabled={busy !== null}>Save profile</Button></div></form></section>

    <section className={`${productPanel} grid gap-6 md:grid-cols-[13rem_1fr]`}><div><h2 className="flex items-center gap-2 text-base font-semibold"><LockKeyhole size={18} className="text-olive" aria-hidden="true" />Password</h2><p className="mt-2 text-xs leading-6 text-brown">Choose a strong password to protect your account.</p></div><form onSubmit={(event) => void save(event, "password")} className="flex flex-col gap-4">{feedback("password")}<Field label="Current password" name="password-current" error={fields("password").current_password}><PasswordInput id="password-current" name="current_password" disabled={busy !== null} autoComplete="current-password" /></Field><Field label="New password" name="password-new" error={fields("password").password}><PasswordInput id="password-new" name="password" disabled={busy !== null} autoComplete="new-password" minLength={12} /></Field><p className="text-xs leading-5 text-brown">Use at least 12 characters, including uppercase, lowercase, a number, and a symbol. Maximum 72 bytes.</p><Field label="Confirm new password" name="password-confirmation" error={fields("password").password_confirmation}><PasswordInput id="password-confirmation" name="password_confirmation" disabled={busy !== null} autoComplete="new-password" minLength={12} /></Field><p className="text-xs text-brown">Other sessions will be signed out. This session will stay active.</p><div className="pt-1"><Button type="submit" loading={busy === "password"} disabled={busy !== null}>Update password</Button></div></form></section>

    <section className={`${productPanel} grid gap-6 md:grid-cols-[13rem_1fr]`}><div><h2 className="flex items-center gap-2 text-base font-semibold"><ShieldCheck size={18} className="text-olive" aria-hidden="true" />Account role</h2><p className="mt-2 text-xs leading-6 text-brown">Only an administrator can change their own role.</p></div><div className="flex flex-col gap-4">{feedback("role")}{account.role === "admin" ? <form onSubmit={(event) => void save(event, "role")} className="flex flex-col gap-4"><Field label="Your role" name="settings-role" error={fields("role").role}><select id="settings-role" value={role} onChange={(event) => setRole(event.target.value)} disabled={busy !== null} className={productInput}><option value="admin">Administrator</option><option value="staff">Staff</option></select></Field>{role !== account.role && <><p className="rounded-xl bg-[#fff6e5] p-3 text-xs leading-6 text-[#815a17]">Switching to Staff removes your administrator access. Another active administrator must remain. You will need an administrator to grant the Admin role again.</p><Field label="Current password to confirm role change" name="role-current-password" error={fields("role").current_password}><PasswordInput id="role-current-password" name="current_password" disabled={busy !== null} autoComplete="current-password" /></Field></>}<div><Button type="submit" variant="outline" loading={busy === "role"} disabled={busy !== null || role === account.role}>Save role</Button></div></form> : <p className="rounded-xl bg-ivory p-4 text-sm leading-6 text-brown">Your role is Staff. Contact an administrator to change your access.</p>}</div></section>
  </div>;
}