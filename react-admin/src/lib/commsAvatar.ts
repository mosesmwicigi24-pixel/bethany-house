/** Conversation avatars, shared by the chat list and the floating message notices. */
const AVATAR_GRADIENTS = [
    "from-info-500 to-info-600",
    "from-success-500 to-info-600",
    "from-accent-500 to-accent-600",
    "from-danger-500 to-accent-600",
    "from-amber-500 to-brand-600",
    "from-info-500 to-info-700",
    "from-info-500 to-info-600",
    "from-accent-500 to-accent-600",
];
// Deterministic: the same person is always the same colour, everywhere.
export const avatarGradient = (seed: string) =>
    AVATAR_GRADIENTS[[...seed].reduce((n, ch) => n + ch.charCodeAt(0), 0) % AVATAR_GRADIENTS.length];
export const initialsOf = (name: string) =>
    name.trim().split(/\s+/).slice(0, 2).map(w => w[0]?.toUpperCase() ?? "").join("") || "?";

