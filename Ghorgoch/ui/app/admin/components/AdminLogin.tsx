"use client";

import content from "@/data/admin/login.json";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { ArrowLeft, ArrowRight, Eye, EyeOff, LockKeyhole, ShieldCheck } from "lucide-react";
import Button from "@/components/atoms/Button";
import Logo from "@/components/atoms/Logo";
import { useAdminAuth } from "@/admin/services/useAdminAuth";

export default function AdminLogin() {
  const { login: signIn } = useAdminAuth();
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) { return; }
    const data = new FormData(event.currentTarget);
    setBusy(true);
    setError("");
    try {
      await signIn(String(data.get("email")).trim(), String(data.get("password")));
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : content.unableToSignInPleaseTry);
    } finally {
      setBusy(false);
    }
  }

  const inputClass = "min-h-12 w-full rounded-card border border-cocoa/20 bg-ivory px-4 py-3 text-base text-cocoa placeholder:text-brown/60 focus:border-olive focus:outline-2 focus:outline-offset-2 focus:outline-olive";

  return <section className="mx-auto grid w-full max-w-4xl overflow-hidden rounded-2xl border border-cocoa/10 bg-ivory md:grid-cols-2" aria-labelledby="admin-login-title">
    <div className="flex flex-col justify-between gap-10 bg-olive p-7 text-ivory sm:p-10">
      <div className="flex items-center gap-2 text-sm"><ShieldCheck size={20} aria-hidden="true" /> {content.ghorgochAdministration}</div>
      <div className="flex flex-col gap-4">
        <span className="flex size-14 items-center justify-center rounded-2xl border border-ivory/25"><LockKeyhole size={26} strokeWidth={1.5} aria-hidden="true" /></span>
        <p className="font-heading text-3xl leading-tight sm:text-4xl">{content.aLittleCare}<br />{content.aBetterStore}</p>
        <p className="max-w-xs text-sm leading-relaxed text-ivory/80">{content.yourSpaceToManageProductsUpdateCollections}</p>
      </div>
      <p className="text-xs text-ivory/75">{content.forAuthorizedStaffMembers}</p>
    </div>
    <div className="flex flex-col gap-7 p-7 sm:p-10">
      <Link href="/" className="self-start" aria-label={content.ghorgochHome}><Logo /></Link>
      <div className="flex flex-col gap-2">
        <p className="text-xs font-medium uppercase tracking-widest text-olive">{content.welcomeBack}</p>
        <h1 id="admin-login-title" className="font-heading text-3xl text-cocoa">{content.adminSignIn}</h1>
        <p className="text-sm leading-relaxed text-brown">{content.enterYourStaffCredentialsToContinue}</p>
      </div>
      <form onSubmit={login} aria-busy={busy} className="flex flex-col gap-5">
        {error && <p id="admin-login-error" role="alert" className="rounded-card border border-cocoa/20 bg-rose/30 p-3 text-sm text-cocoa">{error}</p>}
        <fieldset disabled={busy} className="flex min-w-0 flex-col gap-5 border-0 p-0">
          <div className="flex flex-col gap-2">
            <label htmlFor="admin-email" className="text-sm font-medium text-cocoa">{content.emailAddress}</label>
            <input id="admin-email" name="email" type="email" autoComplete="username" autoCapitalize="none" spellCheck={false} placeholder={content.youExampleCom} required className={inputClass} aria-describedby={error ? "admin-login-error" : undefined} />
          </div>
          <div className="flex flex-col gap-2">
            <label htmlFor="admin-password" className="text-sm font-medium text-cocoa">{content.password}</label>
            <div className="relative">
              <input id="admin-password" name="password" type={showPassword ? "text" : "password"} autoComplete="current-password" placeholder={content.enterYourPassword} required className={`${inputClass} pr-14`} aria-describedby={error ? "admin-login-error" : undefined} />
              <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? content.hidePassword : content.showPassword} aria-pressed={showPassword} aria-controls="admin-password" className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-card text-brown focus-visible:outline-2 focus-visible:outline-olive">
                {showPassword ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}
              </button>
            </div>
          </div>
          <Button type="submit" fullWidth loading={busy} loadingLabel={content.loadingLabel} endIcon={<ArrowRight size={17} />}>{content.signIn}</Button>
        </fieldset>
      </form>
      <Link href="/" className="inline-flex min-h-11 items-center gap-2 self-start text-sm text-brown hover:text-cocoa"><ArrowLeft size={16} aria-hidden="true" /> {content.backToStore}</Link>
    </div>
  </section>;
}
