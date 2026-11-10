"use client";

import content from "@/data/footer.json";
import Button from "@/components/atoms/Button";
import { useState, type FormEvent } from "react";
import { ArrowRight, ArrowUpRight, Mail, Phone } from "lucide-react";

type ContactDetails = { name: string; email: string; phone: string; message: string };
type FooterProps = {
  links: ReadonlyArray<{ label: string; href: string }>;
  copyrightYear: number;
  onContact?: (details: ContactDetails) => Promise<void>;
};

function Facebook({ size = 17 }: { size?: number }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 22v-9h3l.5-4H14V7c0-1.1.3-2 2-2h2V1.5A24 24 0 0 0 15 1c-3 0-5 1.8-5 5v3H7v4h3v9z" /></svg>;
}
function Instagram({ size = 17 }: { size?: number }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r=".75" fill="currentColor" stroke="none" /></svg>;
}
function WhatsApp({ size = 17 }: { size?: number }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.9 11.9 0 0 0 12.05 0C5.46 0 .1 5.36.1 11.95c0 2.1.55 4.16 1.6 5.97L0 24l6.24-1.64a11.9 11.9 0 0 0 5.8 1.48h.01c6.59 0 11.95-5.36 11.95-11.95 0-3.2-1.24-6.2-3.48-8.41ZM12.05 21.82a9.9 9.9 0 0 1-5.05-1.38l-.36-.21-3.7.97.99-3.61-.24-.37a9.9 9.9 0 0 1-1.52-5.27c0-5.48 4.46-9.94 9.95-9.94a9.87 9.87 0 0 1 7.03 2.91 9.87 9.87 0 0 1 2.91 7.03c0 5.48-4.46 9.94-9.94 9.94Zm5.45-7.45c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.49s1.07 2.89 1.22 3.09c.15.2 2.1 3.21 5.09 4.5.71.3 1.27.48 1.7.62.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35Z" /></svg>;
}
const socialIcons = { Facebook, Instagram, WhatsApp };

export default function Footer({ links, copyrightYear, onContact }: FooterProps) {
  const [status, setStatus] = useState("");
  const [pending, setPending] = useState(false);
  const phoneNumber = content.contactPhone.replace(/[^\d+]/g, "");
  const whatsappUrl = phoneNumber ? `https://wa.me/${phoneNumber.replace(/\D/g, "")}` : "";

  async function sendMessage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const form = event.currentTarget;
    const data = new FormData(form);
    const details = Object.fromEntries(["name", "email", "phone", "message"].map((key) => [key, String(data.get(key) ?? "").trim()])) as ContactDetails;
    if (!onContact) {
      if (content.contactEmail) {
        const body = `নাম: ${details.name}\nইমেইল: ${details.email}\nফোন: ${details.phone}\n\n${details.message}`;
        window.location.href = `mailto:${content.contactEmail}?subject=${encodeURIComponent(`${details.name}-এর বার্তা`)}&body=${encodeURIComponent(body)}`;
        setStatus("আপনার ইমেইল অ্যাপ খুলবে। বার্তাটি পাঠাতে সেখান থেকে পাঠানোর বোতামে চাপ দিন।");
      } else {
        setStatus("এখন অনলাইনে বার্তা পাঠানো যাচ্ছে না। আপনার বার্তাটি পাঠানো হয়নি।");
      }
      return;
    }
    setPending(true);
    setStatus("");
    try {
      await onContact(details);
      setStatus("ধন্যবাদ! আপনার বার্তা পাঠানো হয়েছে।");
      form.reset();
    } catch {
      setStatus("আপনার বার্তা পাঠানো যায়নি। অনুগ্রহ করে আবার চেষ্টা করুন।");
    } finally {
      setPending(false);
    }
  }

  return <footer id="contact" className="site-footer page-container">
    <section className="footer-contact" lang="bn" aria-labelledby="footer-contact-title">
      <div className="footer-contact-intro">
        <p className="text-sm font-semibold text-olive">কথা হোক আপনার সঙ্গে</p>
        <h2 id="footer-contact-title" className="font-heading text-3xl leading-snug text-cocoa sm:text-4xl">আপনার ঘর সাজানোর<br className="hidden lg:block" /> সঙ্গী আমরা।</h2>
        <p className="max-w-sm text-sm leading-7 text-brown">পণ্য বা অর্ডার নিয়ে কোনো প্রশ্ন আছে? কিংবা ঘর সাজাতে চাই একটু পরামর্শ? আমাদের লিখুন, আপনার কথা শুনতে আমরা আছি।</p>
        <address className="mt-2 grid w-full gap-5 not-italic" aria-label="যোগাযোগের তথ্য">
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory text-olive"><Phone size={18} strokeWidth={1.5} aria-hidden="true" /></span>
            <div className="min-w-0">
              {phoneNumber ? <a className="inline-flex min-h-touch items-center break-words text-base font-medium text-cocoa underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive sm:text-lg" href={`tel:${phoneNumber}`}>{content.contactPhone}</a> : <p className="text-base text-brown">ফোন নম্বর শীঘ্রই যুক্ত হবে</p>}
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-cocoa/10 bg-ivory text-olive"><Mail size={18} strokeWidth={1.5} aria-hidden="true" /></span>
            <div className="min-w-0">{content.contactEmail ? <a className="inline-flex min-h-touch items-center break-all text-base font-medium text-cocoa underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive sm:text-lg" href={`mailto:${content.contactEmail}`}>{content.contactEmail}</a> : <p className="text-base text-brown">ইমেইল ঠিকানা শীঘ্রই যুক্ত হবে</p>}</div>
          </div>
        </address>
        <div className="mt-3 w-full border-t border-cocoa/10 pt-6">
          <p className="mb-3 text-xs font-semibold text-cocoa">{content.followGhorgoch}</p>
          <nav className="footer-socials" aria-label={content.followGhorgoch}>
            {content.socialLinks.map((link) => {
              const href = link.label === "WhatsApp" ? whatsappUrl || link.href : link.href;
              const Icon = socialIcons[link.label as keyof typeof socialIcons];
              const label = <>{Icon && <Icon size={17} aria-hidden="true" />}<span>{{ Facebook: "ফেসবুক", Instagram: "ইনস্টাগ্রাম", WhatsApp: "হোয়াটসঅ্যাপ" }[link.label] ?? link.label}</span>{href && <ArrowUpRight size={13} aria-hidden="true" />}</>;
              return href ? <a key={link.label} className="footer-social" href={href} target="_blank" rel="noopener noreferrer" aria-label={`${link.label} (নতুন ট্যাবে খুলবে)`}>{label}</a> : <span key={link.label} className="footer-social" aria-disabled="true" title="লিংক শীঘ্রই যুক্ত হবে">{label}<span className="sr-only"> — লিংক শীঘ্রই যুক্ত হবে</span></span>;
            })}
          </nav>
        </div>
      </div>
      <form className="footer-contact-form" onSubmit={sendMessage} aria-busy={pending} aria-describedby="footer-form-note">
        <div><h3 className="text-lg font-semibold text-cocoa">আমাদের বার্তা পাঠান</h3><p id="footer-form-note" className="mt-2 text-xs leading-6 text-brown">সব ঘর পূরণ করুন। আপনার প্রশ্নের উত্তর দিতেই এই তথ্য ব্যবহার করা হবে।</p></div>
        <div className="grid gap-5 sm:grid-cols-2">
          <label className="footer-field" htmlFor="footer-name">নাম<input id="footer-name" name="name" autoComplete="name" placeholder="আপনার পুরো নাম" required maxLength={120} pattern=".*\S.*" disabled={pending} /></label>
          <label className="footer-field" htmlFor="footer-email">ইমেইল<input id="footer-email" name="email" type="email" autoComplete="email" placeholder="আপনার ইমেইল ঠিকানা" required maxLength={254} disabled={pending} /></label>
          <label className="footer-field sm:col-span-2" htmlFor="footer-phone">ফোন নম্বর<input id="footer-phone" name="phone" type="tel" autoComplete="tel" placeholder="আপনার ফোন নম্বর" required maxLength={30} pattern="[+0-9\s().\-]{7,30}" disabled={pending} /></label>
          <label className="footer-field sm:col-span-2" htmlFor="footer-message">বার্তা<textarea id="footer-message" name="message" rows={4} placeholder="কীভাবে সাহায্য করতে পারি?" required maxLength={5000} disabled={pending} /></label>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-xs text-brown">সুন্দর ঘরের গল্প শুরু হোক এখানেই।</p><Button type="submit" className="footer-send-button bg-olive! border-olive! text-ivory! enabled:hover:bg-olive-hover! enabled:hover:opacity-100!" loading={pending} loadingLabel="পাঠানো হচ্ছে…"><span className="footer-send-content"><span>বার্তা পাঠান</span><ArrowRight size={18} strokeWidth={2} aria-hidden="true" /></span></Button></div>
        <p className="footer-status" role="status" aria-live="polite">{status}</p>
      </form>
    </section>
    <div className="footer-bottom">
      <nav aria-label={content.footerNavigation}><ul className="footer-links">{links.map((link) => <li key={link.href}><a className="footer-link" href={link.href}>{link.label}</a></li>)}</ul></nav>
      <p className="footer-credits"><span>{content.pText}{copyrightYear} {content.pText2}</span><span className="footer-credit-divider" aria-hidden="true">·</span><span>Developed by <a href="https://arifuldev.com/" target="_blank" rel="noopener noreferrer" className="font-medium text-cocoa underline-offset-4 hover:text-olive hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-olive" aria-label="arifulDev.com (opens in a new tab)">arifulDev.com</a></span></p>
    </div>
  </footer>;
}
