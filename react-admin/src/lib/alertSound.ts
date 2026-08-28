/**
 * alertSound.ts — audible + haptic alert for incoming messages.
 *
 * "Someone knows a message has come, not just silent" (owner, 2026-08-28).
 *
 * The chime is synthesised with WebAudio — a two-note marimba-ish figure —
 * so there is no audio asset to precache and nothing for the service worker
 * to version. Browsers block audio until the page has seen a user gesture,
 * so primeAudio() is installed once (pointerdown/keydown) to create and
 * unlock the shared AudioContext; before that first gesture a chime request
 * degrades to vibration only, which is exactly the old behaviour.
 *
 * Vibration uses navigator.vibrate — Android Chrome honours it; iOS Safari
 * has never implemented it and silently ignores the call. iPhones get their
 * sound/haptics from the OS notification banner via Web Push instead, which
 * the service worker already posts when the app is backgrounded.
 */

let ctx: AudioContext | null = null;
let primed = false;

function ensureContext(): AudioContext | null {
    try {
        if (!ctx) {
            const AC = window.AudioContext
                ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
            if (!AC) return null;
            ctx = new AC();
        }
        if (ctx.state === "suspended") void ctx.resume();
        return ctx;
    } catch {
        return null;
    }
}

/**
 * Install once at app start. The first real tap/keypress unlocks audio for
 * the rest of the session; listeners remove themselves after that.
 */
export function primeAudio(): void {
    if (primed) return;
    primed = true;
    const unlock = () => {
        ensureContext();
        window.removeEventListener("pointerdown", unlock);
        window.removeEventListener("keydown", unlock);
    };
    window.addEventListener("pointerdown", unlock, { passive: true });
    window.addEventListener("keydown", unlock);
}

/** One synthesised note. */
function note(ac: AudioContext, freq: number, at: number, dur: number, peak: number): void {
    const osc  = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = "sine";
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0, at);
    gain.gain.linearRampToValueAtTime(peak, at + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + dur);
    osc.connect(gain).connect(ac.destination);
    osc.start(at);
    osc.stop(at + dur + 0.05);
}

/**
 * Play the incoming-message alert: a soft two-note chime plus a short
 * vibration. Safe to call from anywhere; every failure path is silent.
 */
export function messageAlert(): void {
    try {
        navigator.vibrate?.([120, 60, 120]);
    } catch {
        /* unsupported — iOS */
    }
    const ac = ensureContext();
    if (!ac || ac.state !== "running") return;
    const t = ac.currentTime;
    note(ac, 880, t, 0.35, 0.22);        // A5
    note(ac, 1174.66, t + 0.13, 0.45, 0.18); // D6
}
