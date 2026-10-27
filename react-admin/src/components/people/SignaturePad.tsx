import { useEffect, useRef, useState } from "react";

/** A finger-or-mouse signature on a canvas (People C4). onChange gets a PNG data URL, or null when cleared. */
export function SignaturePad({ onChange, height = 140 }: { onChange: (dataUrl: string | null) => void; height?: number }) {
    const ref = useRef<HTMLCanvasElement | null>(null);
    const drawing = useRef(false);
    const [empty, setEmpty] = useState(true);

    useEffect(() => {
        const c = ref.current; if (!c) return;
        const scale = window.devicePixelRatio || 1;
        const w = c.clientWidth;
        c.width = Math.round(w * scale); c.height = Math.round(height * scale);
        const ctx = c.getContext("2d"); if (!ctx) return;
        ctx.scale(scale, scale); ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, w, height);
        ctx.lineWidth = 2; ctx.lineCap = "round"; ctx.lineJoin = "round"; ctx.strokeStyle = "#111";
    }, [height]);

    const pos = (e: React.PointerEvent<HTMLCanvasElement>) => { const r = ref.current!.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top] as const; };
    const down = (e: React.PointerEvent<HTMLCanvasElement>) => { const ctx = ref.current?.getContext("2d"); if (!ctx) return; drawing.current = true; ref.current!.setPointerCapture(e.pointerId); const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); };
    const move = (e: React.PointerEvent<HTMLCanvasElement>) => { if (!drawing.current) return; const ctx = ref.current?.getContext("2d"); if (!ctx) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); if (empty) setEmpty(false); };
    const up = () => { if (!drawing.current) return; drawing.current = false; onChange(ref.current?.toDataURL("image/png") ?? null); };
    const clear = () => { const c = ref.current; const ctx = c?.getContext("2d"); if (!c || !ctx) return; ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, c.width, c.height); setEmpty(true); onChange(null); };

    return (
        <div className="space-y-1">
            <canvas ref={ref} style={{ height, touchAction: "none" }} className="w-full rounded-xl border border-line bg-white" onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={up} onPointerLeave={up} aria-label="Signature" />
            <div className="flex items-center justify-between text-xs text-surface-500"><span>{empty ? "Sign here with your finger" : "Signed"}</span><button type="button" onClick={clear} className="text-brand-600">Clear</button></div>
        </div>
    );
}
