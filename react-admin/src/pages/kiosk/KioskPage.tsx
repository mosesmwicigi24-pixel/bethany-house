import { useCallback, useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { clsx } from "clsx";
import { kioskApi, kioskToken, KioskError, type KioskPerson } from "@/api/people-c2";

/**
 * /kiosk — the clock-in tablet at the door (People C2).
 *
 * No sign-in screen: the page runs on the kiosk token a manager minted from
 * Attendance › Kiosks (the token reaches only /kiosk/* on the server). Tap
 * your name, type your terminal PIN, the camera takes one picture, done.
 * Big targets, no scrolling on a 10" tablet, nothing of the hub to wander
 * into. Without a token the page says how to set the tablet up.
 */
export default function KioskPage() {
    const qc = useQueryClient();
    const [token, setToken] = useState(kioskToken.get());
    const [picked, setPicked] = useState<KioskPerson | null>(null);
    const [pin, setPin] = useState("");
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState<{ ok: boolean; text: string } | null>(null);
    const [clock, setClock] = useState(new Date());
    const [leaving, setLeaving] = useState(false);
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [camera, setCamera] = useState<"off" | "on" | "denied">("off");
    const doneTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const show = (d: { ok: boolean; text: string }) => {
        setDone(d);
        if (doneTimer.current) clearTimeout(doneTimer.current);
        doneTimer.current = setTimeout(() => setDone(null), 6000);
    };

    const board = useQuery({
        queryKey: ["kiosk-board"],
        queryFn: () => kioskApi.board(),
        enabled: !!token,
        refetchInterval: 30_000,
        retry: (n, e) => !(e instanceof KioskError && (e.status === 401 || e.status === 403)) && n < 2,
    });

    useEffect(() => { const t = setInterval(() => setClock(new Date()), 10_000); return () => clearInterval(t); }, []);

    // A kiosk whose token was removed on the hub goes back to the set-up screen.
    useEffect(() => {
        if (board.error instanceof KioskError && board.error.status === 401) { kioskToken.remove(); setToken(null); }
    }, [board.error]);

    // The camera runs only while someone is at the PIN pad.
    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach(t => t.stop());
        streamRef.current = null;
        setCamera("off");
    }, []);
    useEffect(() => {
        if (!picked) { stopCamera(); return; }
        let cancelled = false;
        (async () => {
            try {
                const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
                if (cancelled) { s.getTracks().forEach(t => t.stop()); return; }
                streamRef.current = s;
                if (videoRef.current) { videoRef.current.srcObject = s; await videoRef.current.play().catch(() => undefined); }
                setCamera("on");
            } catch {
                setCamera("denied");
            }
        })();
        return () => { cancelled = true; stopCamera(); };
    }, [picked, stopCamera]);

    const snapshot = (): string | null => {
        const v = videoRef.current;
        if (!v || camera !== "on" || !v.videoWidth) return null;
        const c = document.createElement("canvas");
        const w = 480, h = Math.round((v.videoHeight / v.videoWidth) * 480) || 360;
        c.width = w; c.height = h;
        c.getContext("2d")?.drawImage(v, 0, 0, w, h);
        return c.toDataURL("image/jpeg", 0.7);
    };

    const reset = () => { setPicked(null); setPin(""); };

    const submit = async (action: "in" | "out") => {
        if (!picked || pin.length < 4 || busy) return;
        setBusy(true);
        try {
            const r = await kioskApi.punch({ user_id: picked.id, pin, action, photo: snapshot() });
            show({ ok: true, text: r.message });
            reset();
            qc.invalidateQueries({ queryKey: ["kiosk-board"] });
        } catch (e) {
            show({ ok: false, text: e instanceof Error ? e.message : "Something went wrong." });
            setPin("");
        } finally {
            setBusy(false);
        }
    };

    const leave = async () => {
        try { await kioskApi.leave(); } catch { /* the token may already be gone */ }
        kioskToken.remove(); setToken(null); setLeaving(false);
    };

    if (!token) {
        return (
            <div className="min-h-screen bg-surface-900 text-white flex items-center justify-center p-8">
                <div className="max-w-md text-center space-y-4">
                    <div className="text-5xl">🕒</div>
                    <h1 className="text-2xl font-bold">This tablet is not a kiosk yet</h1>
                    <p className="text-surface-300 text-sm leading-relaxed">A manager sets it up: sign in to the Hub on this tablet, open <b>People › Attendance</b>, and under <b>Kiosks</b> choose <b>Make this tablet a kiosk</b>. The Hub signs the manager out of the tablet and brings this screen back.</p>
                    <Link to="/login" className="btn-primary inline-flex">Sign in as a manager</Link>
                </div>
            </div>
        );
    }

    const people = board.data?.people ?? [];
    const inNow = people.filter(p => p.state !== "out").length;

    return (
        <div className="min-h-screen bg-surface-900 text-white flex flex-col select-none">
            <header className="flex items-center justify-between px-6 py-4">
                <div>
                    <h1 className="text-xl font-bold">{board.data?.kiosk.outlet ?? "Bethany House"}</h1>
                    <p className="text-surface-400 text-xs">{board.data?.kiosk.name ?? "Kiosk"} · {inNow} clocked in</p>
                </div>
                <div className="text-right">
                    <p className="text-3xl font-bold tabular-nums">{clock.toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" })}</p>
                    <p className="text-surface-400 text-xs">{clock.toLocaleDateString("en-KE", { weekday: "long", day: "numeric", month: "long" })}</p>
                </div>
            </header>

            {done && (
                <div className={clsx("mx-6 mb-3 rounded-2xl px-5 py-4 text-lg font-semibold text-center", done.ok ? "bg-success text-white" : "bg-danger text-white")} role="status">{done.text}</div>
            )}

            {!picked ? (
                <main className="flex-1 px-6 pb-6">
                    {board.isLoading && <p className="text-surface-400 text-center py-10">Loading the team…</p>}
                    {board.error && !board.isLoading && <p className="text-danger text-center py-10">{(board.error as Error).message}</p>}
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        {people.map(p => (
                            <button key={p.id} type="button" onClick={() => { setPicked(p); setPin(""); }}
                                className={clsx("rounded-2xl p-4 text-left transition active:scale-[0.98] min-h-[88px] flex items-center gap-3",
                                    p.state === "out" ? "bg-surface-800 hover:bg-surface-700" : "bg-emerald-700/70 hover:bg-emerald-700")}>
                                <span className={clsx("w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold shrink-0", p.state === "out" ? "bg-surface-600" : "bg-emerald-500")}>{p.initials}</span>
                                <span className="min-w-0">
                                    <span className="block font-semibold truncate">{p.name}</span>
                                    <span className="block text-xs text-surface-300">
                                        {p.state === "out" ? "Tap to clock in" : p.state === "break" ? "On break" : `In since ${p.since ? new Date(p.since).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }) : ""}`}
                                        {p.elsewhere ? ` · at ${p.elsewhere}` : ""}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                    {!board.isLoading && people.length === 0 && !board.error && <p className="text-surface-400 text-center py-10">Nobody is on this outlet's team yet.</p>}
                </main>
            ) : (
                <main className="flex-1 px-6 pb-6 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                    <section className="space-y-3">
                        <button type="button" onClick={reset} className="text-surface-300 text-sm">← Not {picked.name.split(" ")[0]}? Go back</button>
                        <h2 className="text-2xl font-bold">{picked.name}</h2>
                        <p className="text-surface-300 text-sm">
                            {picked.state === "out" ? "Enter your PIN to clock in." : "Enter your PIN to clock out."}
                            {!picked.has_pin && <span className="block text-amber-300 mt-1">You have no PIN yet. Set one under Profile › Security on your own phone or computer, then come back.</span>}
                        </p>
                        <div className="flex justify-center gap-3 py-2" aria-label="PIN">
                            {[0, 1, 2, 3, 4, 5].map(i => <span key={i} className={clsx("w-4 h-4 rounded-full", i < pin.length ? "bg-white" : i < 4 ? "bg-surface-600" : "bg-surface-800")} />)}
                        </div>
                        <div className="grid grid-cols-3 gap-2 max-w-xs mx-auto">
                            {["1", "2", "3", "4", "5", "6", "7", "8", "9", "⌫", "0", "OK"].map(k => (
                                <button key={k} type="button" disabled={busy}
                                    onClick={() => {
                                        if (k === "⌫") setPin(p => p.slice(0, -1));
                                        else if (k === "OK") submit(picked.state === "out" ? "in" : "out");
                                        else if (pin.length < 6) setPin(p => p + k);
                                    }}
                                    className={clsx("h-16 rounded-2xl text-2xl font-semibold active:scale-95 transition",
                                        k === "OK" ? (pin.length >= 4 ? "bg-brand-600" : "bg-surface-800 text-surface-500") : "bg-surface-800 hover:bg-surface-700")}>
                                    {k === "OK" ? (busy ? "…" : picked.state === "out" ? "In" : "Out") : k}
                                </button>
                            ))}
                        </div>
                    </section>
                    <section className="space-y-2">
                        <div className="rounded-2xl overflow-hidden bg-black aspect-[4/3] flex items-center justify-center">
                            <video ref={videoRef} playsInline muted className={clsx("w-full h-full object-cover", camera !== "on" && "hidden")} />
                            {camera === "off" && <p className="text-surface-400 text-sm">Starting the camera…</p>}
                            {camera === "denied" && <p className="text-surface-400 text-sm text-center px-6">No camera. The punch is recorded without a photo.</p>}
                        </div>
                        <p className="text-surface-400 text-xs">One picture is taken when you press {picked.state === "out" ? "In" : "Out"}. Your manager can see it on the punch; it is deleted with the shift trail.</p>
                    </section>
                </main>
            )}

            <footer className="px-6 py-3 flex items-center justify-between text-xs text-surface-500">
                <span>Bethany House Hub · clock-in kiosk</span>
                {leaving ? (
                    <span className="flex items-center gap-2">Leave kiosk mode? <button type="button" onClick={leave} className="text-danger font-semibold">Yes, leave</button><button type="button" onClick={() => setLeaving(false)}>No</button></span>
                ) : (
                    <button type="button" onClick={() => setLeaving(true)} className="hover:text-surface-300">Leave kiosk mode</button>
                )}
            </footer>
        </div>
    );
}
