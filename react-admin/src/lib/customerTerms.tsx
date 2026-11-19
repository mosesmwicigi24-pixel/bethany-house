// The terms printed on every customer receipt, in one place so the till
// receipt, the order's receipts and the PDF say the same thing. Mirrors
// App\Support\CustomerTerms on the server.
//
// Repairs and alterations (management notice, 2026-11-19): only for items
// bought from Bethany House, within 14 days of the customer receiving the
// item, subject to assessment; none after that, no exceptions.

export const RECEIPT_TERMS: string[] = [
    "Goods once sold are not exchangeable unless within 7 days with the original receipt.",
    "Repairs and alterations: only for items bought from Bethany House, within 14 days of receiving the item, subject to assessment. None are accepted after 14 days.",
];

/** The thank-you line and the terms, for the 80 mm thermal receipts. */
export function ReceiptTerms({ thanks = "Thank you for shopping at Bethany House!" }: { thanks?: string }) {
    return (
        <div style={{ textAlign: "center", fontSize: "10px", lineHeight: 1.5, margin: "2px 0" }}>
            <p style={{ margin: 0 }}>{thanks}</p>
            <p style={{ margin: "3px 0 0", fontWeight: 700, letterSpacing: "0.04em" }}>TERMS</p>
            {RECEIPT_TERMS.map((t) => (
                <p key={t} style={{ margin: "1px 0 0" }}>{t}</p>
            ))}
        </div>
    );
}
