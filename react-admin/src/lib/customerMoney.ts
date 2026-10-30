/**
 * What a customer is billed is a whole unit — KES 1,000, R3,231, USD 150 —
 * never cents (owner, 2026-10-30). Halves round up. Mirrors
 * App\Support\CustomerMoney on the server, so a figure on screen is the
 * figure the order will carry.
 */
export const whole = (amount: number): number => Math.round(amount);
