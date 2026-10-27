import { Link } from "react-router-dom";
import type { Recognition } from "@/api/people";
import { CardHead, EmptyLine, Icon, fmtDay } from "./fileKit";

/** Recognitions on a person's file (People C3): the last ten shout-outs and the points they carry. */
export function RecognitionsCard({ recognitions, points, self, titleId }: { recognitions: Recognition[]; points: number; self?: boolean; titleId?: string }) {
    return (
        <div className="card" aria-labelledby={titleId}>
            <CardHead icon="star" tone="amber" titleId={titleId} title="Recognitions"
                subtitle={recognitions.length ? `${points} point${points === 1 ? "" : "s"} from shout-outs on the feed` : "Shout-outs from the Updates feed."}
                actions={<Link to="/updates?kind=recognition" className="btn-ghost btn-sm">Feed<Icon name="chevronRight" className="w-3.5 h-3.5" /></Link>} />
            <div className="card-body">
                {recognitions.length === 0 ? (
                    <EmptyLine icon="star" text={self ? "No shout-outs yet. Do something worth one." : "No shout-outs yet. Good work noticed on the feed lands here."}
                        action={!self ? <Link to="/updates?kind=recognition" className="btn-secondary btn-sm"><Icon name="sparkle" className="w-3.5 h-3.5" />Give a shout-out</Link> : undefined} />
                ) : (
                    <ul className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {recognitions.map(r => (
                            <li key={r.id} className="rounded-2xl border border-line p-4 flex gap-3">
                                <span className="w-9 h-9 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center shrink-0"><Icon name="star" className="w-4 h-4" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-surface-800 leading-relaxed">{r.body}</p>
                                    <p className="mt-1 text-xs text-surface-500">
                                        {r.by ?? "Bethany House"}{r.at ? ` · ${fmtDay(r.at)}` : ""}
                                        {r.points ? <span className="ml-1 badge-amber">+{r.points}</span> : null}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
