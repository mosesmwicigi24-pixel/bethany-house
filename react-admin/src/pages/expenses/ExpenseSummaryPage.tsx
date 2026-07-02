// src/pages/expenses/ExpenseSummaryPage.tsx
// Analytics dashboard for the expense module.
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { expensesApi, fmtKes } from '@/api/expenses'
import { Spinner } from '@/components/ui/Spinner'
import {
  BarChart, Bar, LineChart, Line, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import { clsx } from 'clsx'
import dayjs from 'dayjs'

const CHART_COLORS = ['#6366F1', '#8B5CF6', '#EC4899', '#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#64748B']

type Preset = 'this_month' | 'last_month' | 'this_quarter' | 'this_year' | 'custom'

function getRange(preset: Preset, custom: { start: string; end: string }): { start: string; end: string } {
  const now = dayjs()
  switch (preset) {
    case 'this_month':   return { start: now.startOf('month').format('YYYY-MM-DD'), end: now.endOf('month').format('YYYY-MM-DD') }
    case 'last_month':   return { start: now.subtract(1, 'month').startOf('month').format('YYYY-MM-DD'), end: now.subtract(1, 'month').endOf('month').format('YYYY-MM-DD') }
    case 'this_quarter': return { start: now.startOf('quarter').format('YYYY-MM-DD'), end: now.endOf('quarter').format('YYYY-MM-DD') }
    case 'this_year':    return { start: now.startOf('year').format('YYYY-MM-DD'), end: now.endOf('year').format('YYYY-MM-DD') }
    default:             return custom
  }
}

function KpiCard({ label, value, sub, color = '' }: { label: string; value: string | number; sub?: string; color?: string }) {
  return (
    <div className="card card-body flex flex-col gap-1">
      <p className="text-xs text-surface-500">{label}</p>
      <p className={clsx('text-xl font-bold tabular-nums', color || 'text-surface-900')}>{value}</p>
      {sub && <p className="text-xs text-surface-400 mt-0.5">{sub}</p>}
    </div>
  )
}

function SectionCard({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <div className="card p-5">
      <div className="flex flex-col gap-2 mb-4 sm:flex-row sm:items-center sm:justify-between">
        <h3 className="font-semibold text-surface-900">{title}</h3>
        {action}
      </div>
      {children}
    </div>
  )
}

export default function ExpenseSummaryPage() {
  const [preset,      setPreset]      = useState<Preset>('this_month')
  const [customStart, setCustomStart] = useState(dayjs().startOf('month').format('YYYY-MM-DD'))
  const [customEnd,   setCustomEnd]   = useState(dayjs().endOf('month').format('YYYY-MM-DD'))

  const range = getRange(preset, { start: customStart, end: customEnd })

  const { data, isLoading } = useQuery({
    queryKey: ['expense-summary', range.start, range.end],
    queryFn:  () => expensesApi.summary({ start_date: range.start, end_date: range.end }),
  })

  const totals       = data?.totals          ?? {}
  const byCategory   = data?.by_category     ?? []
  const trend        = (data?.monthly_trend ?? []).map((m: any) => ({ ...m, total: Number(m.total) }))
  const pending      = data?.pending         ?? {}
  const byPayment    = data?.by_payment_method ?? []
  const pmTotal      = byPayment.reduce((s: number, x: any) => s + Number(x.total), 0)

  return (
    <div className="space-y-5 animate-fade-in">

      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
        <div>
          <h1 className="page-title">Expense Analytics</h1>
          <p className="page-subtitle">Spending overview and trend analysis.</p>
        </div>

        {/* Date range */}
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
          <select className="input w-full text-sm sm:w-36" value={preset}
            onChange={e => setPreset(e.target.value as Preset)}>
            <option value="this_month">This Month</option>
            <option value="last_month">Last Month</option>
            <option value="this_quarter">This Quarter</option>
            <option value="this_year">This Year</option>
            <option value="custom">Custom</option>
          </select>
          {preset === 'custom' && (
            <div className="flex items-center gap-2 flex-wrap">
              <input type="date" className="input flex-1 text-sm sm:w-36 sm:flex-none" value={customStart}
                onChange={e => setCustomStart(e.target.value)} />
              <span className="text-surface-400 text-sm">to</span>
              <input type="date" className="input flex-1 text-sm sm:w-36 sm:flex-none" value={customEnd}
                onChange={e => setCustomEnd(e.target.value)} />
            </div>
          )}
          {preset !== 'custom' && (
            <span className="text-sm text-surface-500 whitespace-nowrap">
              {dayjs(range.start).format('D MMM YYYY')} – {dayjs(range.end).format('D MMM YYYY')}
            </span>
          )}
        </div>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-20"><Spinner /></div>
      ) : (
        <>
          {/* KPI strip */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <KpiCard label="Total Approved"   value={fmtKes(totals.total_amount)}   sub={`${totals.total_count ?? 0} expenses`} />
            <KpiCard label="Paid"             value={fmtKes(totals.paid_amount)}    color="text-success" />
            <KpiCard label="Approved (Unpaid)" value={fmtKes(totals.approved_unpaid_amount)} color="text-info" />
            <KpiCard label="Avg per Expense"  value={fmtKes(totals.avg_amount)} />
          </div>

          {pending.count > 0 && (
            <div className="flex items-center gap-3 px-4 py-3 bg-warning-light border border-warning/20 rounded-xl">
              <svg className="w-5 h-5 text-warning shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p className="text-sm font-medium text-warning-dark">
                {pending.count} expense{pending.count > 1 ? 's' : ''} pending approval - {fmtKes(pending.total)} awaiting review
              </p>
            </div>
          )}

          {/* Trend + Category breakdown */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">

            {/* 12-month trend */}
            <SectionCard title="12-Month Spending Trend">
              {trend.length === 0 ? (
                <p className="text-sm text-surface-400 text-center py-8">No data available.</p>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={trend} barSize={18}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
                    <XAxis dataKey="month" tick={{ fontSize: 10 }} />
                    <YAxis tickFormatter={(v: number) => `${(v / 1000).toFixed(0)}K`} tick={{ fontSize: 10 }} width={40} />
                    <Tooltip formatter={(v) => fmtKes(v as number)} />
                    <Bar dataKey="total" name="Expenses" fill="#6366F1" radius={[3, 3, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </SectionCard>

            {/* By category pie */}
            <SectionCard title="Spend by Category">
              {byCategory.length === 0 ? (
                <p className="text-sm text-surface-400 text-center py-8">No data for this period.</p>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <PieChart>
                    <Pie data={byCategory} dataKey="total" nameKey="name"
                      cx="50%" cy="50%" outerRadius={80} innerRadius={40}
                      label={({ name, percent }: any) => `${name} ${(percent * 100).toFixed(0)}%`}
                      labelLine={false}>
                      {byCategory.map((_: any, i: number) => (
                        <Cell key={i} fill={byCategory[i].color || CHART_COLORS[i % CHART_COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(v) => fmtKes(v as number)} />
                  </PieChart>
                </ResponsiveContainer>
              )}
            </SectionCard>
          </div>

          {/* Category breakdown table */}
          {byCategory.length > 0 && (
            <div className="card overflow-hidden">
              <div className="px-5 pt-5 pb-4">
                <h3 className="font-semibold text-surface-900">Spend by Category</h3>
              </div>
              <div className="overflow-x-auto">
              <table className="w-full min-w-[480px]">
                <thead>
                  <tr className="border-y border-surface-100 bg-surface-50/50">
                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">Category</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">Expenses</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">Total</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider min-w-[120px]">Share</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-50">
                  {byCategory.map((cat: any, i: number) => {
                    const totalAll = byCategory.reduce((s: number, c: any) => s + Number(c.total), 0)
                    const pct = totalAll > 0 ? (Number(cat.total) / totalAll) * 100 : 0
                    return (
                      <tr key={cat.id} className="hover:bg-surface-50/50 transition-colors">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2.5">
                            <span className="w-3 h-3 rounded-full shrink-0"
                              style={{ backgroundColor: cat.color || CHART_COLORS[i % CHART_COLORS.length] }} />
                            <span className="font-medium text-surface-900 text-sm">{cat.name}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right tabular-nums text-sm">{cat.count}</td>
                        <td className="px-4 py-3 text-right font-semibold tabular-nums">{fmtKes(cat.total)}</td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <div className="flex-1 h-1.5 bg-surface-100 rounded-full overflow-hidden">
                              <div className="h-full rounded-full" style={{
                                width: `${pct}%`,
                                backgroundColor: cat.color || CHART_COLORS[i % CHART_COLORS.length],
                              }} />
                            </div>
                            <span className="text-xs text-surface-500 w-8 text-right tabular-nums">{pct.toFixed(0)}%</span>
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
              </div>
            </div>
          )}

          {/* Payment methods */}
          {byPayment.length > 0 && (
            <SectionCard title="Payment Methods">
              <div className="space-y-3">
                {byPayment.map((pm: any, i: number) => {
                  const pct = pmTotal > 0 ? (Number(pm.total) / pmTotal) * 100 : 0
                  return (
                    <div key={pm.payment_method}>
                      <div className="flex justify-between items-center text-sm mb-1.5">
                        <span className="capitalize text-surface-700">{pm.payment_method?.replace(/_/g, ' ')}</span>
                        <div className="text-right">
                          <span className="font-medium tabular-nums">{fmtKes(pm.total)}</span>
                          <span className="text-surface-400 text-xs ml-2">{pm.count} transactions</span>
                        </div>
                      </div>
                      <div className="h-2 bg-surface-100 rounded-full overflow-hidden">
                        <div className="h-full rounded-full transition-all"
                          style={{ width: `${pct}%`, backgroundColor: CHART_COLORS[i % CHART_COLORS.length] }} />
                      </div>
                    </div>
                  )
                })}
              </div>
            </SectionCard>
          )}
        </>
      )}
    </div>
  )
}