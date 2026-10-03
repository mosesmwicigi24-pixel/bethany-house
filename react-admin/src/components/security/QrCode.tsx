import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { Spinner } from "@/components/ui/Spinner";

/**
 * An authenticator QR code drawn in the browser. The otpauth:// URI carries the
 * two-step secret, so it never goes to a third-party image service.
 */
export function QrCode({ value, size = 184 }: { value: string; size?: number }) {
    const [src, setSrc] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let alive = true;
        setSrc(null);
        setFailed(false);
        QRCode.toDataURL(value, { width: size, margin: 1, errorCorrectionLevel: "M" })
            .then((url) => { if (alive) setSrc(url); })
            .catch(() => { if (alive) setFailed(true); });
        return () => { alive = false; };
    }, [value, size]);

    if (failed) {
        return <p className="text-xs text-danger">The QR code could not be drawn — enter the key below instead.</p>;
    }
    if (!src) {
        return <div className="flex items-center justify-center" style={{ width: size, height: size }}><Spinner size="md" /></div>;
    }
    return <img src={src} width={size} height={size} alt="Authenticator QR code" className="rounded-xl border border-line" />;
}
