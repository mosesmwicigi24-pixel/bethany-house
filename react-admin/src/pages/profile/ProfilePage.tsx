import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { profileApi } from '@/api/profile'
import { currenciesApi, languagesApi } from '@/api/setup'
import { useAuthStore } from '@/store/auth.store'
import { useToastStore } from '@/store/toast.store'
import { Modal } from '@/components/ui/Modal'
import { Spinner } from '@/components/ui/Spinner'
import { Field, useFieldAriaProps, Toggle, FieldInput, FieldSelect, FieldTextarea } from '@/components/setup/FormComponents'
import type { ApiError } from '@/types'
import { clsx } from 'clsx'

// ── Schemas ────────────────────────────────────────────────────────────────────

const profileSchema = z.object({
  first_name:          z.string().min(1, 'First name is required'),
  last_name:           z.string().min(1, 'Last name is required'),
  phone:               z.string().optional(),
  preferred_language:  z.string(),
  preferred_currency:  z.string(),
})

const passwordSchema = z.object({
  current_password:      z.string().min(1, 'Current password is required'),
  password:              z.string().min(8, 'Minimum 8 characters'),
  password_confirmation: z.string().min(1, 'Please confirm your password'),
}).refine((d) => d.password === d.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation'],
})

type ProfileFormValues  = z.infer<typeof profileSchema>
type PasswordFormValues = z.infer<typeof passwordSchema>

// ── Action badge colours ────────────────────────────────────────────────────────

function actionMeta(action: string): { color: string; icon: string } {
  if (action.includes('login'))    return { color: 'text-brand-600 bg-brand-50',   icon: '→' }
  if (action.includes('logout'))   return { color: 'text-surface-500 bg-surface-100', icon: '←' }
  if (action.includes('created'))  return { color: 'text-success bg-success-light', icon: '+' }
  if (action.includes('deleted'))  return { color: 'text-danger bg-danger-light',   icon: '×' }
  if (action.includes('updated') || action.includes('settings')) return { color: 'text-info bg-info-light', icon: '✎' }
  if (action.includes('password')) return { color: 'text-warning bg-warning-light', icon: '⚿' }
  if (action.includes('role'))     return { color: 'text-purple-600 bg-purple-50',  icon: '◈' }
  return { color: 'text-surface-500 bg-surface-100', icon: '·' }
}

function actionLabel(action: string): string {
  return action
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1)   return 'Just now'
  if (mins < 60)  return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24)   return `${hrs}h ago`
  const days = Math.floor(hrs / 24)
  if (days < 7)   return `${days}d ago`
  return new Date(dateStr).toLocaleDateString()
}

// ── Tab type ──────────────────────────────────────────────────────────────────

type Tab = 'profile' | 'password' | 'security' | 'sessions' | 'preferences' | 'activity'

// ── Component ──────────────────────────────────────────────────────────────────

export default function ProfilePage() {
  const qc    = useQueryClient()
  const toast = useToastStore()
  const { user: authUser, logout } = useAuthStore()

  const [activeTab, setActiveTab]       = useState<Tab>('profile')
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null)
  const [twoFaModal, setTwoFaModal]     = useState(false)
  const [disableTwoFaModal, setDisable2faModal] = useState(false)
  const [qrData, setQrData]             = useState<{ secret: string; url: string } | null>(null)
  const [twoFaCode, setTwoFaCode]       = useState('')
  const [disable2faPassword, setDisable2faPassword] = useState('')
  const [revokeAllModal, setRevokeAllModal] = useState(false)

  // ── Data ─────────────────────────────────────────────────────────────────────

  const { data: profileData, isLoading } = useQuery({
    queryKey: ['profile'],
    queryFn:  () => profileApi.get(),
  })

  const { data: sessionsData, refetch: refetchSessions } = useQuery({
    queryKey: ['profile-sessions'],
    queryFn:  () => profileApi.sessions(),
    enabled:  activeTab === 'sessions',
  })

  const { data: activityData } = useQuery({
    queryKey: ['profile-activity'],
    queryFn:  () => profileApi.myActivity({ per_page: '20' }),
    enabled:  activeTab === 'activity',
  })

  const { data: currenciesData } = useQuery({
    queryKey: ['currencies'],
    queryFn:  () => currenciesApi.list(),
  })

  const { data: languagesData } = useQuery({
    queryKey: ['languages'],
    queryFn:  () => languagesApi.list(),
  })

  const profile    = profileData?.user
  const sessions   = sessionsData?.data ?? []
  const activities = activityData?.data ?? []
  const currencies = currenciesData?.data?.filter((c) => c.is_active) ?? []
  const languages  = languagesData?.data?.filter((l) => l.is_active) ?? []

  // ── Forms ─────────────────────────────────────────────────────────────────────

  const profileForm = useForm<ProfileFormValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { first_name: '', last_name: '', phone: '', preferred_language: 'en', preferred_currency: 'KES' },
  })

  const passwordForm = useForm<PasswordFormValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  useEffect(() => {
    if (profile) {
      profileForm.reset({
        first_name:         profile.first_name,
        last_name:          profile.last_name,
        phone:              profile.phone ?? '',
        preferred_language: profile.preferred_language ?? 'en',
        preferred_currency: profile.preferred_currency ?? 'KES',
      })
      if ((profile as any).avatar_url) setAvatarPreview((profile as any).avatar_url)
    }
  }, [profile])

  // ── Mutations ─────────────────────────────────────────────────────────────────

  const updateMutation = useMutation({
    mutationFn: (v: ProfileFormValues) => profileApi.update(v),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['profile'] })
      toast.success('Profile updated successfully.')
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const passwordMutation = useMutation({
    mutationFn: (v: PasswordFormValues) => profileApi.changePassword(v),
    onSuccess: () => {
      toast.success('Password changed. Please log in again.')
      passwordForm.reset()
    },
    onError: (err: ApiError) => {
      if (err.errors?.current_password)
        passwordForm.setError('current_password', { message: err.errors.current_password[0] })
      else toast.error(err.message)
    },
  })

  const avatarMutation = useMutation({
    mutationFn: (file: File) => profileApi.uploadAvatar(file),
    onSuccess: (res) => {
      setAvatarPreview(res.url)
      qc.invalidateQueries({ queryKey: ['profile'] })
      toast.success('Avatar updated.')
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const setup2faMutation = useMutation({
    mutationFn: () => profileApi.setup2fa(),
    onSuccess: (res) => {
      setQrData({ secret: res.secret_key, url: res.qr_code_url })
      setTwoFaModal(true)
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const verify2faMutation = useMutation({
    mutationFn: () => profileApi.verify2fa(twoFaCode),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['profile'] })
      toast.success('Two-factor authentication enabled.')
      setTwoFaModal(false)
      setTwoFaCode('')
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const disable2faMutation = useMutation({
    mutationFn: () => profileApi.disable2fa(disable2faPassword),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['profile'] })
      toast.success('Two-factor authentication disabled.')
      setDisable2faModal(false)
      setDisable2faPassword('')
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const revokeSessionMutation = useMutation({
    mutationFn: (tokenId: string) => profileApi.revokeSession(tokenId),
    onSuccess: () => {
      refetchSessions()
      toast.success('Session revoked.')
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  const revokeAllMutation = useMutation({
    mutationFn: () => profileApi.revokeAllSessions(),
    onSuccess: () => {
      toast.success('All other sessions revoked.')
      setRevokeAllModal(false)
      refetchSessions()
    },
    onError: (err: ApiError) => toast.error(err.message),
  })

  // ── Initials ──────────────────────────────────────────────────────────────────

  const initials = profile
    ? `${profile.first_name?.[0] ?? ''}${profile.last_name?.[0] ?? ''}`.toUpperCase()
    : '?'

  const fullName = profile
    ? `${profile.first_name} ${profile.last_name}`.trim()
    : ''

  // ── Tabs config ───────────────────────────────────────────────────────────────

  const tabs: { id: Tab; label: string; icon: string }[] = [
    { id: 'profile',     label: 'Profile',      icon: 'user'       },
    { id: 'password',    label: 'Password',      icon: 'lock'       },
    { id: 'security',    label: '2FA Security',  icon: 'shield'     },
    { id: 'sessions',    label: 'Sessions',      icon: 'monitor'    },
    { id: 'preferences', label: 'Preferences',   icon: 'sliders'    },
    { id: 'activity',    label: 'My Activity',   icon: 'clipboard'  },
  ]

  if (isLoading) return (
    <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>
  )

  return (
    <div className="max-w-4xl animate-fade-in">
      {/* ── Header card ──────────────────────────────────────────────────── */}
      <div className="card mb-6">
        <div className="card-body">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-5">
            {/* Avatar */}
            <div className="relative shrink-0">
              <div className="w-20 h-20 rounded-full bg-brand-500/10 flex items-center justify-center overflow-hidden border-2 border-brand-100">
                {avatarPreview
                  ? <img src={avatarPreview} alt="Avatar" className="w-full h-full object-cover" />
                  : <span className="text-brand-600 text-2xl font-bold">{initials}</span>
                }
              </div>
              <label className="absolute -bottom-1 -right-1 w-7 h-7 bg-white border border-surface-200 rounded-full flex items-center justify-center cursor-pointer shadow-sm hover:bg-surface-50 transition-colors">
                {avatarMutation.isPending ? <Spinner size="xs" /> : <CameraIcon />}
                <input type="file" accept="image/*" className="hidden" onChange={(e) => {
                  const f = e.target.files?.[0]; if (f) avatarMutation.mutate(f)
                }} />
              </label>
            </div>

            {/* Info */}
            <div className="flex-1 min-w-0">
              <h2 className="text-xl font-bold text-surface-900">{fullName}</h2>
              <p className="text-sm text-surface-500">{profile?.email}</p>
              <div className="flex items-center gap-2 mt-1.5 flex-wrap">
                {profile?.roles.map((r) => (
                  <span key={r.id} className="badge badge-info text-2xs">{r.display_name ?? r.name}</span>
                ))}
                {profile?.outlet && (
                  <span className="badge badge-neutral text-2xs">{profile.outlet.name}</span>
                )}
                <span className={`badge text-2xs ${profile?.status === 'active' ? 'badge-success' : 'badge-neutral'}`}>
                  {profile?.status}
                </span>
                {profile?.two_factor_enabled && (
                  <span className="badge text-2xs bg-success-light text-success">2FA On</span>
                )}
              </div>
            </div>

            {/* Last login */}
            <div className="text-right shrink-0 hidden sm:block">
              <p className="text-xs text-surface-400">Last login</p>
              <p className="text-sm font-medium text-surface-700">
                {profile?.last_login_at ? timeAgo(profile.last_login_at) : 'Never'}
              </p>
              {profile?.last_login_ip && (
                <p className="text-xs text-surface-400 font-mono">{profile.last_login_ip}</p>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* ── Tab nav + content ─────────────────────────────────────────────── */}
      <div className="flex flex-col gap-4 sm:flex-row sm:gap-6">
        {/* Tab nav: horizontal scroll on mobile, sidebar on sm+ */}
        <div className="sm:w-44 sm:shrink-0">
          {/* Mobile: horizontal scrollable pill row */}
          <div className="flex gap-1 overflow-x-auto no-scrollbar pb-1 sm:hidden">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={clsx(
                  'flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium whitespace-nowrap shrink-0 transition-colors',
                  activeTab === tab.id
                    ? 'bg-brand-50 text-brand-700'
                    : 'text-surface-500 bg-surface-50',
                )}
              >
                <ProfileTabIcon name={tab.icon} />
                {tab.label}
              </button>
            ))}
          </div>
          {/* Desktop: vertical sidebar */}
          <div className="hidden sm:flex sm:flex-col sm:space-y-0.5">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={clsx(
                  'w-full flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm transition-colors text-left',
                  activeTab === tab.id
                    ? 'bg-brand-50 text-brand-700 font-medium'
                    : 'text-surface-600 hover:bg-surface-50',
                )}
              >
                <ProfileTabIcon name={tab.icon} />
                {tab.label}
              </button>
            ))}
          </div>
        </div>

        {/* Tab content */}
        <div className="flex-1 min-w-0">

          {/* ── PROFILE TAB ───────────────────────────────────────────────── */}
          {activeTab === 'profile' && (
            <form onSubmit={profileForm.handleSubmit((v) => updateMutation.mutate(v))} className="space-y-4">
              <div className="card">
                <div className="card-header">
                  <h3 className="font-semibold text-sm text-surface-900">Personal Information</h3>
                </div>
                <div className="card-body space-y-4">
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="First Name" error={profileForm.formState.errors.first_name?.message} required>
                      <FieldInput className={`input ${profileForm.formState.errors.first_name ? 'input-error' : ''}`} {...profileForm.register('first_name')} />
                    </Field>
                    <Field label="Last Name" error={profileForm.formState.errors.last_name?.message} required>
                      <FieldInput className={`input ${profileForm.formState.errors.last_name ? 'input-error' : ''}`} {...profileForm.register('last_name')} />
                    </Field>
                  </div>
                  <Field label="Email Address" hint="Contact admin to change your email address.">
                    <FieldInput className="input bg-surface-50 text-surface-500 cursor-not-allowed" value={profile?.email ?? ''} disabled />
                  </Field>
                  <Field label="Phone Number">
                    <FieldInput className="input" {...profileForm.register('phone')} placeholder="+254 700 000 000" />
                  </Field>
                </div>
              </div>

              <div className="card">
                <div className="card-header">
                  <h3 className="font-semibold text-sm text-surface-900">Account Details</h3>
                </div>
                <div className="card-body">
                  <dl className="space-y-3">
                    <div className="flex justify-between text-sm">
                      <dt className="text-surface-500">Account ID</dt>
                      <dd className="font-mono text-surface-700 text-xs">{profile?.uuid}</dd>
                    </div>
                    <div className="flex justify-between text-sm">
                      <dt className="text-surface-500">Member since</dt>
                      <dd className="text-surface-700">{profile?.created_at ? new Date(profile.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : '-'}</dd>
                    </div>
                    <div className="flex justify-between text-sm">
                      <dt className="text-surface-500">Email verified</dt>
                      <dd>{profile?.email_verified_at
                        ? <span className="badge badge-success text-2xs">Verified</span>
                        : <span className="badge badge-warning text-2xs">Unverified</span>}
                      </dd>
                    </div>
                    <div className="flex justify-between text-sm">
                      <dt className="text-surface-500">Outlet</dt>
                      <dd className="text-surface-700">{profile?.outlet?.name ?? '-'}</dd>
                    </div>
                  </dl>
                </div>
              </div>

              <div className="flex justify-end">
                <button type="submit" disabled={updateMutation.isPending} className="btn-primary">
                  {updateMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                  Save Profile
                </button>
              </div>
            </form>
          )}

          {/* ── PASSWORD TAB ──────────────────────────────────────────────── */}
          {activeTab === 'password' && (
            <form onSubmit={passwordForm.handleSubmit((v) => passwordMutation.mutate(v))} className="space-y-4">
              <div className="card">
                <div className="card-header">
                  <h3 className="font-semibold text-sm text-surface-900">Change Password</h3>
                </div>
                <div className="card-body space-y-4">
                  <p className="text-xs text-surface-500 bg-surface-50 rounded-lg px-3 py-2.5">
                    After changing your password, all other active sessions will be terminated and you'll need to log in again on those devices.
                  </p>
                  <Field label="Current Password" error={passwordForm.formState.errors.current_password?.message} required>
                    <FieldInput
                      type="password"
                      autoComplete="current-password"
                      className={`input ${passwordForm.formState.errors.current_password ? 'input-error' : ''}`}
                      {...passwordForm.register('current_password')}
                    />
                  </Field>
                  <Field label="New Password" error={passwordForm.formState.errors.password?.message} required hint="Minimum 8 characters">
                    <FieldInput
                      type="password"
                      autoComplete="new-password"
                      className={`input ${passwordForm.formState.errors.password ? 'input-error' : ''}`}
                      {...passwordForm.register('password')}
                    />
                  </Field>
                  <Field label="Confirm New Password" error={passwordForm.formState.errors.password_confirmation?.message} required>
                    <FieldInput
                      type="password"
                      autoComplete="new-password"
                      className={`input ${passwordForm.formState.errors.password_confirmation ? 'input-error' : ''}`}
                      {...passwordForm.register('password_confirmation')}
                    />
                  </Field>
                </div>
              </div>
              <div className="flex justify-end">
                <button type="submit" disabled={passwordMutation.isPending} className="btn-primary">
                  {passwordMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                  Change Password
                </button>
              </div>
            </form>
          )}

          {/* ── SECURITY / 2FA TAB ────────────────────────────────────────── */}
          {activeTab === 'security' && (
            <div className="space-y-4">
              <div className="card">
                <div className="card-header">
                  <h3 className="font-semibold text-sm text-surface-900">Two-Factor Authentication</h3>
                </div>
                <div className="card-body space-y-4">
                  <div className="flex items-start gap-4 p-4 rounded-xl bg-surface-50 border border-surface-100">
                    <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 text-lg ${profile?.two_factor_enabled ? 'bg-success-light' : 'bg-surface-200'}`}>
                      {profile?.two_factor_enabled ? '✓' : <svg className="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>}
                    </div>
                    <div className="flex-1">
                      <p className="text-sm font-semibold text-surface-900">
                        2FA is {profile?.two_factor_enabled ? 'enabled' : 'disabled'}
                      </p>
                      <p className="text-xs text-surface-500 mt-0.5">
                        {profile?.two_factor_enabled
                          ? 'Your account is protected with an authenticator app. You\'ll be asked for a code on each login.'
                          : 'Add an extra layer of security. You\'ll need an authenticator app like Google Authenticator or Authy.'
                        }
                      </p>
                    </div>
                    {profile?.two_factor_enabled
                      ? <button onClick={() => setDisable2faModal(true)} className="btn-secondary btn-sm text-danger shrink-0">Disable 2FA</button>
                      : <button onClick={() => setup2faMutation.mutate()} disabled={setup2faMutation.isPending} className="btn-primary btn-sm shrink-0">
                          {setup2faMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                          Enable 2FA
                        </button>
                    }
                  </div>

                  {/* Security checklist */}
                  <div className="space-y-2">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">Security checklist</p>
                    {[
                      { label: 'Email verified',       done: !!profile?.email_verified_at },
                      { label: '2FA enabled',           done: !!profile?.two_factor_enabled },
                      { label: 'Strong password set',   done: true },
                    ].map((item) => (
                      <div key={item.label} className="flex items-center gap-2.5 text-sm">
                        <span className={`w-5 h-5 rounded-full flex items-center justify-center text-xs shrink-0 ${item.done ? 'bg-success-light text-success' : 'bg-surface-100 text-surface-400'}`}>
                          {item.done ? '✓' : '○'}
                        </span>
                        <span className={item.done ? 'text-surface-700' : 'text-surface-400'}>{item.label}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* ── SESSIONS TAB ──────────────────────────────────────────────── */}
          {activeTab === 'sessions' && (
            <div className="space-y-4">
              <div className="card">
                <div className="card-header flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <h3 className="font-semibold text-sm text-surface-900">Active Sessions</h3>
                  <button onClick={() => setRevokeAllModal(true)} className="btn-secondary btn-sm text-danger text-xs">
                    Revoke all other sessions
                  </button>
                </div>
                <div className="card-body divide-y divide-surface-50">
                  {sessions.length === 0 ? (
                    <p className="text-sm text-surface-400 py-4 text-center">No active sessions found.</p>
                  ) : sessions.map((s) => (
                    <div key={s.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                      <div className="w-9 h-9 rounded-lg bg-surface-100 flex items-center justify-center shrink-0 text-surface-500">
                        <MonitorIcon />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <p className="text-sm font-medium text-surface-800 truncate">{s.agent || 'Unknown device'}</p>
                          {s.is_current && <span className="badge badge-success text-2xs">Current</span>}
                        </div>
                        <p className="text-xs text-surface-400">{s.ip} · {timeAgo(s.last_used)}</p>
                      </div>
                      {!s.is_current && (
                        <button
                          onClick={() => revokeSessionMutation.mutate(s.id)}
                          disabled={revokeSessionMutation.isPending}
                          className="btn-ghost btn-sm text-xs text-danger"
                        >
                          Revoke
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* ── PREFERENCES TAB ───────────────────────────────────────────── */}
          {activeTab === 'preferences' && (
            <form onSubmit={profileForm.handleSubmit((v) => updateMutation.mutate(v))} className="space-y-4">
              <div className="card">
                <div className="card-header">
                  <h3 className="font-semibold text-sm text-surface-900">Language & Currency</h3>
                  <p className="text-xs text-surface-400 mt-0.5">These preferences apply to your personal view of the admin panel.</p>
                </div>
                <div className="card-body space-y-4">
                  <Field label="Preferred Language">
                    <FieldSelect className="input" {...profileForm.register('preferred_language')}>
                      {languages.length > 0
                        ? languages.map((l) => (
                            <option key={l.code} value={l.code}>{l.flag} {l.name} ({l.native_name})</option>
                          ))
                        : <>
                            <option value="en">🇬🇧 English</option>
                            <option value="fr">🇫🇷 Français</option>
                            <option value="pt">🇵🇹 Português</option>
                          </>
                      }
                    </FieldSelect>
                  </Field>
                  <Field label="Preferred Currency" hint="Used for displaying amounts in the admin panel.">
                    <FieldSelect className="input" {...profileForm.register('preferred_currency')}>
                      {currencies.length > 0
                        ? currencies.map((c) => (
                            <option key={c.code} value={c.code}>{c.code} - {c.name}</option>
                          ))
                        : <>
                            <option value="KES">KES - Kenyan Shilling</option>
                            <option value="USD">USD - US Dollar</option>
                          </>
                      }
                    </FieldSelect>
                  </Field>
                </div>
              </div>
              <div className="flex justify-end">
                <button type="submit" disabled={updateMutation.isPending} className="btn-primary">
                  {updateMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                  Save Preferences
                </button>
              </div>
            </form>
          )}

          {/* ── ACTIVITY TAB ──────────────────────────────────────────────── */}
          {activeTab === 'activity' && (
            <div className="card">
              <div className="card-header">
                <h3 className="font-semibold text-sm text-surface-900">My Recent Activity</h3>
                <p className="text-xs text-surface-400">Last 20 actions performed by your account.</p>
              </div>
              <div className="card-body">
                {activities.length === 0 ? (
                  <p className="text-sm text-surface-400 py-6 text-center">No activity recorded yet.</p>
                ) : (
                  <div className="space-y-1">
                    {activities.map((entry) => {
                      const meta = actionMeta(entry.action)
                      return (
                        <div key={entry.id} className="flex items-start gap-3 py-2.5 border-b border-surface-50 last:border-0">
                          <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold shrink-0 mt-0.5 ${meta.color}`}>
                            {meta.icon}
                          </span>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm text-surface-800">{entry.description || actionLabel(entry.action)}</p>
                            <div className="flex items-center gap-2 mt-0.5">
                              <span className="text-xs text-surface-400">{timeAgo(entry.created_at)}</span>
                              {entry.ip_address && <span className="text-xs text-surface-300 font-mono">{entry.ip_address}</span>}
                            </div>
                          </div>
                          <span className="badge badge-neutral text-2xs shrink-0">{actionLabel(entry.action)}</span>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
          )}

        </div>
      </div>

      {/* ── 2FA Setup modal ───────────────────────────────────────────────── */}
      <Modal open={twoFaModal} onClose={() => setTwoFaModal(false)} title="Set Up Two-Factor Authentication" size="sm"
        footer={<>
          <button onClick={() => setTwoFaModal(false)} className="btn-secondary btn-sm">Cancel</button>
          <button onClick={() => verify2faMutation.mutate()} disabled={verify2faMutation.isPending || twoFaCode.length < 6} className="btn-primary btn-sm">
            {verify2faMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
            Verify & Enable
          </button>
        </>}
      >
        <div className="space-y-4 text-center">
          <p className="text-sm text-surface-600">Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.)</p>
          {qrData && (
            <div className="flex justify-center">
              <img src={`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(qrData.url)}`} alt="2FA QR Code" className="rounded-xl border border-surface-100" />
            </div>
          )}
          {qrData && (
            <div className="bg-surface-50 rounded-lg px-3 py-2">
              <p className="text-xs text-surface-400 mb-1">Or enter the key manually:</p>
              <p className="font-mono text-sm text-surface-800 tracking-widest">{qrData.secret}</p>
            </div>
          )}
          <Field label="Enter the 6-digit code from your app">
            <FieldInput
              className="input text-center text-xl tracking-widest font-mono"
              placeholder="000000"
              maxLength={6}
              value={twoFaCode}
              onChange={(e) => setTwoFaCode(e.target.value.replace(/\D/g, ''))}
            />
          </Field>
        </div>
      </Modal>

      {/* ── Disable 2FA modal ─────────────────────────────────────────────── */}
      <Modal open={disableTwoFaModal} onClose={() => setDisable2faModal(false)} title="Disable Two-Factor Authentication" size="sm"
        footer={<>
          <button onClick={() => setDisable2faModal(false)} className="btn-secondary btn-sm">Cancel</button>
          <button onClick={() => disable2faMutation.mutate()} disabled={disable2faMutation.isPending} className="btn-danger btn-sm">
            {disable2faMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
            Disable 2FA
          </button>
        </>}
      >
        <div className="space-y-3">
          <p className="text-sm text-surface-600">Enter your current password to confirm you want to disable two-factor authentication.</p>
          <Field label="Current Password">
            <FieldInput type="password" className="input" value={disable2faPassword} onChange={(e) => setDisable2faPassword(e.target.value)} autoComplete="current-password" />
          </Field>
        </div>
      </Modal>

      {/* ── Revoke all sessions modal ─────────────────────────────────────── */}
      <Modal open={revokeAllModal} onClose={() => setRevokeAllModal(false)} title="Revoke All Other Sessions" size="sm"
        footer={<>
          <button onClick={() => setRevokeAllModal(false)} className="btn-secondary btn-sm">Cancel</button>
          <button onClick={() => revokeAllMutation.mutate()} disabled={revokeAllMutation.isPending} className="btn-danger btn-sm">
            {revokeAllMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
            Revoke All
          </button>
        </>}
      >
        <p className="text-sm text-surface-600">This will immediately log out all other devices and browsers. Your current session will remain active.</p>
      </Modal>
    </div>
  )
}

// ── Icons ──────────────────────────────────────────────────────────────────────

const ProfileTabIcon = ({ name }: { name: string }) => {
  const s = { className: "w-4 h-4", fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
  if (name === 'user')      return <svg {...s}><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>;
  if (name === 'lock')      return <svg {...s}><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>;
  if (name === 'shield')    return <svg {...s}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>;
  if (name === 'monitor')   return <svg {...s}><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>;
  if (name === 'sliders')   return <svg {...s}><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>;
  if (name === 'clipboard') return <svg {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
  return null;
};

const CameraIcon  = () => <svg className="w-3.5 h-3.5 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path strokeLinecap="round" strokeLinejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
const MonitorIcon = () => <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>