import { useState } from "react";

/**
 * The account's recovery codes, shown once (Phase 4C). Each works one time at
 * the sign-in code step if the authenticator is lost. The server keeps only a
 * hash; once this panel closes they cannot be shown again.
 */
export function RecoveryCodes({ codes, onDone, doneLabel = "I’ve saved them — continue" }: {
    codes: string[];
    onDone: () => void;
    doneLabel?: string;
}) {
    const [saved, setSaved] = useState(false);
    const [copied, setCopied] = useState(false);
    const text = codes.join("\n");

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    const download = () => {
        const blob = new Blob([`Bethany Hub recovery codes\nEach code works once.\n\n${text}\n`], { type: "text/plain" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "bethany-hub-recovery-codes.txt";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-surface-600">
                Keep these somewhere safe, away from your phone. If you lose your authenticator, each code signs you in
                <strong> once</strong>. They will not be shown again.
            </p>
            <ol className="grid grid-cols-2 gap-2 rounded-lg bg-surface-50 p-3 font-mono text-sm text-surface-800">
                {codes.map((c) => <li key={c} className="tracking-wider">{c}</li>)}
            </ol>
            <div className="flex gap-2">
                <button type="button" onClick={copy} className="btn-secondary btn-sm">{copied ? "Copied" : "Copy"}</button>
                <button type="button" onClick={download} className="btn-secondary btn-sm">Download</button>
            </div>
            <label className="flex items-center gap-2 text-sm text-surface-700 cursor-pointer select-none">
                <input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} className="w-4 h-4 accent-brand-500" />
                I have saved these codes
            </label>
            <button type="button" onClick={onDone} disabled={!saved} className="btn-primary w-full h-10">{doneLabel}</button>
        </div>
    );
}
