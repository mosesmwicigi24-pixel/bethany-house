import { Link } from "react-router-dom";
import type { Recognition } from "@/api/people";

/** Recognitions on a person's file (People C3): the last ten shout-outs and the points they carry. */
export function RecognitionsCard({ recognitions, points, self }: { recognitions: Recognition[]; points: number; self?: boolean }) {
    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <h3 className="font-semibold text-sm text-surface-900">Recognitions</h3>
                <span className="text-xs text-surface-500">{points} point{points === 1 ? "" : "s"} · <Link to="/updates?kind=recognition" className="text-brand-600">feed ›</Link></span>
            </div>
            <div className="card-body">
                {recognitions.length === 0 ? <p className="text-sm text-surface-500">{self ? "No shout-outs yet. Do something worth one." : "No shout-outs yet."}</p> : (
                    <ul className="divide-y divide-line text-sm">
                        {recognitions.map(r => (
                            <li key={r.id} className="py-2">
                                <p className="text-surface-800">{r.body}</p>
                                <p className="text-xs text-surface-500">{r.by ?? "Bethany House"} · {r.at ? new Date(r.at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : ""}{r.points ? <span className="text-amber-700 font-semibold"> · +{r.points}</span> : null}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
