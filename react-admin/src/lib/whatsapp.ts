// src/lib/whatsapp.ts
//
// Client-side WhatsApp deep-linking. Business-INITIATED WhatsApp messages
// need Meta-approved templates and a server integration — deliberately out
// of scope. A wa.me link opens the staff member's own WhatsApp with the
// message prefilled, so a human presses send: no template, no API, no risk.

/** Turn a local Kenyan number into a wa.me number (07… → 2547…). */
export function waNumber(p?: string | null): string | null {
    if (!p) return null;
    let d = p.replace(/[^\d]/g, "");
    if (d.startsWith("0")) d = "254" + d.slice(1);
    else if (d.length === 9) d = "254" + d;
    return d.length >= 11 ? d : null;
}

/** Open WhatsApp to `phone` with `message` prefilled. False if the number is unusable. */
export function openWhatsApp(phone: string | null | undefined, message: string): boolean {
    const num = waNumber(phone);
    if (!num) return false;
    window.open(`https://wa.me/${num}?text=${encodeURIComponent(message)}`, "_blank");
    return true;
}
