"use client";

import { useEffect, useRef, useState } from "react";
import { Camera, Check } from "lucide-react";
import Button from "@/components/atoms/Button";
import { useAdminAuth, type AdminUser } from "@/admin/services/useAdminAuth";
import { productPanel } from "./ProductFields";
import ProfileAvatar from "./ProfileAvatar";

export default function ProfilePhotoSettings({ user, disabled, onUpdated, onBusyChange }: {
  user: AdminUser; disabled: boolean; onUpdated: (user: AdminUser & { role: "admin" | "staff" }) => void; onBusyChange: (busy: boolean) => void;
}) {
  const { request, updateUser } = useAdminAuth();
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [saving, setSaving] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const locked = useRef(false);
  useEffect(() => {
    if (!file) { setPreview(""); return; }
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  async function save(remove: boolean, selectedFile: File | null = file) {
    if (locked.current || disabled || (!remove && !selectedFile)) { return; }
    locked.current = true; setSaving(true); onBusyChange(true); setError(""); setNotice("");
    try {
      const body = new FormData();
      if (selectedFile && !remove) { body.set("avatar", selectedFile); }
      const data = await request<{ user: AdminUser & { role: "admin" | "staff" } }>("/users/me/avatar", { method: remove ? "DELETE" : "POST", ...(remove ? {} : { body }) });
      updateUser(data.user); onUpdated(data.user); setFile(null);
      if (input.current) { input.current.value = ""; }
      setNotice(remove ? "Default avatar restored." : "Profile photo updated.");
    } catch (failure) { setError(failure instanceof Error ? failure.message : "Unable to update your photo."); }
    finally { locked.current = false; setSaving(false); onBusyChange(false); }
  }

  return <section className={`${productPanel} grid gap-6 md:grid-cols-[13rem_1fr]`}>
    <div><h2 className="flex items-center gap-2 text-base font-semibold"><Camera size={18} className="text-olive" aria-hidden="true" />Profile photo</h2><p className="mt-2 text-xs leading-6 text-brown">Choose a photo to upload and save it automatically, or use the default avatar.</p></div>
    <div className="flex flex-col gap-4">
      {notice && <p role="status" className="flex items-center gap-2 rounded-xl bg-olive/8 p-3 text-sm text-olive"><Check size={17} aria-hidden="true" />{notice}</p>}
      {error && <p role="alert" className="rounded-xl bg-[#9b3528]/5 p-3 text-sm leading-6 text-[#9b3528]">{error}</p>}
      <div className="flex flex-wrap items-center gap-5"><ProfileAvatar large user={{ ...user, avatar_url: preview || user.avatar_url }} /><div className="min-w-0 flex-1"><label htmlFor="profile-photo" className="mb-2 block text-xs font-semibold text-brown">Choose profile photo</label><input ref={input} id="profile-photo" type="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" disabled={disabled} aria-describedby="profile-photo-help" className="block w-full text-xs text-brown file:mr-3 file:min-h-11 file:rounded-xl file:border file:border-cocoa/15 file:bg-ivory file:px-3 file:text-xs file:font-medium file:text-cocoa focus-visible:outline-2 focus-visible:outline-olive" onChange={(event) => {
        setError(""); setNotice("");
        const chosen = event.target.files?.[0] || null;
        if (chosen && (chosen.size > 2 * 1024 * 1024 || !/\.(png|jpe?g)$/i.test(chosen.name) || !["image/png", "image/jpeg"].includes(chosen.type))) {
          setError("Choose a PNG, JPG, or JPEG image no larger than 2 MB."); setFile(null); event.target.value = ""; return;
        }
        setFile(chosen);
        if (chosen) { void save(false, chosen); }
      }} /><p id="profile-photo-help" className="mt-2 text-xs text-brown">PNG, JPG, or JPEG. Maximum 2 MB. Changes save automatically.</p></div></div>
      <div className="flex flex-wrap gap-3">{saving && <p role="status" className="flex min-h-11 items-center text-sm text-brown">Updating profile photo...</p>}{file && !saving && <Button disabled={disabled} onClick={() => void save(false)}>Retry upload</Button>}{file && !saving && <Button variant="ghost" disabled={disabled} onClick={() => { setFile(null); setError(""); if (input.current) { input.current.value = ""; } }}>Cancel selection</Button>}<Button variant="outline" disabled={disabled || !user.avatar_url} onClick={() => void save(true)}>Use default avatar</Button></div>
    </div>
  </section>;
}