import React, { createContext, useCallback, useContext, useEffect, useId, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, NavLink, Route, Routes, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import axios from 'axios';
import { Bar, Doughnut, Line } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Tooltip, Legend, Filler } from 'chart.js';
import { useGSAP } from '@gsap/react';
import gsap from 'gsap';
import {
    Activity, AlertTriangle, ArrowDownRight, ArrowUpRight, BadgeCheck, Banknote, Building2,
    Check, ChevronRight, CircleDollarSign, ClipboardCheck, Database, Download, Gauge, HardDrive,
    Eye, EyeOff, LayoutDashboard, LogOut, Menu, Plus, RefreshCw, Server, ShieldCheck, Target, Upload,
    WalletCards, X, Users, KeyRound, Bell,
} from 'lucide-react';
import '../css/app.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Tooltip, Legend, Filler);

const demoMode = String(import.meta.env.VITE_DEMO_MODE ?? 'false') === 'true';

const http = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } });
http.interceptors.request.use((config) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token) config.headers['X-CSRF-TOKEN'] = token;
    return config;
});

const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' },
    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0c1817', padding: 12, cornerRadius: 6 } },
    scales: {
        x: { grid: { display: false }, ticks: { color: '#76827f', font: { family: 'system-ui' } } },
        y: { grid: { color: 'rgba(18, 47, 43, .08)' }, border: { display: false }, ticks: { color: '#76827f', font: { family: 'system-ui' } } },
    },
};

const AuthContext = createContext(null);
const useAuth = () => useContext(AuthContext);
const ToastContext = createContext(null);
const useToast = () => useContext(ToastContext);
const hasPermission = (user, permission) => Boolean(user?.permissions?.[permission]);

function ToastProvider({ children }) {
    const [messages, setMessages] = useState([]);
    const notify = (message, tone = 'success') => {
        const id = crypto.randomUUID();
        setMessages((current) => [...current, { id, message, tone }]);
        window.setTimeout(() => setMessages((current) => current.filter((item) => item.id !== id)), 4500);
    };

    return <ToastContext.Provider value={notify}>{children}<div className="toast-region" aria-live="polite" aria-atomic="true">{messages.map((item) => <div className={`toast ${item.tone}`} key={item.id}>{item.tone === 'success' ? <Check size={17} aria-hidden="true" /> : <AlertTriangle size={17} aria-hidden="true" />}<span>{item.message}</span><button type="button" aria-label="Tutup notifikasi" onClick={() => setMessages((current) => current.filter((message) => message.id !== item.id))}><X size={15} aria-hidden="true" /></button></div>)}</div></ToastContext.Provider>;
}

function requestError(error, fallback) {
    return error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || fallback;
}

function fieldErrors(error) {
    return Object.fromEntries(Object.entries(error.response?.data?.errors || {}).map(([field, messages]) => [field, messages[0]]));
}

function FieldError({ errors, name }) {
    return errors[name] ? <small className="field-error" role="alert">{errors[name]}</small> : null;
}

function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const refreshUser = useCallback(async () => { const { data } = await http.get('/me'); setUser(data.user); return data.user; }, []);
    useEffect(() => { refreshUser().catch(() => setUser(null)).finally(() => setLoading(false)); }, [refreshUser]);
    const login = async (credentials) => { const { data } = await http.post('/login', credentials); setUser(data.user); };
    const logout = async () => { await http.post('/logout'); setUser(null); };
    const changePassword = async (payload) => { const { data } = await http.post('/account/password', payload); setUser(data.user); return data; };
    return <AuthContext.Provider value={{ user, loading, login, logout, refreshUser, changePassword }}>{children}</AuthContext.Provider>;
}

function LoginPage() {
    const { user, login } = useAuth();
    const [form, setForm] = useState({ email: '', password: '' });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [demoAccess, setDemoAccess] = useState({ accounts: [], password: '' });
    const visual = useRef(null);
    useGSAP(() => {
        gsap.from('.login-reveal', { y: 28, opacity: 0, duration: .8, stagger: .08, ease: 'power3.out' });
        gsap.from('.orbit-line', { scale: .8, opacity: 0, duration: 1.4, stagger: .12, ease: 'expo.out' });
    }, { scope: visual });
    useEffect(() => {
        if (!demoMode) return;
        http.get('/demo-access')
            .then(({ data }) => setDemoAccess({ accounts: data.accounts || [], password: data.password || '' }))
            .catch(() => setDemoAccess({ accounts: [], password: '' }));
    }, []);
    if (user) return <Navigate to="/dashboard" replace />;

    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setError('');
        try { await login(form); } catch (e) { setError(e.response?.data?.message || 'Tidak dapat masuk.'); } finally { setBusy(false); }
    };

    return <main className="login-page" ref={visual}>
        <section className="login-story">
            <div className="brand login-reveal"><BrandMark /><span>NADI</span></div>
            <div className="login-copy">
                <p className="eyebrow login-reveal">Decision intelligence untuk LSP Migas</p>
                <h1 className="login-reveal">Satu nadi untuk membaca kesehatan perusahaan.</h1>
                <p className="login-reveal">Kinerja sertifikasi, keuangan, dan teknologi bertemu dalam satu ruang keputusan yang mampu ditelusuri.</p>
            </div>
            <div className="source-strip login-reveal"><ShieldCheck size={18} /> {demoMode ? 'Mode demonstrasi · data sintetis' : 'Sistem operasional terintegrasi · akses terlindungi'}</div>
        </section>
        <section className="login-panel">
            <div className="orbit-field" aria-hidden="true"><i className="orbit-line one" /><i className="orbit-line two" /><i className="orbit-line three" /></div>
            <form className="login-form login-reveal" onSubmit={submit}>
                <div><span className="form-index">Akses sistem</span><h2>Masuk ke ruang kerja</h2><p>{demoMode ? 'Pilih akun demonstrasi atau gunakan kredensial yang tersedia.' : 'Gunakan kredensial perusahaan yang telah diberikan administrator.'}</p></div>
                <label>Email<input type="email" autoComplete="username" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></label>
                <label>Kata sandi<span className="password-field"><input type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required /><button type="button" aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'} onClick={() => setShowPassword((visible) => !visible)}>{showPassword ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}</button></span></label>
                {error && <div className="form-error" role="alert"><AlertTriangle size={16} aria-hidden="true" />{error}</div>}
                <button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? <><RefreshCw className="spin" size={18} aria-hidden="true" />Memeriksa akses</> : <>Masuk ke NADI <ChevronRight size={18} aria-hidden="true" /></>}</button>
                {demoMode && demoAccess.accounts.length > 0 && <><div className="demo-accounts">{demoAccess.accounts.map((account) => <button type="button" key={account.email} onClick={() => setForm({ email: account.email, password: demoAccess.password })}><span>{account.label}</span><small>{account.email}</small></button>)}</div><p className="credential-note">Kata sandi demo: <strong>{demoAccess.password}</strong></p></>}
            </form>
        </section>
    </main>;
}

function BrandMark() {
    return <svg className="brand-mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M5 27h8l4-14 6 21 4-12h8" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /></svg>;
}

const navItems = [
    { to: '/dashboard', label: 'Ikhtisar', icon: LayoutDashboard, module: 'overview', permission: 'overview.view' },
    { to: '/certification', label: 'Sertifikasi', icon: BadgeCheck, module: 'certification', permission: 'certification.view' },
    { to: '/finance', label: 'Keuangan', icon: CircleDollarSign, module: 'finance', permission: 'finance.view' },
    { to: '/it', label: 'Teknologi', icon: Server, module: 'it', permission: 'it.view' },
    { to: '/governance', label: 'Mutu & Kepatuhan', icon: ShieldCheck, module: 'governance', permission: 'governance.view' },
    { to: '/decisions', label: 'Pusat Keputusan', icon: AlertTriangle, module: 'decisions', permission: 'decisions.view' },
    { to: '/reports', label: 'Laporan', icon: ClipboardCheck, module: 'reports', permission: 'reports.view' },
    { to: '/integrations', label: 'Integrasi Data', icon: Upload, module: 'integrations', permission: 'integrations.view' },
    { to: '/kpi-catalog', label: 'Katalog KPI', icon: Target, module: 'kpi-catalog', permission: 'kpi.catalog.view' },
    { to: '/master-data', label: 'Pusat Data', icon: Database, module: 'master-data', anyPermissions: ['master_data.certification.manage', 'master_data.it.manage'] },
    { to: '/admin/users', label: 'Pengguna & Akses', icon: Users, module: 'admin', permission: 'users.manage' },
    { to: '/account', label: 'Akun Saya', icon: KeyRound, module: 'account', publicForUser: true },
];

function canAccessNavItem(user, item) {
    if (item.publicForUser) return true;
    if (item.anyPermissions) return item.anyPermissions.some((permission) => hasPermission(user, permission));
    return hasPermission(user, item.permission);
}

function AppShell() {
    const { user, logout } = useAuth();
    const [mobileOpen, setMobileOpen] = useState(false);
    const location = useLocation();
    const usesGlanceLayout = navItems.some((item) => item.to === location.pathname);
    const accessible = navItems.filter((item) => canAccessNavItem(user, item));
    return <div className={`app-shell ${usesGlanceLayout ? 'dashboard-shell' : ''}`}><a className="skip-link" href="#main-content">Lewati ke konten utama</a>
        <aside className={`sidebar ${mobileOpen ? 'open' : ''}`}>
            <div className="sidebar-top"><div className="brand"><BrandMark /><span>NADI</span></div><button className="icon-button mobile-only" type="button" aria-label="Tutup navigasi" onClick={() => setMobileOpen(false)}><X size={20} aria-hidden="true" /></button></div>
            <nav>{accessible.map((item) => <NavLink key={item.to} to={item.to} onClick={() => setMobileOpen(false)}><item.icon size={19} /><span>{item.label}</span><ChevronRight className="nav-arrow" size={15} /></NavLink>)}</nav>
            <div className="sidebar-foot">
                <div className="source-status"><span className="status-dot" /><div><strong>Data operasional</strong><small>Sinkronisasi otomatis aktif</small></div></div>
                <button className="user-card" onClick={logout}><span className="avatar">{user.name.split(' ').map((x) => x[0]).slice(0, 2).join('')}</span><span><strong>{user.name}</strong><small>{user.position}</small></span><LogOut size={17} /></button>
            </div>
        </aside>
        <div className="workspace">
            <header className="topbar"><button className="icon-button mobile-only" type="button" aria-label="Buka navigasi" onClick={() => setMobileOpen(true)}><Menu size={21} aria-hidden="true" /></button><div className="top-context"><span>LSP Migas</span><i /><span>Monitoring terpadu</span></div><div className="top-meta"><span className="live-indicator"><i /> Sistem aktif</span><time>{new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date())}</time>{!usesGlanceLayout && <NotificationCenter />}</div></header>
            <Routes>
                <Route path="/dashboard" element={<DashboardPage />} />
                <Route path="/certification" element={accessible.some((x) => x.module === 'certification') ? <CertificationPage /> : <Navigate to="/dashboard" />} />
                <Route path="/finance" element={accessible.some((x) => x.module === 'finance') ? <FinancePage /> : <Navigate to="/dashboard" />} />
                <Route path="/it" element={accessible.some((x) => x.module === 'it') ? <ItPage /> : <Navigate to="/dashboard" />} />
                <Route path="/governance" element={accessible.some((x) => x.module === 'governance') ? <GovernancePage /> : <Navigate to="/dashboard" />} />
                <Route path="/decisions" element={accessible.some((x) => x.module === 'decisions') ? <DecisionCenterPage /> : <Navigate to="/dashboard" />} />
                <Route path="/reports" element={accessible.some((x) => x.module === 'reports') ? <ReportingPage /> : <Navigate to="/dashboard" />} />
                <Route path="/integrations" element={accessible.some((x) => x.module === 'integrations') ? <IntegrationPage /> : <Navigate to="/dashboard" />} />
                <Route path="/kpi-catalog" element={accessible.some((x) => x.module === 'kpi-catalog') ? <KpiCatalogPage /> : <Navigate to="/dashboard" />} />
                <Route path="/master-data" element={accessible.some((x) => x.module === 'master-data') ? <MasterDataPage /> : <Navigate to="/dashboard" />} />
                <Route path="/admin/users" element={accessible.some((x) => x.module === 'admin') ? <UserAdminPage /> : <Navigate to="/dashboard" />} />
                <Route path="/account" element={<AccountPage />} />
                <Route path="*" element={<Navigate to="/dashboard" replace />} />
            </Routes>
        </div>
    </div>;
}

function Page({ children, loading = false }) {
    const scope = useRef(null);
    useGSAP(() => {
        if (!loading) {
            const targets = gsap.utils.toArray('.animate-in');
            if (targets.length) gsap.from(targets, { y: 22, opacity: 0, duration: .65, stagger: .07, ease: 'power3.out' });
        }
    }, { scope, dependencies: [loading] });
    if (loading) return <main id="main-content" className="page" aria-busy="true" tabIndex="-1"><LoadingState /></main>;
    return <main id="main-content" className="page" ref={scope} tabIndex="-1">{children}</main>;
}

function LoadingState() { return <div className="loading-state" role="status"><RefreshCw className="spin" aria-hidden="true" /><p>Menghitung data operasional</p></div>; }
function ErrorState({ message, retry }) { return <div className="error-state" role="alert"><AlertTriangle aria-hidden="true" /><h2>Data belum dapat dimuat</h2><p>{message}</p><button className="secondary-button" onClick={retry}>Coba lagi</button></div>; }

function useRemote(url, refreshInterval = 10000) {
    const [state, setState] = useState({ data: null, loading: true, refreshing: false, error: '', syncError: '', lastUpdated: null });
    const requestId = useRef(0);
    const load = useCallback(async ({ silent = false } = {}) => {
        const currentRequest = ++requestId.current;
        setState((current) => ({ ...current, loading: silent ? current.loading : true, refreshing: silent, error: silent ? current.error : '', syncError: '' }));

        try {
            const { data } = await http.get(url);
            if (currentRequest !== requestId.current) return;
            setState({ data, loading: false, refreshing: false, error: '', syncError: '', lastUpdated: new Date() });
        } catch (error) {
            if (currentRequest !== requestId.current) return;
            const message = requestError(error, 'Data belum dapat disinkronkan.');
            setState((current) => current.data
                ? { ...current, loading: false, refreshing: false, syncError: message }
                : { ...current, data: null, loading: false, refreshing: false, error: message });
        }
    }, [url]);

    useEffect(() => {
        load();
        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') load({ silent: true });
        }, refreshInterval);
        const syncWhenVisible = () => {
            if (document.visibilityState === 'visible') load({ silent: true });
        };
        document.addEventListener('visibilitychange', syncWhenVisible);

        return () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', syncWhenVisible);
        };
    }, [load, refreshInterval]);

    return { ...state, reload: () => load({ silent: true }) };
}

function NotificationCenter({ compact = false }) {
    const navigate = useNavigate();
    const notify = useToast();
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState([]);
    const [summary, setSummary] = useState({ unread: 0, unacknowledged_critical: 0 });
    const [connected, setConnected] = useState(false);
    const [loading, setLoading] = useState(true);
    const cursorRef = useRef(0);
    const sourceRef = useRef(null);

    const mergeNotification = useCallback((incoming, announce = false) => {
        setItems((current) => {
            const exists = current.some((item) => item.id === incoming.id);
            if (!exists && announce && !incoming.read_at) {
                setSummary((currentSummary) => ({ ...currentSummary, unread: currentSummary.unread + 1, unacknowledged_critical: currentSummary.unacknowledged_critical + (incoming.severity === 'critical' && !incoming.acknowledged_at ? 1 : 0) }));
            }
            const next = exists ? current.map((item) => item.id === incoming.id ? incoming : item) : [incoming, ...current];
            return next.slice(0, 50);
        });
        cursorRef.current = Math.max(cursorRef.current, Number(incoming.id || 0));
        if (announce && incoming.severity === 'critical') notify(incoming.title, 'error');
    }, [notify]);

    const reload = useCallback(async () => {
        const { data } = await http.get('/notifications?limit=50');
        setItems(data.notifications || []);
        setSummary(data.summary || { unread: 0, unacknowledged_critical: 0 });
        cursorRef.current = Math.max(0, ...(data.notifications || []).map((item) => Number(item.id || 0)));
        setLoading(false);
    }, []);

    useEffect(() => {
        let disposed = false;
        const connect = () => {
            if (disposed || typeof EventSource === 'undefined') return;
            sourceRef.current?.close();
            const source = new EventSource(`/api/notifications/stream?cursor=${cursorRef.current}`);
            sourceRef.current = source;
            source.addEventListener('open', () => !disposed && setConnected(true));
            source.addEventListener('notification', (event) => {
                if (disposed) return;
                try { mergeNotification(JSON.parse(event.data), true); } catch (_) { /* ignore malformed event */ }
            });
            source.addEventListener('error', () => !disposed && setConnected(false));
        };
        reload().catch(() => setLoading(false)).finally(() => { if (!disposed) connect(); });
        const fallback = window.setInterval(() => {
            if (document.visibilityState === 'visible') reload().catch(() => {});
        }, 60000);
        return () => {
            disposed = true;
            window.clearInterval(fallback);
            sourceRef.current?.close();
        };
    }, [mergeNotification, reload]);

    const markRead = async (item) => {
        if (!item.read_at) {
            const { data } = await http.post(`/notifications/${item.id}/read`);
            setItems((current) => current.map((entry) => entry.id === item.id ? data.notification : entry));
            setSummary((current) => ({ ...current, unread: Math.max(0, current.unread - 1) }));
        }
    };
    const acknowledge = async (item) => {
        const wasUnread = !item.read_at;
        const wasCritical = item.severity === 'critical' && !item.acknowledged_at;
        const { data } = await http.post(`/notifications/${item.id}/acknowledge`);
        setItems((current) => current.map((entry) => entry.id === item.id ? data.notification : entry));
        setSummary((current) => ({ unread: Math.max(0, current.unread - (wasUnread ? 1 : 0)), unacknowledged_critical: Math.max(0, current.unacknowledged_critical - (wasCritical ? 1 : 0)) }));
    };
    const markAllRead = async () => {
        await http.post('/notifications/read-all');
        const now = new Date().toISOString();
        setItems((current) => current.map((item) => item.read_at ? item : { ...item, read_at: now }));
        setSummary((current) => ({ ...current, unread: 0 }));
    };
    const openItem = async (item) => {
        try { await markRead(item); } catch (_) { /* navigation remains available */ }
        setOpen(false);
        if (item.action_url) navigate(item.action_url);
    };

    return <div className={`notification-center ${compact ? 'compact' : ''}`}>
        <button type="button" className="notification-trigger" aria-label={`Notifikasi${summary.unread ? `, ${summary.unread} belum dibaca` : ''}`} aria-expanded={open} onClick={() => setOpen((value) => !value)}>
            <Bell size={compact ? 16 : 18} aria-hidden="true" />
            {summary.unread > 0 && <span className="notification-count">{summary.unread > 99 ? '99+' : summary.unread}</span>}
            <i className={connected ? 'connected' : ''} aria-hidden="true" />
        </button>
        {open && <div className="notification-popover" role="dialog" aria-label="Pusat notifikasi">
            <header><div><strong>Notifikasi</strong><small>{connected ? 'Realtime tersambung' : 'Fallback sinkronisasi aktif'}</small></div><button type="button" onClick={markAllRead} disabled={!summary.unread}>Tandai dibaca</button></header>
            <div className="notification-summary"><span>{summary.unread} belum dibaca</span><span className={summary.unacknowledged_critical ? 'critical' : ''}>{summary.unacknowledged_critical} kritis belum diakui</span></div>
            <div className="notification-list">
                {loading && <div className="notification-empty">Memuat notifikasi…</div>}
                {!loading && !items.length && <div className="notification-empty">Belum ada notifikasi operasional.</div>}
                {items.map((item) => <article key={item.id} className={`${item.severity} ${item.read_at ? 'read' : 'unread'}`}>
                    <button className="notification-main" type="button" onClick={() => openItem(item)}>
                        <span className="notification-dot" />
                        <span><strong>{item.title}</strong><small>{item.message}</small><time>{new Date(item.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</time></span>
                    </button>
                    {!item.acknowledged_at && <button type="button" className="notification-ack" onClick={() => acknowledge(item)}>Akui</button>}
                </article>)}
            </div>
        </div>}
    </div>;
}

function LiveDataStatus({ remote }) {
    const label = remote.refreshing
        ? 'Menyinkronkan data'
        : remote.syncError
            ? 'Sinkronisasi tertunda'
            : `Tersinkron ${remote.lastUpdated?.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) || 'sekarang'}`;

    return <div className={`live-data-status ${remote.syncError ? 'delayed' : ''}`} role="status" aria-live="polite" aria-atomic="true"><span className="live-data-dot" aria-hidden="true" /><span>{label}</span><button type="button" aria-label="Segarkan data sekarang" title="Segarkan data" onClick={remote.reload} disabled={remote.refreshing}><RefreshCw className={remote.refreshing ? 'spin' : ''} size={15} aria-hidden="true" /></button></div>;
}

function PageHeader({ eyebrow, title, description, actions }) {
    return <header className="page-header animate-in"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{description}</p></div><div className="header-actions">{actions}<NotificationCenter /></div></header>;
}

function StatusBadge({ status }) {
    const labels = { on_track: 'Sesuai target', watch: 'Perlu dipantau', critical: 'Perlu tindakan', open: 'Terbuka', in_progress: 'Dikerjakan', blocked: 'Terhambat', completed: 'Selesai', operational: 'Operasional', degraded: 'Terdegradasi', maintenance: 'Pemeliharaan', resolved: 'Selesai', investigating: 'Ditangani', low: 'Rendah', medium: 'Sedang', high: 'Tinggi', active: 'Aktif', inactive: 'Nonaktif', expiring: 'Segera habis', expired: 'Kedaluwarsa', received: 'Diterima', reviewing: 'Ditinjau', decided: 'Diputuskan', closed: 'Ditutup', reconciled: 'Terekonsiliasi', exception: 'Pengecualian', pending: 'Menunggu', completed_with_errors: 'Selesai dengan catatan', staged: 'Staged', staged_with_errors: 'Staged · perlu perbaikan', validated: 'Tervalidasi', published: 'Published', published_with_errors: 'Published · ada exception', duplicate: 'Duplikat', skipped: 'Dilewati', valid: 'Valid', invalid: 'Invalid', publish_error: 'Gagal publish', acknowledged: 'Diakui', draft: 'Draft', in_review: 'Dalam review', approved: 'Disetujui', dismissed: 'Dihentikan' };
    return <span className={`status-badge ${status}`}>{labels[status] || status?.replaceAll('_', ' ')}</span>;
}

function MetricCard({ icon: Icon, label, value, unit, change, tone = 'teal' }) {
    return <article className={`metric-card animate-in tone-${tone}`}><div className="metric-head"><span className="metric-icon"><Icon size={20} /></span>{change !== undefined && <span className={change >= 0 ? 'positive' : 'negative'}>{change >= 0 ? <ArrowUpRight size={15} /> : <ArrowDownRight size={15} />}{Math.abs(change)}%</span>}</div><div><strong>{value}</strong>{unit && <span>{unit}</span>}<p>{label}</p></div></article>;
}

const reportingMonths = [
    ['1', 'Jan'], ['2', 'Feb'], ['3', 'Mar'], ['4', 'Apr'], ['5', 'Mei'], ['6', 'Jun'],
    ['7', 'Jul'], ['8', 'Ags'], ['9', 'Sep'], ['10', 'Okt'], ['11', 'Nov'], ['12', 'Des'],
];

function useReportingPeriod() {
    const today = new Date();
    const [searchParams, setSearchParams] = useSearchParams();
    const parsedYear = Number(searchParams.get('year'));
    const parsedMonth = Number(searchParams.get('month'));
    const year = Number.isInteger(parsedYear) && parsedYear >= 2000 && parsedYear <= 2100 ? parsedYear : today.getFullYear();
    const month = Number.isInteger(parsedMonth) && parsedMonth >= 1 && parsedMonth <= 12 ? parsedMonth : today.getMonth() + 1;
    const years = useMemo(() => {
        const available = Array.from({ length: 10 }, (_, index) => today.getFullYear() - index);
        return [...new Set([...available, year])].sort((a, b) => b - a);
    }, [today.getFullYear(), year]);
    const update = (nextYear, nextMonth) => setSearchParams({ year: String(nextYear), month: String(nextMonth) }, { replace: true });

    return { year, month, years, setYear: (value) => update(value, month), setMonth: (value) => update(year, value), query: `year=${year}&month=${month}` };
}

function GlanceHeader({ eyebrow, title, description, period, remote, actions, showPeriod = true }) {
    const { user, logout } = useAuth();
    const notify = useToast();
    const [loggingOut, setLoggingOut] = useState(false);
    const accessible = navItems.filter((item) => canAccessNavItem(user, item));
    const signOut = async () => {
        setLoggingOut(true);
        try {
            await logout();
        } catch (error) {
            notify(requestError(error, 'Sesi belum dapat ditutup. Periksa koneksi lalu coba lagi.'), 'error');
        } finally {
            setLoggingOut(false);
        }
    };

    return <header className="dashboard-head animate-in"><div className="dashboard-title"><span className="dashboard-kicker">{eyebrow}</span><h1>{title} <em>— LSP Migas</em></h1><p>{description}</p><nav className="dashboard-nav" aria-label="Modul utama">{accessible.map((item) => <NavLink key={item.to} to={period?.query ? `${item.to}?${period.query}` : item.to}>{item.label}</NavLink>)}</nav></div><div className="dashboard-controls">{showPeriod && <><label>Pilih tahun<select value={period.year} onChange={(event) => period.setYear(Number(event.target.value))}>{period.years.map((year) => <option key={year} value={year}>{year}</option>)}</select></label><label>Periode<select value={period.month} onChange={(event) => period.setMonth(Number(event.target.value))}>{reportingMonths.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></>}{remote && <LiveDataStatus remote={remote} />}{actions && <div className="dashboard-actions">{actions}</div>}<NotificationCenter compact /><div className="dashboard-user"><span className="avatar small" aria-hidden="true">{user.name.split(' ').map((part) => part[0]).slice(0, 2).join('')}</span><span><strong>{user.name}</strong><small>{user.position}</small></span><button type="button" aria-label="Keluar dari NADI" title="Keluar" onClick={signOut} disabled={loggingOut}>{loggingOut ? <RefreshCw className="spin" size={16} aria-hidden="true" /> : <LogOut size={16} aria-hidden="true" />}</button></div></div></header>;
}

function GlanceRail({ primaryIcon: PrimaryIcon, primaryTitle, primaryDescription, secondaryIcon: SecondaryIcon, secondaryTitle, secondaryDescription }) {
    return <aside className="dashboard-rail animate-in"><div className="rail-mark"><BrandMark /><span>NADI</span></div><div className="rail-block"><PrimaryIcon size={32} /><h2>{primaryTitle}</h2><p>{primaryDescription}</p></div><div className="rail-block rail-block-alt"><SecondaryIcon size={31} /><h2>{secondaryTitle}</h2><p>{secondaryDescription}</p></div><div className="rail-foot"><span className="status-dot" /><span>Data periode terpilih</span></div></aside>;
}

function PrototypeNotice({ period }) {
    const label = period?.window_start && period?.window_end
        ? `${new Date(period.window_start).toLocaleDateString('id-ID', { month: 'short', year: 'numeric' })}–${new Date(period.window_end).toLocaleDateString('id-ID', { month: 'short', year: 'numeric' })}`
        : 'periode berjalan';

    return <div className="prototype-banner dashboard-notice animate-in"><Database size={17} /><span><strong>Visualisasi dihitung dari basis data.</strong> Jendela analisis {label}; data prototipe dapat diganti melalui impor atau integrasi sumber perusahaan.</span></div>;
}

const glanceDonutOptions = { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 10, font: { family: 'Satoshi', size: 10 } } }, tooltip: { backgroundColor: '#2b235e', padding: 10, cornerRadius: 4 } } };

const glanceChartOptions = { ...chartDefaults, plugins: { ...chartDefaults.plugins, tooltip: { ...chartDefaults.plugins.tooltip, backgroundColor: '#2b235e', displayColors: false } }, scales: { x: { ...chartDefaults.scales.x, ticks: { ...chartDefaults.scales.x.ticks, font: { family: 'Satoshi', size: 10 } } }, y: { ...chartDefaults.scales.y, ticks: { ...chartDefaults.scales.y.ticks, font: { family: 'Satoshi', size: 10 } } } } };

function EvidenceBars({ rows }) {
    const maximum = Math.max(1, ...rows.map((row) => Number(row.value)));

    if (! rows.length) return <EmptyState compact title="Belum ada data" description="Pilih periode lain atau masukkan data operasional." />;

    return <div className="evidence-bar-list">{rows.map((row) => <div key={row.label}><div><span>{row.label}</span><strong>{row.display ?? Number(row.value).toLocaleString('id-ID')}</strong></div><i className={row.tone || ''} style={{ width: `${Math.min(100, Number(row.value) / maximum * 100)}%` }} aria-hidden="true" /></div>)}</div>;
}

function EvidenceList({ rows }) {
    if (! rows.length) return <EmptyState compact title="Belum ada data" description="Tidak ada rekaman pada periode terpilih." />;

    return <div className="evidence-list">{rows.map((row) => <div key={row.key || row.title}><span className={`evidence-dot ${row.tone || ''}`} aria-hidden="true" /><div><strong>{row.title}</strong><small>{row.detail}</small></div>{row.trailing}</div>)}</div>;
}

function EmptyState({ title, description, compact = false }) {
    return <div className={`empty-state ${compact ? 'compact' : ''}`}><Database size={compact ? 22 : 30} aria-hidden="true" /><strong>{title}</strong><span>{description}</span></div>;
}

function AccessibleChart({ label, summary, hasData = true, children }) {
    if (! hasData) return <div className="dashboard-chart-wrap"><EmptyState compact title="Belum ada data grafik" description="Grafik akan tampil setelah data tersedia." /></div>;

    return <div className="dashboard-chart-wrap" role="img" aria-label={`${label}. ${summary}`}>{children}</div>;
}

function DashboardPage() {
    const { user } = useAuth();
    const period = useReportingPeriod();
    const remote = useRemote(`/dashboard?${period.query}`);
    const { data, loading, error, reload } = remote;
    const [selectedKpiId, setSelectedKpiId] = useState(null);
    const [showActionForm, setShowActionForm] = useState(false);
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;
    if (! data.overview.kpis.length) return <Page><GlanceHeader eyebrow="Decision intelligence" title="KPI at a Glance" description="Satu pandangan untuk membaca kesehatan sertifikasi, keuangan, dan teknologi." period={period} remote={remote} actions={hasPermission(user, 'data.import.manage') ? <DataTools onImported={reload} /> : null} /><div className="dashboard-empty-panel"><EmptyState title="KPI belum dikonfigurasi" description="Tambahkan definisi KPI perusahaan, lalu impor pengukuran melalui template CSV." /></div></Page>;
    const { overview, actions } = data;
    const primaryKpi = overview.kpis.find((kpi) => kpi.id === selectedKpiId) || overview.kpis[0];
    const chartData = { labels: primaryKpi.trend.map((x) => x.period), datasets: [{ label: 'Realisasi', data: primaryKpi.trend.map((x) => x.actual), borderColor: '#0f766e', backgroundColor: 'rgba(15,118,110,.10)', fill: true, tension: .38, pointRadius: 2 }, { label: 'Target', data: primaryKpi.trend.map((x) => x.target), borderColor: '#d39b2a', borderDash: [5, 5], tension: .2, pointRadius: 0 }] };
    const statusGroups = overview.kpis.reduce((acc, kpi) => { acc[kpi.status] = (acc[kpi.status] || 0) + 1; return acc; }, {});
    const statusData = { labels: ['Sesuai target', 'Perlu dipantau', 'Perlu tindakan'], datasets: [{ data: [statusGroups.on_track || 0, statusGroups.watch || 0, statusGroups.critical || 0], backgroundColor: ['#6b5ca5', '#b16ac0', '#4b3f88'], borderWidth: 0 }] };
    const departmentScores = { labels: overview.departments.map((department) => department.name.replace('Teknologi Informasi', 'Teknologi')), datasets: [{ data: overview.departments.map((department) => department.score), backgroundColor: ['#6b5ca5', '#a86ab9', '#4f75b8'], borderRadius: 2, barThickness: 26 }] };
    const departmentShare = { labels: overview.departments.map((department) => department.name), datasets: [{ data: overview.departments.map((department) => Math.max(Number(department.score), 1)), backgroundColor: ['#4b3f88', '#9a5bb3', '#4f75b8'], borderWidth: 0 }] };
    const compactChart = { ...chartDefaults, plugins: { ...chartDefaults.plugins, tooltip: { ...chartDefaults.plugins.tooltip, displayColors: false } }, scales: { x: { ...chartDefaults.scales.x, ticks: { ...chartDefaults.scales.x.ticks, font: { family: 'Satoshi', size: 10 } } }, y: { ...chartDefaults.scales.y, ticks: { ...chartDefaults.scales.y.ticks, font: { family: 'Satoshi', size: 10 } } } } };
    const compactLineOptions = { ...compactChart, plugins: { ...compactChart.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 7, padding: 12, font: { size: 10 } } } } };
    const compactBarOptions = { ...compactChart, indexAxis: 'y', plugins: { ...compactChart.plugins, legend: { display: false } }, scales: { x: { ...compactChart.scales.x, min: 0, max: 100, ticks: { ...compactChart.scales.x.ticks, callback: (value) => `${value}` } }, y: { ...compactChart.scales.y, grid: { display: false } } } };
    const criticalKpis = overview.kpis.filter((kpi) => kpi.status !== 'on_track').sort((a, b) => Number(a.score) - Number(b.score));

    return <Page>
        <GlanceHeader eyebrow="Decision intelligence" title="KPI at a Glance" description="Satu pandangan untuk membaca kesehatan sertifikasi, keuangan, dan teknologi." period={period} remote={remote} actions={<>{hasPermission(user, 'data.import.manage') && <DataTools onImported={reload} />}{hasPermission(user, 'actions.manage') && <button className="primary-button compact" type="button" onClick={() => setShowActionForm(true)}><Plus size={17} aria-hidden="true" />Tindak lanjut</button>}</>} />
        <div className="dashboard-layout">
            <GlanceRail primaryIcon={BadgeCheck} primaryTitle={<>Kinerja<br />Sertifikasi</>} primaryDescription="Arus asesi, keputusan kompetensi, dan ketepatan penerbitan." secondaryIcon={Target} secondaryTitle={<>Kualitas<br />Keputusan</>} secondaryDescription="Angka memiliki pemilik, target, sumber, dan histori." />
            <div className="dashboard-main">
                <section className="dashboard-band band-primary animate-in"><div className="band-summary"><span>Skor kinerja terpadu</span><strong>{overview.overall_score}</strong><small>/ 100 · {overview.overall_status === 'on_track' ? 'sesuai arah' : 'perlu diputuskan'}</small></div><article className="dashboard-chart donut-chart"><h2>Status KPI</h2><AccessibleChart label="Distribusi status KPI" summary={`${statusGroups.on_track || 0} sesuai target, ${statusGroups.watch || 0} dipantau, ${statusGroups.critical || 0} perlu tindakan.`}><Doughnut data={statusData} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart department-chart"><h2>Skor per divisi</h2><AccessibleChart label="Perbandingan skor divisi" summary={overview.departments.map((department) => `${department.name} ${department.score}`).join(', ')}><Bar data={departmentScores} options={compactBarOptions} /></AccessibleChart></article><div className="band-stat-list"><div><span>KPI aktif</span><strong>{overview.kpis.length}</strong><small>indikator terukur</small></div><div><span>Indikator kritis</span><strong>{overview.kpis.filter((x) => x.status === 'critical').length}</strong><small>perlu perhatian</small></div></div></section>
                <section className="dashboard-band band-secondary animate-in"><div className="band-summary"><span>Pusat perhatian</span><strong>{criticalKpis.length}</strong><small>indikator di luar jalur</small><StatusBadge status={criticalKpis.length ? 'watch' : 'on_track'} /></div><article className="dashboard-chart department-donut"><h2>Kontribusi divisi</h2><AccessibleChart label="Kontribusi skor divisi" summary={overview.departments.map((department) => `${department.name} ${department.score}`).join(', ')}><Doughnut data={departmentShare} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><div className="dashboard-chart-head"><h2>{primaryKpi.name}</h2><StatusBadge status={primaryKpi.status} /></div><AccessibleChart label={`Tren ${primaryKpi.name}`} summary={`Realisasi terakhir ${primaryKpi.actual} ${primaryKpi.unit}, target ${primaryKpi.target} ${primaryKpi.unit}.`} hasData={primaryKpi.trend.length > 0}><Line data={chartData} options={compactLineOptions} /></AccessibleChart><div className="kpi-selector">{overview.kpis.map((kpi) => <button type="button" className={primaryKpi.id === kpi.id ? 'active' : ''} aria-pressed={primaryKpi.id === kpi.id} key={kpi.id} onClick={() => setSelectedKpiId(kpi.id)}><i className={kpi.status} aria-hidden="true" />{kpi.code}</button>)}</div></article><div className="band-stat-list"><div><span>Tindak lanjut</span><strong>{overview.open_actions}</strong><small>{overview.overdue_actions} melewati tenggat</small></div><div><span>Pembaruan terakhir</span><strong>{overview.last_updated_at ? new Date(overview.last_updated_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) : '-'}</strong><small>rekam terbaru</small></div></div></section>
                <PrototypeNotice period={overview.period} />
                <section className="dashboard-evidence-grid grid-flow-dense">
                    <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Rasio pencapaian KPI</h3><span>{overview.kpis.length} indikator</span></div><div className="evidence-bar-list">{overview.kpis.slice(0, 5).map((kpi) => <div key={kpi.id}><div><span>{kpi.code}</span><strong>{Number(kpi.score).toFixed(1)}</strong></div><i className={kpi.status} style={{ width: `${Math.min(Number(kpi.score), 100)}%` }} /></div>)}</div></article>
                    <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Kontribusi skor divisi</h3><span>Skala 100</span></div><div className="evidence-donut" role="img" aria-label={overview.departments.map((department) => `${department.name} ${department.score}`).join(', ')}><Doughnut data={departmentShare} options={glanceDonutOptions} /></div></article>
                    <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Indikator untuk dilihat</h3><span>{criticalKpis.length} sinyal</span></div><div className="evidence-list">{(criticalKpis.length ? criticalKpis : overview.kpis.slice(0, 3)).slice(0, 4).map((kpi) => <div key={kpi.id}><span className={`evidence-dot ${kpi.status}`} /><div><strong>{kpi.name}</strong><small>{kpi.code} · {Number(kpi.actual).toLocaleString('id-ID')} {kpi.unit}</small></div><b>{Number(kpi.score).toFixed(1)}</b></div>)}</div></article>
                    <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Tindak lanjut aktif</h3><span>{actions.filter((x) => x.status !== 'completed').length} pekerjaan</span></div><div className="evidence-list">{actions.slice(0, 4).map((action) => <div key={action.id}><span className={`evidence-dot ${action.priority}`} /><div><strong>{action.title}</strong><small>{action.department?.name} · {action.owner_name}</small></div><StatusBadge status={action.status} /></div>)}</div></article>
                </section>
                <section className="dashboard-kpi-details"><div className="section-heading animate-in"><div><p className="eyebrow">Kamus kinerja</p><h2>Indikator yang membentuk skor</h2></div><p>Setiap angka memiliki pemilik, target, sumber, dan histori.</p></div><div className="kpi-grid grid-flow-dense">{overview.kpis.map((kpi) => <KpiCard kpi={kpi} key={kpi.id} period={`${period.year}-${String(period.month).padStart(2, '0')}-01`} onSaved={reload} canEdit={hasPermission(user, 'kpi.custom.manage')} />)}</div></section>
                <ActionBoard actions={actions} onChanged={reload} canManage={hasPermission(user, 'actions.manage')} />
            </div>
        </div>
        {showActionForm && hasPermission(user, 'actions.manage') && <ActionForm options={data.action_options} onClose={() => setShowActionForm(false)} onSaved={() => { setShowActionForm(false); reload(); }} />}
    </Page>;
}

function KpiCard({ kpi, period, onSaved, canEdit = false }) {
    const [editing, setEditing] = useState(false);
    const [actual, setActual] = useState(kpi.actual);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const notify = useToast();
    const save = async () => {
        setBusy(true); setError('');
        try {
            await http.post(`/kpis/${kpi.id}/measurements`, { period: period || new Date().toISOString().slice(0, 7) + '-01', actual });
            setEditing(false); notify(`${kpi.name} berhasil diperbarui.`); onSaved();
        } catch (saveError) { setError(requestError(saveError, 'Nilai KPI tidak dapat disimpan.')); } finally { setBusy(false); }
    };
    return <article className={`kpi-card animate-in ${kpi.status}`}><div className="kpi-top"><span>{kpi.code}</span><StatusBadge status={kpi.status} /></div><h3>{kpi.name}</h3><p>{kpi.description}</p><div className="kpi-values"><div><small>Realisasi</small>{editing ? <input aria-label={`Realisasi ${kpi.name}`} type="number" value={actual} onChange={(e) => setActual(e.target.value)} /> : <strong>{Number(kpi.actual).toLocaleString('id-ID')} <em>{kpi.unit}</em></strong>}</div><div><small>Target</small><strong>{Number(kpi.target).toLocaleString('id-ID')} <em>{kpi.unit}</em></strong></div></div><div className="progress" role="progressbar" aria-label={`Pencapaian ${kpi.name}`} aria-valuenow={Math.min(Number(kpi.score), 100)} aria-valuemin="0" aria-valuemax="100"><i style={{ width: `${Math.min(kpi.score, 100)}%` }} /></div>{error && <small className="field-error" role="alert">{error}</small>}<footer><span>Sumber: {kpi.data_source}</span>{kpi.is_system_derived ? <strong>Otomatis</strong> : editing ? <span className="inline-actions"><button type="button" onClick={() => { setEditing(false); setError(''); }}>Batal</button><button type="button" onClick={save} disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan'}</button></span> : canEdit ? <button type="button" onClick={() => setEditing(true)}>Perbarui</button> : <strong>Manual · baca saja</strong>}</footer></article>;
}

function DataTools({ onImported }) {
    const input = useRef(null);
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const importFile = async (event) => { const file = event.target.files[0]; if (!file) return; setBusy(true); const body = new FormData(); body.append('file', file); try { const { data } = await http.post('/data/import', body); notify(`${data.imported} baris diimpor${data.rejected ? `, ${data.rejected} ditolak` : ''}.`, data.rejected ? 'warning' : 'success'); onImported(); } catch (e) { notify(requestError(e, 'Impor gagal.'), 'error'); } finally { setBusy(false); event.target.value = ''; } };
    return <><a className="secondary-button" href="/api/data/template"><Download size={17} aria-hidden="true" />Template CSV</a><button className="primary-button compact" type="button" onClick={() => input.current.click()} disabled={busy} aria-busy={busy}>{busy ? <RefreshCw className="spin" size={17} aria-hidden="true" /> : <Upload size={17} aria-hidden="true" />}{busy ? 'Mengimpor…' : 'Impor data'}</button><input ref={input} hidden type="file" accept=".csv,text/csv" onChange={importFile} /></>;
}

function ActionBoard({ actions, onChanged, canManage = false }) {
    const [updatingId, setUpdatingId] = useState(null);
    const [completionAction, setCompletionAction] = useState(null);
    const notify = useToast();
    const update = async (action, status) => {
        if (status === 'completed') { setCompletionAction(action); return; }
        setUpdatingId(action.id);
        try { await http.patch(`/actions/${action.id}`, { status }); notify('Status tindak lanjut diperbarui.'); onChanged(); }
        catch (error) { notify(requestError(error, 'Status tidak dapat diperbarui.'), 'error'); }
        finally { setUpdatingId(null); }
    };
    return <><section className="action-section animate-in"><div className="section-heading"><div><p className="eyebrow">Eksekusi keputusan</p><h2>Tindak lanjut lintas divisi</h2></div><span>{actions.filter((x) => x.status !== 'completed').length} pekerjaan aktif</span></div>{actions.length ? <div className="action-list">{actions.map((action) => <article key={action.id}><span className={`priority-mark ${action.priority}`} aria-hidden="true" /><div className="action-main"><div><StatusBadge status={action.status} /><span>{action.department?.name}</span>{action.risk_signal && <span>· Signal #{action.risk_signal.id}</span>}</div><h3>{action.title}</h3><p>{action.description}</p>{action.decision_reference && <small>Referensi keputusan: {action.decision_reference}</small>}{action.status === 'completed' && action.resolution_note && <small>Resolusi: {action.resolution_note} · Bukti: {action.resolution_evidence}</small>}</div><div className="action-owner"><span className="avatar small" aria-hidden="true">{action.owner_name.split(' ').map((x) => x[0]).slice(0, 2).join('')}</span><div><strong>{action.owner_name}</strong><small>Tenggat {new Date(action.due_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}</small>{action.escalation_level > 0 && <small>Eskalasi level {action.escalation_level}</small>}</div></div><select aria-label={`Status ${action.title}`} value={action.status} disabled={!canManage || updatingId === action.id || action.status === 'completed'} onChange={(e) => update(action, e.target.value)}><option value="open">Terbuka</option><option value="in_progress">Dikerjakan</option><option value="blocked">Terhambat</option><option value="completed">Selesai</option></select></article>)}</div> : <EmptyState title="Belum ada tindak lanjut" description="Gunakan tombol Tindak lanjut untuk membuat pekerjaan keputusan pertama." />}</section>{completionAction && <ActionCompletionForm action={completionAction} onClose={() => setCompletionAction(null)} onSaved={() => { setCompletionAction(null); onChanged(); }} />}</>;
}

function ActionCompletionForm({ action, onClose, onSaved }) {
    const [form, setForm] = useState({ resolution_note: '', resolution_evidence: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try { await http.patch(`/actions/${action.id}`, { status: 'completed', ...form }); notify('Tindak lanjut diselesaikan beserta bukti resolusi.'); onSaved(); }
        catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Tindak lanjut belum dapat diselesaikan.'), 'error'); }
        finally { setBusy(false); }
    };
    return <Modal title="Selesaikan tindak lanjut" onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Penyelesaian harus dapat diaudit. Jelaskan hasil koreksi dan cantumkan referensi bukti.</p><label>Catatan resolusi<textarea value={form.resolution_note} onChange={(e) => setForm({ ...form, resolution_note: e.target.value })} required /><FieldError errors={errors} name="resolution_note" /></label><label>Bukti / referensi<input value={form.resolution_evidence} onChange={(e) => setForm({ ...form, resolution_evidence: e.target.value })} placeholder="Nomor dokumen, tiket, URL internal, atau referensi evidence" required /><FieldError errors={errors} name="resolution_evidence" /></label><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Tutup tindak lanjut'}</button></div></form></Modal>;
}

function ActionForm({ options, onClose, onSaved }) {
    const initialDepartment = options.departments[0]?.id || '';
    const [form, setForm] = useState({ department_id: initialDepartment, kpi_definition_id: '', title: '', description: '', priority: 'medium', owner_name: '', due_date: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10) });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const kpis = options.kpis.filter((kpi) => Number(kpi.department_id) === Number(form.department_id));
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try { await http.post('/actions', { ...form, kpi_definition_id: form.kpi_definition_id || null }); notify('Tindak lanjut berhasil dibuat.'); onSaved(); }
        catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Tindak lanjut tidak dapat dibuat.'), 'error'); }
        finally { setBusy(false); }
    };
    const setDepartment = (departmentId) => setForm({ ...form, department_id: departmentId, kpi_definition_id: '' });

    return <Modal title="Buat tindak lanjut" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><div className="form-grid"><label>Divisi<select value={form.department_id} onChange={(event) => setDepartment(event.target.value)} required>{options.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select><FieldError errors={errors} name="department_id" /></label><label>KPI terkait<select value={form.kpi_definition_id} onChange={(event) => setForm({ ...form, kpi_definition_id: event.target.value })}><option value="">Tanpa KPI khusus</option>{kpis.map((kpi) => <option key={kpi.id} value={kpi.id}>{kpi.code} · {kpi.name}</option>)}</select><FieldError errors={errors} name="kpi_definition_id" /></label></div><label>Judul pekerjaan<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /><FieldError errors={errors} name="title" /></label><label>Uraian<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /><FieldError errors={errors} name="description" /></label><div className="form-grid"><label>Prioritas<select value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })}><option value="low">Rendah</option><option value="medium">Sedang</option><option value="high">Tinggi</option><option value="critical">Kritis</option></select></label><label>Tenggat<input type="date" value={form.due_date} onChange={(event) => setForm({ ...form, due_date: event.target.value })} required /><FieldError errors={errors} name="due_date" /></label></div><label>Penanggung jawab<input value={form.owner_name} onChange={(event) => setForm({ ...form, owner_name: event.target.value })} required /><FieldError errors={errors} name="owner_name" /></label><button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Simpan tindak lanjut'}</button></form></Modal>;
}


function DecisionCenterPage() {
    const { user } = useAuth();
    const remote = useRemote('/decisions', 15000);
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const [selected, setSelected] = useState(null);
    const notify = useToast();
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const activeSignals = data.signals.filter((signal) => !['resolved', 'dismissed'].includes(signal.status));
    const openModal = (type, entity = null) => { setSelected(entity); setModal(type); };
    const closeModal = () => { setModal(null); setSelected(null); };
    const saved = () => { closeModal(); reload(); };
    const acknowledge = async (signal) => {
        try { await http.post(`/decisions/signals/${signal.id}/acknowledge`); notify('Signal telah diakui dan masuk tanggung jawab penanganan.'); reload(); }
        catch (e) { notify(requestError(e, 'Signal belum dapat diakui.'), 'error'); }
    };

    return <Page>
        <GlanceHeader eyebrow="Decision workflow" title="Pusat Keputusan" description="Ubah exception operasional menjadi signal, keputusan, tindak lanjut, eskalasi, dan management review yang dapat diaudit." remote={remote} showPeriod={false} actions={data.permissions.review ? <button className="primary-button compact" type="button" onClick={() => openModal('review-create')}><ClipboardCheck size={16} />Management review</button> : null} />
        <section className="metric-grid decision-metrics animate-in">
            <MetricCard icon={AlertTriangle} label="Signal aktif" value={data.summary.active_signals} tone="gold" />
            <MetricCard icon={ShieldCheck} label="Signal kritis" value={data.summary.critical_signals} tone="gold" />
            <MetricCard icon={Eye} label="Belum diakui" value={data.summary.unacknowledged_signals} tone="violet" />
            <MetricCard icon={ArrowUpRight} label="Sudah dieskalasi" value={data.summary.escalated_signals} tone="blue" />
            <MetricCard icon={ClipboardCheck} label="Action aktif" value={data.summary.open_actions} tone="blue" />
            <MetricCard icon={Target} label="Action lewat tenggat" value={data.summary.overdue_actions} tone="gold" />
        </section>

        <DataTable title="Risk & exception signals" columns={['Severity', 'Sumber', 'Signal', 'Divisi', 'Status', 'Tenggat', 'Tindakan']} rows={data.signals.map((signal) => ({ key: signal.id, cells: [<StatusBadge status={signal.severity} />, <span><strong>{signal.source_reference || signal.rule_code}</strong><br /><small>{signal.category} · {signal.rule_code}</small></span>, <span><strong>{signal.title}</strong><br /><small>{signal.description}</small></span>, signal.department?.name || 'Lintas perusahaan', <span><StatusBadge status={signal.status} />{signal.escalation_level > 0 && <small>Level eskalasi {signal.escalation_level}</small>}</span>, signal.due_at ? new Date(signal.due_at).toLocaleString('id-ID') : '—', data.permissions.manage && !['resolved', 'dismissed'].includes(signal.status) ? <span className="inline-actions">{!signal.acknowledged_at && <button type="button" onClick={() => acknowledge(signal)}>Akui</button>}<button type="button" onClick={() => openModal('signal-action', signal)}>Action</button><button type="button" onClick={() => openModal('signal-escalate', signal)}>Eskalasi</button><button type="button" onClick={() => openModal('signal-resolve', signal)}>Resolusi</button></span> : '—'] }))} />

        <ActionBoard actions={data.actions} onChanged={reload} canManage={data.permissions.manage} />

        {data.permissions.review && <DataTable title="Riwayat Management Review" columns={['Referensi', 'Periode', 'Rapat', 'Ketua', 'Status', 'Item', 'Keputusan']} rows={data.reviews.map((review) => ({ key: review.id, cells: [<strong>{review.reference}</strong>, `${new Date(review.period_start).toLocaleDateString('id-ID')} – ${new Date(review.period_end).toLocaleDateString('id-ID')}`, review.meeting_at ? new Date(review.meeting_at).toLocaleString('id-ID') : 'Belum dijadwalkan', review.chair_name, <StatusBadge status={review.status} />, `${review.items.length} item`, <button type="button" className="table-action" onClick={() => openModal('review-update', review)}>{review.status === 'closed' ? 'Lihat' : 'Kelola'}</button>] }))} />}

        {modal === 'signal-action' && selected && <RiskActionForm signal={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'signal-escalate' && selected && <SignalEscalationForm signal={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'signal-resolve' && selected && <SignalResolutionForm signal={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'review-create' && data.permissions.review && <ManagementReviewCreateForm user={user} signals={activeSignals} onClose={closeModal} onSaved={saved} />}
        {modal === 'review-update' && selected && data.permissions.review && <ManagementReviewWorkflowForm review={selected} onClose={closeModal} onSaved={saved} />}
    </Page>;
}

function RiskActionForm({ signal, onClose, onSaved }) {
    const defaultDue = signal.due_at ? new Date(signal.due_at) : new Date(Date.now() + 7 * 86400000);
    const [form, setForm] = useState({ title: `Tindak lanjut: ${signal.title}`, description: signal.description, priority: signal.severity === 'critical' ? 'critical' : signal.severity === 'high' ? 'high' : 'medium', owner_name: '', due_date: defaultDue.toISOString().slice(0, 10), decision_reference: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/decisions/signals/${signal.id}/actions`, form); notify('Action item dibuat dan ditautkan ke risk signal.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Action item belum dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Buat action dari signal" onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Signal {signal.source_reference || `#${signal.id}`} · <strong>{signal.title}</strong></p><label>Judul<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /><FieldError errors={errors} name="title" /></label><label>Uraian<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label><div className="form-grid"><label>Prioritas<select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}><option value="low">Rendah</option><option value="medium">Sedang</option><option value="high">Tinggi</option><option value="critical">Kritis</option></select></label><label>Tenggat<input type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} required /></label></div><label>Penanggung jawab<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /><FieldError errors={errors} name="owner_name" /></label><label>Referensi keputusan <small>(opsional)</small><input value={form.decision_reference} onChange={(e) => setForm({ ...form, decision_reference: e.target.value })} placeholder="Notulen, nomor memo, tiket, atau referensi rapat" /></label><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Buat action'}</button></div></form></Modal>;
}

function SignalEscalationForm({ signal, onClose, onSaved }) {
    const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { await http.post(`/decisions/signals/${signal.id}/escalate`, { reason }); notify('Signal dieskalasi.'); onSaved(); } catch (err) { setError(requestError(err, 'Signal belum dapat dieskalasi.')); } finally { setBusy(false); } };
    return <Modal title="Eskalasi signal" onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Eskalasi menaikkan level perhatian dan tercatat di audit trail.</p><label>Alasan eskalasi<textarea value={reason} onChange={(e) => setReason(e.target.value)} required /></label>{error && <div className="form-error">{error}</div>}<div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : `Eskalasi ke level ${Math.min(3, Number(signal.escalation_level || 0) + 1)}`}</button></div></form></Modal>;
}

function SignalResolutionForm({ signal, onClose, onSaved }) {
    const [resolution, setResolution] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { await http.post(`/decisions/signals/${signal.id}/resolve`, { resolution_note: resolution }); notify('Signal ditandai selesai. Jika kondisi sumber masih aktif, engine akan membukanya kembali.'); onSaved(); } catch (err) { setError(requestError(err, 'Signal belum dapat diselesaikan.')); } finally { setBusy(false); } };
    return <Modal title="Catat resolusi signal" onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Risk engine akan memverifikasi ulang kondisi sumber pada sinkronisasi berikutnya.</p><label>Catatan resolusi<textarea value={resolution} onChange={(e) => setResolution(e.target.value)} required /></label>{error && <div className="form-error">{error}</div>}<div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Catat resolusi'}</button></div></form></Modal>;
}

function ManagementReviewCreateForm({ user, signals, onClose, onSaved }) {
    const now = new Date(); const start = new Date(now.getFullYear(), now.getMonth(), 1); const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    const [form, setForm] = useState({ title: `Management Review ${now.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}`, period_start: start.toISOString().slice(0, 10), period_end: end.toISOString().slice(0, 10), meeting_at: '', chair_name: user.name, summary: '', signal_ids: signals.filter((s) => ['critical', 'high'].includes(s.severity)).map((s) => s.id) });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const toggle = (id) => setForm((current) => ({ ...current, signal_ids: current.signal_ids.includes(id) ? current.signal_ids.filter((x) => x !== id) : [...current.signal_ids, id] }));
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/decisions/reviews', { ...form, meeting_at: form.meeting_at || null }); notify('Management review draft dibuat.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Management review belum dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Buat Management Review" onClose={onClose}><form className="stack-form" onSubmit={submit}><label>Judul<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></label><div className="form-grid"><label>Periode mulai<input type="date" value={form.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} required /></label><label>Periode akhir<input type="date" value={form.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} required /></label></div><div className="form-grid"><label>Jadwal rapat<input type="datetime-local" value={form.meeting_at} onChange={(e) => setForm({ ...form, meeting_at: e.target.value })} /></label><label>Ketua rapat<input value={form.chair_name} onChange={(e) => setForm({ ...form, chair_name: e.target.value })} required /></label></div><label>Ringkasan awal<textarea value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} /></label><fieldset className="selection-list"><legend>Signal yang masuk agenda</legend>{signals.length ? signals.map((signal) => <label key={signal.id}><input type="checkbox" checked={form.signal_ids.includes(signal.id)} onChange={() => toggle(signal.id)} /><span><strong>{signal.title}</strong><small><StatusBadge status={signal.severity} /> {signal.department?.name || 'Lintas perusahaan'}</small></span></label>) : <EmptyState compact title="Tidak ada signal aktif" description="Management review memerlukan minimal satu signal." />}</fieldset><FieldError errors={errors} name="signal_ids" /><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy || !form.signal_ids.length}>{busy ? 'Menyimpan…' : 'Buat draft review'}</button></div></form></Modal>;
}

function ManagementReviewWorkflowForm({ review, onClose, onSaved }) {
    const reviewStatusLabels = { draft: 'Draft', in_review: 'Dalam review', approved: 'Disetujui', closed: 'Ditutup' };
    const reviewTransitions = { draft: ['draft', 'in_review'], in_review: ['in_review', 'approved'], approved: ['approved', 'closed'], closed: ['closed'] };
    const itemStatusLabels = { open: 'Terbuka', in_progress: 'Dikerjakan', completed: 'Selesai' };
    const itemTransitions = { open: ['open', 'in_progress', 'completed'], in_progress: ['in_progress', 'completed'], completed: ['completed'] };
    const [form, setForm] = useState({ status: review.status, meeting_at: review.meeting_at ? String(review.meeting_at).slice(0, 16) : '', summary: review.summary || '', decisions: review.decisions || '' });
    const [items, setItems] = useState(review.items.map((item) => ({ id: item.id, title: item.title, decision: item.decision || '', owner_name: item.owner_name || '', due_date: item.due_date ? String(item.due_date).slice(0, 10) : '', status: item.status || 'open' })));
    const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast(); const locked = ['approved', 'closed'].includes(review.status);
    const allowedReviewStatuses = reviewTransitions[review.status] || [review.status];
    const setItem = (id, key, value) => setItems((current) => current.map((item) => item.id === id ? { ...item, [key]: value } : item));
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { if (!locked) { for (const item of items) await http.patch(`/decisions/reviews/${review.id}/items/${item.id}`, { decision: item.decision || null, owner_name: item.owner_name || null, due_date: item.due_date || null, status: item.status }); } await http.patch(`/decisions/reviews/${review.id}`, { ...form, meeting_at: form.meeting_at || null, summary: form.summary || null, decisions: form.decisions || null }); notify('Management review diperbarui.'); onSaved(); } catch (err) { setError(requestError(err, 'Management review belum dapat diperbarui.')); } finally { setBusy(false); } };
    return <Modal title={`${review.reference} · Management Review`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Lifecycle wajib mengikuti urutan Draft → Dalam review → Disetujui → Ditutup. Item agenda hanya dapat bergerak maju dan terkunci setelah review disetujui.</p><div className="form-grid"><label>Status<select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} disabled={review.status === 'closed'}>{allowedReviewStatuses.map((status) => <option key={status} value={status}>{reviewStatusLabels[status] || status}</option>)}</select></label><label>Jadwal rapat<input type="datetime-local" value={form.meeting_at} onChange={(e) => setForm({ ...form, meeting_at: e.target.value })} disabled={review.status === 'closed'} /></label></div><label>Ringkasan<textarea value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} disabled={review.status === 'closed'} /></label><label>Keputusan rapat<textarea value={form.decisions} onChange={(e) => setForm({ ...form, decisions: e.target.value })} disabled={review.status === 'closed'} placeholder="Wajib sebelum status Disetujui." /></label><div className="review-items"><h3>Agenda keputusan</h3>{items.map((item) => { const allowedItemStatuses = itemTransitions[item.status] || [item.status]; return <article key={item.id}><strong>{item.title}</strong><label>Keputusan<textarea value={item.decision} onChange={(e) => setItem(item.id, 'decision', e.target.value)} disabled={locked} /></label><div className="form-grid"><label>Owner<input value={item.owner_name} onChange={(e) => setItem(item.id, 'owner_name', e.target.value)} disabled={locked} /></label><label>Tenggat<input type="date" value={item.due_date} onChange={(e) => setItem(item.id, 'due_date', e.target.value)} disabled={locked} /></label></div><label>Status<select value={item.status} onChange={(e) => setItem(item.id, 'status', e.target.value)} disabled={locked}>{allowedItemStatuses.map((status) => <option key={status} value={status}>{itemStatusLabels[status] || status}</option>)}</select></label></article>; })}</div>{error && <div className="form-error">{error}</div>}<div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Tutup</button>{review.status !== 'closed' && <button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan review'}</button>}</div></form></Modal>;
}

function localDateTimeValue(date = new Date()) {
    const local = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
    return local.toISOString().slice(0, 16);
}

function ReportingPage() {
    const { user } = useAuth();
    const period = useReportingPeriod();
    const remote = useRemote('/reports', 60000);
    const notify = useToast();
    const [reportType, setReportType] = useState('');
    const [departmentCode, setDepartmentCode] = useState('');
    const [reviewId, setReviewId] = useState('');
    const [selected, setSelected] = useState(null);
    const [generating, setGenerating] = useState(false);

    useEffect(() => {
        if (!remote.data?.catalog?.length) return;
        if (!reportType || !remote.data.catalog.some((item) => item.type === reportType)) setReportType(remote.data.catalog[0].type);
        if (!departmentCode && remote.data.departments?.length) setDepartmentCode(remote.data.departments[0].code);
        if (!reviewId && remote.data.management_reviews?.length) setReviewId(String(remote.data.management_reviews[0].id));
    }, [remote.data, reportType, departmentCode, reviewId]);

    const generate = async () => {
        if (!reportType) return;
        setGenerating(true);
        try {
            const payload = { report_type: reportType, year: period.year, month: period.month };
            if (reportType === 'department') payload.department_code = departmentCode;
            if (reportType === 'management_review') payload.management_review_id = Number(reviewId);
            const { data } = await http.post('/reports', payload);
            setSelected(data.report);
            notify('Snapshot laporan berhasil dibuat dan dikunci dengan SHA-256.');
            remote.reload();
        } catch (error) {
            notify(requestError(error, 'Laporan belum dapat dibuat.'), 'error');
        } finally { setGenerating(false); }
    };

    const loadSnapshot = async (id) => {
        try { const { data } = await http.get(`/reports/${id}`); setSelected(data.report); }
        catch (error) { notify(requestError(error, 'Snapshot laporan tidak dapat dibuka.'), 'error'); }
    };

    if (remote.loading) return <Page loading />;
    if (remote.error) return <Page><ErrorState message={remote.error} retry={remote.reload} /></Page>;
    const data = remote.data;
    const catalog = data.catalog || [];
    const currentCatalog = catalog.find((item) => item.type === reportType);
    const kpis = selected?.payload?.kpi_configuration_snapshot || [];
    const content = selected?.payload?.content || {};
    const overview = content.overview || content.department?.overview || null;
    const summaryCandidates = [
        ['Skor komposit', overview?.overall_score],
        ['Status', overview?.overall_status],
        ['Action aktif', overview?.open_actions],
        ['Action overdue', overview?.overdue_actions],
    ].filter(([, value]) => value !== undefined && value !== null);

    return <Page><PageHeader eyebrow="Reporting & Evidence" title="Laporan manajemen" description="Buat snapshot immutable dari angka dashboard, keputusan, dan provenance untuk rapat, audit, serta bukti manajemen." actions={<button className="primary-button compact" type="button" onClick={generate} disabled={generating || !reportType}>{generating ? <RefreshCw className="spin" size={17} /> : <ClipboardCheck size={17} />}{generating ? 'Membuat snapshot…' : 'Buat snapshot'}</button>} />
        <section className="report-builder panel animate-in">
            <div className="panel-heading"><div><p className="eyebrow">Snapshot builder</p><h2>Pilih laporan dan periode</h2><p className="report-help">Snapshot yang sudah dibuat tidak ikut berubah ketika data operasional atau target KPI diperbarui di kemudian hari.</p></div><span>{catalog.length} tipe tersedia</span></div>
            <div className="report-controls">
                <label>Tipe laporan<select value={reportType} onChange={(e) => setReportType(e.target.value)}>{catalog.map((item) => <option key={item.type} value={item.type}>{item.label}</option>)}</select><small>{currentCatalog?.description}</small></label>
                <label>Tahun<select value={period.year} onChange={(e) => period.setYear(Number(e.target.value))}>{period.years.map((year) => <option key={year} value={year}>{year}</option>)}</select></label>
                <label>Periode<select value={period.month} onChange={(e) => period.setMonth(Number(e.target.value))}>{reportingMonths.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                {reportType === 'department' && <label>Divisi<select value={departmentCode} onChange={(e) => setDepartmentCode(e.target.value)}>{(data.departments || []).map((department) => <option key={department.id} value={department.code}>{department.name}</option>)}</select></label>}
                {reportType === 'management_review' && <label>Management Review<select value={reviewId} onChange={(e) => setReviewId(e.target.value)}>{(data.management_reviews || []).map((review) => <option key={review.id} value={review.id}>{review.reference} · {review.title}</option>)}</select></label>}
            </div>
        </section>

        {selected && <section className="report-preview panel animate-in">
            <div className="panel-heading"><div><p className="eyebrow">Immutable snapshot</p><h2>{selected.title}</h2><p className="report-help">{selected.reference} · {new Date(selected.period_start).toLocaleDateString('id-ID')}–{new Date(selected.period_end).toLocaleDateString('id-ID')}</p></div><StatusBadge status="completed" /></div>
            <div className="report-hash"><ShieldCheck size={18} /><div><small>SHA-256 content hash</small><code>{selected.content_hash}</code></div></div>
            {summaryCandidates.length > 0 && <div className="report-summary-grid">{summaryCandidates.map(([label, value]) => <div key={label}><span>{label}</span><strong>{typeof value === 'number' ? Number(value).toLocaleString('id-ID') : value}</strong></div>)}</div>}
            {kpis.length > 0 && <div className="table-scroll report-table"><table><thead><tr><th>KPI</th><th>Aktual</th><th>Target</th><th>Warning</th><th>Bobot</th><th>Status</th><th>Sumber</th></tr></thead><tbody>{kpis.map((kpi) => <tr key={kpi.code}><td><strong>{kpi.code}</strong><small>{kpi.name}</small></td><td>{Number(kpi.actual ?? 0).toLocaleString('id-ID')}</td><td>{Number(kpi.target ?? 0).toLocaleString('id-ID')}</td><td>{kpi.warning_threshold ?? '—'}</td><td>{kpi.weight ?? '—'}</td><td><StatusBadge status={kpi.status} /></td><td><small>{kpi.data_source || kpi.source_type}</small></td></tr>)}</tbody></table></div>}
            <div className="report-export-actions">
                {data.permissions?.export && <><button type="button" className="secondary-button" onClick={() => window.open(`/api/reports/${selected.id}/print`, '_blank', 'noopener')}><Eye size={16} />Preview / Print PDF</button><a className="secondary-button" href={`/api/reports/${selected.id}/export/csv`}><Download size={16} />CSV</a><a className="secondary-button" href={`/api/reports/${selected.id}/export/json`}><Download size={16} />JSON</a><a className="primary-button compact" href={`/api/reports/${selected.id}/export/zip`}><ShieldCheck size={16} />Evidence Pack ZIP</a></>}
            </div>
        </section>}

        <section className="table-panel panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Snapshot history</p><h2>Riwayat laporan</h2></div><span>{data.history?.length || 0} snapshot</span></div>{data.history?.length ? <div className="table-scroll"><table><thead><tr><th>Reference</th><th>Laporan</th><th>Periode</th><th>Generator</th><th>Hash</th><th></th></tr></thead><tbody>{data.history.map((row) => <tr key={row.id}><td><strong>{row.reference}</strong><small>{new Date(row.generated_at).toLocaleString('id-ID')}</small></td><td>{row.title}{row.department && <small>{row.department.name}</small>}</td><td>{new Date(row.period_start).toLocaleDateString('id-ID')}–{new Date(row.period_end).toLocaleDateString('id-ID')}</td><td>{row.generated_by || 'Sistem'}</td><td><code className="short-hash">{row.content_hash.slice(0, 12)}…</code></td><td><button className="table-action" type="button" onClick={() => loadSnapshot(row.id)}>Buka</button></td></tr>)}</tbody></table></div> : <EmptyState title="Belum ada snapshot" description="Buat laporan pertama untuk mengunci evidence periode terpilih." />}</section>
    </Page>;
}

function CertificationPage() {
    const { user } = useAuth();
    const period = useReportingPeriod();
    const remote = useRemote(`/certification?${period.query}`);
    const { data, loading, error, reload } = remote;
    const [showForm, setShowForm] = useState(false);
    const [resultBatch, setResultBatch] = useState(null);
    const [issuanceBatch, setIssuanceBatch] = useState(null);
    const [updatingId, setUpdatingId] = useState(null);
    const notify = useToast();
    const canManage = hasPermission(user, 'certification.manage');
    const canIssueCertificates = hasPermission(user, 'certification.issue');
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;
    const totalCompetent = data.monthly.reduce((total, row) => total + Number(row.kompeten), 0);
    const totalNotCompetent = data.monthly.reduce((total, row) => total + Number(row.belum_kompeten), 0);
    const totalPending = Math.max(0, Number(data.summary.asesi) - totalCompetent - totalNotCompetent);
    const outcome = { labels: ['Kompeten', 'Belum kompeten', 'Dalam proses'], datasets: [{ data: [totalCompetent, totalNotCompetent, totalPending], backgroundColor: ['#4b3f88', '#b16ac0', '#4f75b8'], borderWidth: 0 }] };
    const trend = { labels: data.monthly.map((row) => row.period), datasets: [{ label: 'Asesi', data: data.monthly.map((row) => row.asesi), borderColor: '#4b3f88', backgroundColor: 'rgba(75,63,136,.10)', fill: true, tension: .35, pointRadius: 2 }, { label: 'Kompeten', data: data.monthly.map((row) => row.kompeten), borderColor: '#b16ac0', tension: .35, pointRadius: 2 }] };
    const pipeline = { labels: data.pipeline.map((item) => item.status.replaceAll('_', ' ')), datasets: [{ data: data.pipeline.map((item) => item.count), backgroundColor: ['#c5b7ea', '#aa8bd2', '#9368bb', '#7656a8', '#4b3f88'], borderRadius: 2, barThickness: 20 }] };
    const schemes = { labels: data.schemes.map((scheme) => scheme.name), datasets: [{ data: data.schemes.map((scheme) => scheme.asesi), backgroundColor: ['#4b3f88', '#7656a8', '#9a5bb3', '#b16ac0', '#4f75b8', '#8da1d4'], borderWidth: 0 }] };
    const pipelineOptions = { ...glanceChartOptions, indexAxis: 'y', plugins: { ...glanceChartOptions.plugins, legend: { display: false } }, scales: { x: { ...glanceChartOptions.scales.x, beginAtZero: true, ticks: { ...glanceChartOptions.scales.x.ticks, precision: 0 } }, y: { ...glanceChartOptions.scales.y, grid: { display: false } } } };
    const activePipeline = data.pipeline.filter((item) => item.status !== 'completed').reduce((total, item) => total + Number(item.count), 0);
    const stageOrder = ['planned', 'document_review', 'assessment', 'decision', 'completed'];
    const stageLabels = { planned: 'Direncanakan', document_review: 'Verifikasi dokumen', assessment: 'Asesmen', decision: 'Keputusan', completed: 'Selesai' };
    const updateStage = async (batch, status) => {
        setUpdatingId(batch.id);
        try {
            await http.patch(`/certification/batches/${batch.id}`, { status });
            notify(`Tahap ${batch.code} diperbarui.`);
            reload();
        } catch (updateError) {
            notify(requestError(updateError, 'Tahap batch tidak dapat diperbarui.'), 'error');
        } finally {
            setUpdatingId(null);
        }
    };
    const remainingCertificates = (batch) => Math.max(0, Number(batch.passed) - Number(batch.certificates_issued));

    return <Page>
        <GlanceHeader eyebrow="Operasi sertifikasi" title="Certification at a Glance" description="Arus asesi, keputusan kompetensi, dan ketepatan sertifikat dalam jendela analisis yang dapat dipilih." period={period} remote={remote} actions={canManage ? <button className="primary-button compact" type="button" onClick={() => setShowForm(true)}><Plus size={17} aria-hidden="true" />Buat batch</button> : null} />
        <div className="dashboard-layout"><GlanceRail primaryIcon={BadgeCheck} primaryTitle={<>Arus<br />Asesmen</>} primaryDescription="Pantau volume peserta dan progres setiap batch asesmen." secondaryIcon={ClipboardCheck} secondaryTitle={<>Mutu<br />Sertifikat</>} secondaryDescription="Bandingkan hasil kompetensi dan ketepatan penerbitan sertifikat." /><div className="dashboard-main">
            <section className="dashboard-band band-primary animate-in"><div className="band-summary"><span>Asesi 12 bulan</span><strong>{Number(data.summary.asesi).toLocaleString('id-ID')}</strong><small>peserta pada jendela analisis</small></div><article className="dashboard-chart"><h2>Keputusan kompetensi</h2><AccessibleChart label="Keputusan kompetensi" summary={`${totalCompetent} kompeten, ${totalNotCompetent} belum kompeten, ${totalPending} dalam proses.`} hasData={totalCompetent + totalNotCompetent + totalPending > 0}><Doughnut data={outcome} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart department-chart"><h2>Pipeline batch</h2><AccessibleChart label="Pipeline batch sertifikasi" summary={data.pipeline.map((item) => `${item.status} ${item.count}`).join(', ')} hasData={data.pipeline.some((item) => Number(item.count) > 0)}><Bar data={pipeline} options={pipelineOptions} /></AccessibleChart></article><div className="band-stat-list"><div><span>Tingkat kompeten</span><strong>{data.summary.pass_rate}%</strong><small>dari keputusan final</small></div><div><span>SLA sertifikat</span><strong>{data.summary.certificate_sla}%</strong><small>cohort jatuh tempo ≤ 30 hari</small></div></div></section>
            <section className="dashboard-band band-secondary animate-in"><div className="band-summary"><span>Batch aktif</span><strong>{data.summary.active_batches}</strong><small>{activePipeline} batch belum selesai</small></div><article className="dashboard-chart"><h2>Portofolio skema</h2><AccessibleChart label="Portofolio skema sertifikasi" summary={data.schemes.map((scheme) => `${scheme.name} ${scheme.asesi} asesi`).join(', ')} hasData={data.schemes.length > 0}><Doughnut data={schemes} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><h2>Volume dan hasil bulanan</h2><AccessibleChart label="Tren volume dan hasil sertifikasi" summary={`Total ${data.summary.asesi} asesi dengan tingkat kompeten ${data.summary.pass_rate} persen.`} hasData={data.monthly.some((row) => Number(row.asesi) > 0)}><Line data={trend} options={{ ...glanceChartOptions, plugins: { ...glanceChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 7 } } } }} /></AccessibleChart></article><div className="band-stat-list"><div><span>Backlog sertifikat</span><strong>{Number(data.summary.certificate_backlog).toLocaleString('id-ID')}</strong><small>{Number(data.summary.overdue_certificates).toLocaleString('id-ID')} melewati jatuh tempo</small></div><div><span>Belum kompeten</span><strong>{totalNotCompetent.toLocaleString('id-ID')}</strong><small>hasil evaluasi</small></div></div></section>
            <PrototypeNotice period={data.period} />
            <section className="dashboard-evidence-grid grid-flow-dense"><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Asesi per bulan</h3><span>5 bulan terakhir</span></div><EvidenceBars rows={data.monthly.slice(-5).map((row) => ({ label: row.period, value: row.asesi }))} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Sebaran skema</h3><span>{data.schemes.length} skema</span></div><div className="evidence-donut"><Doughnut data={schemes} options={glanceDonutOptions} /></div></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Permintaan tertinggi</h3><span>berdasarkan asesi</span></div><EvidenceList rows={data.schemes.slice(0, 4).map((scheme) => ({ title: scheme.name, detail: `${scheme.asesi} asesi`, trailing: <b>{scheme.pass_rate}%</b> }))} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Status pekerjaan</h3><span>{data.pipeline.length} tahap</span></div><EvidenceList rows={data.pipeline.slice(0, 5).map((item) => ({ key: item.status, title: item.status.replaceAll('_', ' '), detail: 'Jumlah batch pada tahap ini', trailing: <b>{item.count}</b> }))} /></article></section>
            <DataTable title="Batch asesmen terbaru" columns={['Batch', 'Skema dan TUK', 'Jadwal', 'Asesi', 'Hasil & sertifikat', 'Tahap', 'Aksi']} rows={data.batches.map((batch) => {
                const currentStage = stageOrder.indexOf(batch.status);
                const remaining = remainingCertificates(batch);
                const canRecordResult = batch.status === 'assessment' && Number(batch.pending) > 0;
                const canIssue = Boolean(batch.decision_at) && remaining > 0;
                const canComplete = batch.status === 'decision' && Number(batch.pending) === 0 && remaining === 0;
                return { key: batch.id, cells: [
                    <strong>{batch.code}</strong>,
                    <div><strong>{batch.scheme.name}</strong><small>{batch.tuk.name}</small></div>,
                    new Date(batch.assessment_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }),
                    batch.total_assesi,
                    <div><strong>{batch.passed} K</strong><small>{batch.failed} BK · {batch.pending} proses · {batch.certificates_issued}/{batch.passed} sertifikat</small></div>,
                    <select aria-label={`Tahap ${batch.code}`} value={batch.status} disabled={!canManage || updatingId === batch.id || batch.status === 'completed'} onChange={(event) => updateStage(batch, event.target.value)}>{stageOrder.map((status, index) => <option key={status} value={status} disabled={index < currentStage || index > currentStage + 1 || (status === 'decision' && Number(batch.pending) > 0) || (status === 'completed' && ! canComplete)}>{stageLabels[status]}</option>)}</select>,
                    <span className="inline-actions">{canManage && canRecordResult && <button type="button" onClick={() => setResultBatch(batch)}>Catat hasil</button>}{canIssueCertificates && canIssue && <button type="button" onClick={() => setIssuanceBatch(batch)}>Terbitkan</button>}{canManage && canComplete && <button type="button" onClick={() => updateStage(batch, 'completed')} disabled={updatingId === batch.id}>Selesaikan</button>}{(!canManage && !canIssueCertificates) || (!canRecordResult && !canIssue && !canComplete) ? <small>—</small> : null}</span>,
                ] };
            })} />
        </div></div>
        {showForm && canManage && <CertificationBatchForm catalogs={data.catalogs} onClose={() => setShowForm(false)} onSaved={() => { setShowForm(false); reload(); }} />}
        {resultBatch && canManage && <CertificationResultForm batch={resultBatch} onClose={() => setResultBatch(null)} onSaved={() => { setResultBatch(null); reload(); }} />}
        {issuanceBatch && canIssueCertificates && <CertificateIssuanceForm batch={issuanceBatch} onClose={() => setIssuanceBatch(null)} onSaved={() => { setIssuanceBatch(null); reload(); }} />}
    </Page>;
}

function CertificationBatchForm({ catalogs, onClose, onSaved }) {
    const [form, setForm] = useState({ code: `ASM-${new Date().toISOString().slice(0, 7).replace('-', '')}-${Date.now().toString().slice(-3)}`, certification_scheme_id: catalogs.schemes[0]?.id || '', tuk_id: catalogs.tuks[0]?.id || '', assessor_id: catalogs.assessors[0]?.id || '', assessment_date: new Date().toISOString().slice(0, 10), total_assesi: '', revenue: '', status: 'planned' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try { await http.post('/certification/batches', form); notify('Batch sertifikasi berhasil dibuat.'); onSaved(); }
        catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Batch sertifikasi tidak dapat dibuat.'), 'error'); }
        finally { setBusy(false); }
    };

    return <Modal title="Buat batch sertifikasi" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><div className="form-grid"><label>Kode batch<input value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} required /><FieldError errors={errors} name="code" /></label><label>Tanggal asesmen<input type="date" value={form.assessment_date} onChange={(event) => setForm({ ...form, assessment_date: event.target.value })} required /><FieldError errors={errors} name="assessment_date" /></label></div><label>Skema sertifikasi<select value={form.certification_scheme_id} onChange={(event) => setForm({ ...form, certification_scheme_id: event.target.value })}>{catalogs.schemes.map((scheme) => <option key={scheme.id} value={scheme.id}>{scheme.code} · {scheme.name}</option>)}</select><FieldError errors={errors} name="certification_scheme_id" /></label><div className="form-grid"><label>TUK<select value={form.tuk_id} onChange={(event) => setForm({ ...form, tuk_id: event.target.value })}>{catalogs.tuks.map((tuk) => <option key={tuk.id} value={tuk.id}>{tuk.name} · {tuk.city}</option>)}</select><FieldError errors={errors} name="tuk_id" /></label><label>Asesor<select value={form.assessor_id} onChange={(event) => setForm({ ...form, assessor_id: event.target.value })}>{catalogs.assessors.map((assessor) => <option key={assessor.id} value={assessor.id}>{assessor.name} · {assessor.registration_no}</option>)}</select><FieldError errors={errors} name="assessor_id" /></label></div><div className="form-grid"><label>Jumlah asesi<input type="number" min="1" value={form.total_assesi} onChange={(event) => setForm({ ...form, total_assesi: event.target.value })} required /><FieldError errors={errors} name="total_assesi" /></label><label>Proyeksi pendapatan<input type="number" min="0" value={form.revenue} onChange={(event) => setForm({ ...form, revenue: event.target.value })} required /><FieldError errors={errors} name="revenue" /></label></div><label>Tahap awal<select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}><option value="planned">Direncanakan</option><option value="document_review">Verifikasi dokumen</option><option value="assessment">Asesmen</option></select></label><button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Simpan batch'}</button></form></Modal>;
}

function CertificationResultForm({ batch, onClose, onSaved }) {
    const [form, setForm] = useState({ passed: batch.passed || '', failed: batch.failed || '', assessment_completed_at: batch.assessment_completed_at ? localDateTimeValue(new Date(batch.assessment_completed_at)) : localDateTimeValue(), decision_at: batch.decision_at ? localDateTimeValue(new Date(batch.decision_at)) : localDateTimeValue(), status: 'decision' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const entered = Number(form.passed || 0) + Number(form.failed || 0);
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try {
            await http.patch(`/certification/batches/${batch.id}`, form);
            notify(`Hasil ${batch.code} tersimpan dan KPI sertifikasi dihitung ulang.`);
            onSaved();
        } catch (error) {
            setErrors(fieldErrors(error));
            notify(requestError(error, 'Hasil asesmen tidak dapat disimpan.'), 'error');
        } finally { setBusy(false); }
    };

    return <Modal title={`Catat hasil · ${batch.code}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Jumlah kompeten + belum kompeten harus sama dengan {batch.total_assesi} asesi. Setelah disimpan, tanggal jatuh tempo sertifikat dihitung otomatis 30 hari setelah keputusan.</p><div className="form-grid"><label>Kompeten<input type="number" min="0" max={batch.total_assesi} value={form.passed} onChange={(event) => setForm({ ...form, passed: event.target.value })} required /><FieldError errors={errors} name="passed" /></label><label>Belum kompeten<input type="number" min="0" max={batch.total_assesi} value={form.failed} onChange={(event) => setForm({ ...form, failed: event.target.value })} required /><FieldError errors={errors} name="failed" /></label></div><small className={entered === Number(batch.total_assesi) ? '' : 'field-error'}>{entered} / {batch.total_assesi} hasil dicatat</small><div className="form-grid"><label>Asesmen selesai<input type="datetime-local" value={form.assessment_completed_at} onChange={(event) => setForm({ ...form, assessment_completed_at: event.target.value })} required /><FieldError errors={errors} name="assessment_completed_at" /></label><label>Keputusan<input type="datetime-local" value={form.decision_at} onChange={(event) => setForm({ ...form, decision_at: event.target.value })} required /><FieldError errors={errors} name="decision_at" /></label></div><button className="primary-button" disabled={busy || entered !== Number(batch.total_assesi)} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Simpan keputusan'}</button></form></Modal>;
}

function CertificateIssuanceForm({ batch, onClose, onSaved }) {
    const remaining = Math.max(0, Number(batch.passed) - Number(batch.certificates_issued));
    const [form, setForm] = useState({ issued_count: remaining, issued_at: localDateTimeValue(), reference: '', notes: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try {
            await http.post(`/certification/batches/${batch.id}/issuances`, form);
            notify(`${form.issued_count} sertifikat ${batch.code} berhasil dicatat.`);
            onSaved();
        } catch (error) {
            setErrors(fieldErrors(error));
            notify(requestError(error, 'Penerbitan sertifikat tidak dapat dicatat.'), 'error');
        } finally { setBusy(false); }
    };

    return <Modal title={`Penerbitan sertifikat · ${batch.code}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Tersisa {remaining} sertifikat dari {batch.passed} peserta kompeten. Sistem menentukan tepat waktu/terlambat dari jatuh tempo {batch.certificate_due_at ? new Date(batch.certificate_due_at).toLocaleString('id-ID') : 'yang belum tersedia'}.</p><div className="form-grid"><label>Jumlah terbit<input type="number" min="1" max={remaining} value={form.issued_count} onChange={(event) => setForm({ ...form, issued_count: event.target.value })} required /><FieldError errors={errors} name="issued_count" /></label><label>Waktu terbit<input type="datetime-local" value={form.issued_at} onChange={(event) => setForm({ ...form, issued_at: event.target.value })} required /><FieldError errors={errors} name="issued_at" /></label></div><label>Referensi penerbitan<input value={form.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} placeholder="Nomor register / batch sertifikat" /><FieldError errors={errors} name="reference" /></label><label>Catatan<textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></label><button className="primary-button" disabled={busy || Number(form.issued_count) < 1 || Number(form.issued_count) > remaining} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Catat penerbitan'}</button></form></Modal>;
}

function FinancePage() {
    const { user } = useAuth();
    const period = useReportingPeriod();
    const remote = useRemote(`/finance?${period.query}`);
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const [selectedInvoice, setSelectedInvoice] = useState(null);
    const [selectedRecord, setSelectedRecord] = useState(null);
    const [selectedPayment, setSelectedPayment] = useState(null);
    const notify = useToast();
    const canLedger = hasPermission(user, 'finance.ledger.manage');
    const canInvoice = hasPermission(user, 'finance.invoices.manage');
    const canPayment = hasPermission(user, 'finance.payments.manage');
    const canReverse = hasPermission(user, 'finance.reverse');
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const money = (n, compact = true) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', notation: compact ? 'compact' : 'standard', maximumFractionDigits: compact ? 1 : 0 }).format(Number(n || 0));
    const trend = { labels: data.monthly.map((row) => row.period), datasets: [{ label: 'Pendapatan', data: data.monthly.map((row) => row.revenue), borderColor: '#4b3f88', tension: .35, pointRadius: 2 }, { label: 'Beban', data: data.monthly.map((row) => row.expense), borderColor: '#b16ac0', tension: .35, pointRadius: 2 }, { label: 'Anggaran', data: data.monthly.map((row) => row.budget), borderColor: '#4f75b8', borderDash: [5, 5], tension: .25, pointRadius: 0 }] };
    const mix = { labels: data.expense_mix.map((item) => item.category), datasets: [{ data: data.expense_mix.map((item) => item.amount), backgroundColor: ['#4b3f88', '#7656a8', '#9a5bb3', '#b16ac0', '#4f75b8', '#8da1d4'], borderWidth: 0 }] };
    const flow = { labels: ['Pendapatan', 'Beban'], datasets: [{ data: [data.summary.revenue, data.summary.expense], backgroundColor: ['#4b3f88', '#b16ac0'], borderWidth: 0 }] };
    const trendOptions = { ...glanceChartOptions, plugins: { ...glanceChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 7, font: { size: 9 } } } }, scales: { ...glanceChartOptions.scales, y: { ...glanceChartOptions.scales.y, ticks: { ...glanceChartOptions.scales.y.ticks, callback: (value) => money(value) } } } };

    const openInvoice = (invoice, type) => { setSelectedInvoice(invoice); setModal(type); };
    const closeModal = () => { setModal(null); setSelectedInvoice(null); setSelectedRecord(null); setSelectedPayment(null); };
    const saved = () => { closeModal(); reload(); };
    return <Page>
        <GlanceHeader eyebrow="Kesehatan finansial" title="Finance at a Glance" description="Ledger, piutang, pembayaran, dan KPI dihitung dari sumber transaksi yang sama serta mempertahankan jejak koreksi." period={period} remote={remote} actions={<>{canInvoice && <button className="secondary-button compact" onClick={() => setModal('invoice')}><Plus size={17} />Buat invoice</button>}{canLedger && <button className="primary-button compact" onClick={() => setModal('record')}><Plus size={17} />Catat ledger</button>}</>} />
        <div className="dashboard-layout"><GlanceRail primaryIcon={WalletCards} primaryTitle={<>Arus<br />Keuangan</>} primaryDescription="Baca pendapatan dan beban bersih setelah reversal tanpa menghapus histori transaksi." secondaryIcon={Target} secondaryTitle={<>Piutang<br />Terkendali</>} secondaryDescription="Pantau saldo terbuka, aging, pembayaran, dan rekonsiliasi invoice ke ledger." /><div className="dashboard-main">
            <section className="dashboard-band band-primary animate-in"><div className="band-summary"><span>Pendapatan 12 bulan</span><strong className="band-money">{money(data.summary.revenue)}</strong><small>net ledger setelah reversal</small></div><article className="dashboard-chart"><h2>Pendapatan vs beban</h2><AccessibleChart label="Perbandingan pendapatan dan beban" summary={`Pendapatan ${money(data.summary.revenue, false)}, beban ${money(data.summary.expense, false)}.`} hasData={Number(data.summary.revenue) + Number(data.summary.expense) > 0}><Doughnut data={flow} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><h2>Arus bulanan</h2><AccessibleChart label="Tren arus keuangan bulanan" summary={`Margin operasional ${data.summary.operating_margin} persen dan deviasi anggaran ${data.summary.budget_variance} persen.`} hasData={data.monthly.some((row) => Number(row.revenue) + Number(row.expense) + Number(row.budget) > 0)}><Line data={trend} options={trendOptions} /></AccessibleChart></article><div className="band-stat-list"><div><span>Margin operasional</span><strong>{data.summary.operating_margin}%</strong><small>pendapatan dikurangi beban</small></div><div><span>Deviasi anggaran</span><strong>{data.summary.budget_variance}%</strong><small>selisih absolut realisasi</small></div></div></section>
            <section className="dashboard-band band-secondary animate-in"><div className="band-summary"><span>Piutang terbuka</span><strong className="band-money">{money(data.summary.open_receivable)}</strong><small>{data.summary.open_invoices} invoice belum lunas</small></div><article className="dashboard-chart"><h2>Komposisi biaya</h2><AccessibleChart label="Komposisi biaya" summary={data.expense_mix.map((item) => `${item.category} ${money(item.amount, false)}`).join(', ')} hasData={data.expense_mix.length > 0}><Doughnut data={mix} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><h2>Aging piutang</h2><EvidenceBars rows={data.aging.map((row) => ({ label: row.bucket, value: row.amount, display: money(row.amount) }))} /></article><div className="band-stat-list"><div><span>Piutang jatuh tempo</span><strong>{money(data.summary.overdue_receivable)}</strong><small>saldo melewati due date</small></div><div><span>Pembayaran diterima</span><strong>{money(data.summary.payments_received)}</strong><small>dalam jendela analisis</small></div></div></section>
            <PrototypeNotice period={data.period} />
            <section className="dashboard-evidence-grid grid-flow-dense"><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Rekonsiliasi invoice</h3><span>{data.reconciliation.is_reconciled ? 'sinkron' : 'perlu pemeriksaan'}</span></div><EvidenceList rows={[{ title: 'Invoice aktif s.d. periode', detail: 'Nilai invoice yang belum di-void sampai akhir periode', trailing: <b>{money(data.reconciliation.invoice_amount)}</b> }, { title: 'Posting ledger invoice', detail: 'Pendapatan dari sub-ledger invoice', trailing: <b>{money(data.reconciliation.invoice_ledger_revenue)}</b> }, { title: 'Selisih', detail: data.reconciliation.is_reconciled ? 'Tidak ada selisih rekonsiliasi' : 'Perlu penelusuran sumber', tone: data.reconciliation.is_reconciled ? 'on_track' : 'critical', trailing: <b>{money(data.reconciliation.difference, false)}</b> }]} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Penggerak beban</h3><span>{data.expense_mix.length} kategori</span></div><div className="evidence-donut"><Doughnut data={mix} options={glanceDonutOptions} /></div></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Piutang terbesar</h3><span>saldo terbuka</span></div><EvidenceList rows={data.invoices.filter((item) => Number(item.outstanding) > 0).sort((a, b) => Number(b.outstanding) - Number(a.outstanding)).slice(0, 4).map((item) => ({ key: item.id, title: item.customer_name, detail: `${item.invoice_number} · jatuh tempo ${new Date(item.due_on).toLocaleDateString('id-ID')}`, tone: item.days_overdue > 0 ? 'critical' : 'watch', trailing: <b>{money(item.outstanding)}</b> }))} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Ledger terbaru</h3><span>{data.records.length} entri</span></div><EvidenceList rows={data.records.slice(0, 4).map((record) => ({ key: record.id, title: record.category, detail: `${record.reference || 'Tanpa referensi'} · ${record.entry_kind === 'reversal' ? 'reversal' : record.type}`, tone: record.entry_kind === 'reversal' ? 'critical' : record.type === 'revenue' ? 'on_track' : 'watch', trailing: <b>{money(record.net_amount)}</b> }))} /></article></section>

            <DataTable title="Register invoice & piutang" columns={['Invoice', 'Pelanggan', 'Jatuh tempo', 'Nilai', 'Terbayar', 'Saldo', 'Status', 'Tindakan']} rows={data.invoices.map((invoice) => ({ key: invoice.id, cells: [<strong>{invoice.invoice_number}</strong>, invoice.customer_name, <div>{new Date(invoice.due_on).toLocaleDateString('id-ID')}<small>{invoice.days_overdue > 0 ? `${invoice.days_overdue} hari terlambat` : 'belum jatuh tempo'}</small></div>, money(invoice.amount, false), money(invoice.paid, false), <strong>{money(invoice.outstanding, false)}</strong>, <StatusBadge status={invoice.status} />, <div className="table-actions">{canPayment && Number(invoice.outstanding) > 0 && invoice.status !== 'void' ? <button className="table-action" type="button" onClick={() => openInvoice(invoice, 'payment')}><Banknote size={14} />Bayar</button> : null}{canReverse && invoice.status !== 'void' ? <button className="table-action" type="button" onClick={() => openInvoice(invoice, 'void')}><X size={14} />Void</button> : null}{canReverse && invoice.payments?.filter((payment) => !payment.reversed_at).slice(-1).map((payment) => <button key={payment.id} className="table-action" type="button" onClick={() => { setSelectedPayment(payment); setModal('reverse-payment'); }}><RefreshCw size={14} />Reverse bayar</button>)}</div>] }))} />
            <DataTable title="Ledger keuangan" columns={['Referensi', 'Tanggal', 'Jenis', 'Kategori', 'Nilai bersih', 'Sumber', 'Tindakan']} rows={data.records.map((record) => ({ key: record.id, cells: [record.reference || '—', new Date(record.recorded_on).toLocaleDateString('id-ID'), <StatusBadge status={record.entry_kind === 'reversal' ? 'critical' : record.type === 'revenue' ? 'on_track' : record.type === 'expense' ? 'watch' : 'open'} />, record.category, <strong>{money(record.net_amount, false)}</strong>, record.source_type || 'manual', canReverse && record.entry_kind === 'normal' && !record.is_reversed && record.source_type !== 'finance_invoice' ? <button className="table-action" type="button" onClick={() => { setSelectedRecord(record); setModal('reverse'); }}><RefreshCw size={14} />Reverse</button> : <span className="muted">Terkunci audit</span>] }))} />
        </div></div>
        {modal === 'record' && canLedger && <FinanceForm onClose={closeModal} onSaved={saved} />}
        {modal === 'invoice' && canInvoice && <FinanceInvoiceForm onClose={closeModal} onSaved={saved} />}
        {modal === 'payment' && canPayment && selectedInvoice && <FinancePaymentForm invoice={selectedInvoice} onClose={closeModal} onSaved={saved} />}
        {modal === 'void' && canReverse && selectedInvoice && <FinanceVoidInvoiceForm invoice={selectedInvoice} onClose={closeModal} onSaved={saved} />}
        {modal === 'reverse' && canReverse && selectedRecord && <FinanceReverseForm record={selectedRecord} onClose={closeModal} onSaved={saved} />}
        {modal === 'reverse-payment' && canReverse && selectedPayment && <FinanceReversePaymentForm payment={selectedPayment} onClose={closeModal} onSaved={saved} />}
    </Page>;
}

function FinanceForm({ onClose, onSaved }) {
    const [form, setForm] = useState({ recorded_on: new Date().toISOString().slice(0, 10), type: 'expense', category: '', amount: '', description: '', reference: `TRX-${Date.now().toString().slice(-8)}` });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/finance/records', form); notify('Entri ledger berhasil dicatat.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Pencatatan gagal.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Catat ledger keuangan" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Gunakan ledger manual untuk beban, anggaran, atau pendapatan yang tidak melalui invoice. Koreksi dilakukan dengan reversal, bukan menghapus transaksi.</p><div className="form-grid"><label>Tanggal<input type="date" value={form.recorded_on} onChange={(e) => setForm({ ...form, recorded_on: e.target.value })} required /><FieldError errors={errors} name="recorded_on" /></label><label>Jenis<select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}><option value="revenue">Pendapatan</option><option value="expense">Beban</option><option value="budget">Anggaran</option></select><FieldError errors={errors} name="type" /></label></div><label>Kategori<input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} required /><FieldError errors={errors} name="category" /></label><label>Nilai rupiah<input type="number" min="1" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} required /><FieldError errors={errors} name="amount" /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /><FieldError errors={errors} name="description" /></label><label>Referensi<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} /><FieldError errors={errors} name="reference" /></label><button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Simpan ledger'}</button></form></Modal>;
}

function FinanceInvoiceForm({ onClose, onSaved }) {
    const today = new Date().toISOString().slice(0, 10);
    const due = new Date(); due.setDate(due.getDate() + 30);
    const [form, setForm] = useState({ invoice_number: `INV-${new Date().getFullYear()}-${Date.now().toString().slice(-6)}`, issued_on: today, due_on: due.toISOString().slice(0, 10), customer_name: '', category: 'Jasa sertifikasi', department_code: 'finance', amount: '', description: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/finance/invoices', form); notify('Invoice dibuat dan pendapatan otomatis diposting ke ledger.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Invoice tidak dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Buat invoice" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Invoice adalah sub-ledger piutang. Saat dibuat, sistem otomatis membuat satu posting pendapatan yang dapat direkonsiliasi.</p><div className="form-grid"><label>Nomor invoice<input value={form.invoice_number} onChange={(e) => setForm({ ...form, invoice_number: e.target.value })} required /><FieldError errors={errors} name="invoice_number" /></label><label>Pelanggan<input value={form.customer_name} onChange={(e) => setForm({ ...form, customer_name: e.target.value })} required /><FieldError errors={errors} name="customer_name" /></label></div><div className="form-grid"><label>Tanggal terbit<input type="date" value={form.issued_on} onChange={(e) => setForm({ ...form, issued_on: e.target.value })} required /><FieldError errors={errors} name="issued_on" /></label><label>Jatuh tempo<input type="date" value={form.due_on} onChange={(e) => setForm({ ...form, due_on: e.target.value })} required /><FieldError errors={errors} name="due_on" /></label></div><label>Kategori<input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} required /></label><label>Nilai invoice<input type="number" min="1" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} required /><FieldError errors={errors} name="amount" /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /><FieldError errors={errors} name="description" /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Terbitkan invoice'}</button></form></Modal>;
}

function FinancePaymentForm({ invoice, onClose, onSaved }) {
    const [form, setForm] = useState({ paid_on: new Date().toISOString().slice(0, 10), amount: invoice.outstanding, reference: `PAY-${Date.now().toString().slice(-8)}`, notes: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/finance/invoices/${invoice.id}/payments`, form); notify('Pembayaran berhasil dicatat.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Pembayaran gagal dicatat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Pembayaran · ${invoice.invoice_number}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Saldo piutang saat ini {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(invoice.outstanding)}. Sistem menolak pembayaran melebihi saldo.</p><div className="form-grid"><label>Tanggal bayar<input type="date" value={form.paid_on} onChange={(e) => setForm({ ...form, paid_on: e.target.value })} required /><FieldError errors={errors} name="paid_on" /></label><label>Nilai pembayaran<input type="number" min="1" max={invoice.outstanding} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} required /><FieldError errors={errors} name="amount" /></label></div><label>Referensi pembayaran<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} required /><FieldError errors={errors} name="reference" /></label><label>Catatan<textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Catat pembayaran'}</button></form></Modal>;
}

function FinanceVoidInvoiceForm({ invoice, onClose, onSaved }) {
    const [form, setForm] = useState({ voided_on: new Date().toISOString().slice(0, 10), reason: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/finance/invoices/${invoice.id}/void`, form); notify('Invoice di-void dan posting pendapatan direversal.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Invoice tidak dapat di-void.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Void invoice · ${invoice.invoice_number}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Void tidak menghapus invoice. Sistem membuat reversal pendapatan dan menyimpan alasan audit. Pembayaran aktif harus direversal lebih dulu.</p><label>Tanggal void<input type="date" value={form.voided_on} onChange={(e) => setForm({ ...form, voided_on: e.target.value })} required /></label><label>Alasan<textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required /><FieldError errors={errors} name="reason" /><FieldError errors={errors} name="invoice" /></label><button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : 'Void invoice'}</button></form></Modal>;
}

function FinanceReverseForm({ record, onClose, onSaved }) {
    const [form, setForm] = useState({ recorded_on: new Date().toISOString().slice(0, 10), reason: '', reference: `REV-${record.reference || record.id}` });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/finance/records/${record.id}/reverse`, form); notify('Reversal ledger berhasil dibuat tanpa menghapus transaksi asli.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Reversal tidak dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Reverse · ${record.reference || record.id}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Transaksi asli akan tetap tersimpan. Entri reversal bernilai berlawanan akan mengoreksi saldo dan KPI pada tanggal reversal.</p><label>Tanggal reversal<input type="date" value={form.recorded_on} onChange={(e) => setForm({ ...form, recorded_on: e.target.value })} required /></label><label>Referensi reversal<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} /><FieldError errors={errors} name="reference" /></label><label>Alasan<textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required /><FieldError errors={errors} name="reason" /><FieldError errors={errors} name="record" /></label><button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : 'Buat reversal'}</button></form></Modal>;
}

function FinanceReversePaymentForm({ payment, onClose, onSaved }) {
    const [form, setForm] = useState({ reason: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/finance/payments/${payment.id}/reverse`, form); notify('Pembayaran direversal dan saldo piutang dihitung ulang.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Pembayaran tidak dapat direversal.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Reverse pembayaran · ${payment.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Pembayaran asli tetap tersimpan sebagai bukti audit. Setelah reversal, saldo invoice dan status pembayaran akan dihitung ulang.</p><label>Alasan reversal<textarea value={form.reason} onChange={(e) => setForm({ reason: e.target.value })} required /><FieldError errors={errors} name="reason" /><FieldError errors={errors} name="payment" /></label><button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : 'Reverse pembayaran'}</button></form></Modal>;
}

function ItPage() {
    const { user } = useAuth();
    const period = useReportingPeriod();
    const remote = useRemote(`/it?${period.query}`);
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const [selectedIncident, setSelectedIncident] = useState(null);
    const [transitioningId, setTransitioningId] = useState(null);
    const notify = useToast();
    const canManageIncidents = hasPermission(user, 'it.incidents.manage');
    const canManageQuality = hasPermission(user, 'it.data_quality.manage');
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const chart = { labels: data.monthly.map((row) => row.period), datasets: [{ label: 'Insiden', data: data.monthly.map((row) => row.incidents), backgroundColor: '#4b3f88', borderRadius: 3 }, { label: 'Downtime (jam)', data: data.monthly.map((row) => row.downtime_hours), backgroundColor: '#b16ac0', borderRadius: 3 }] };
    const downtimeTrend = { labels: data.monthly.map((row) => row.period), datasets: [{ label: 'Downtime', data: data.monthly.map((row) => row.downtime_hours), borderColor: '#4b3f88', backgroundColor: 'rgba(75,63,136,.10)', fill: true, tension: .35, pointRadius: 2 }] };
    const qualityTrend = { labels: data.monthly.map((row) => row.period), datasets: [{ label: 'Kualitas data', data: data.monthly.map((row) => row.data_quality), borderColor: '#4b3f88', backgroundColor: 'rgba(75,63,136,.08)', fill: true, tension: .35, pointRadius: 2 }] };
    const severity = { labels: data.severity_breakdown.map((item) => item.severity), datasets: [{ data: data.severity_breakdown.map((item) => item.count), backgroundColor: ['#8da1d4', '#7656a8', '#b16ac0', '#4b3f88'], borderWidth: 0 }] };
    const serviceStatusCounts = data.services.reduce((counts, service) => { counts[service.status] = (counts[service.status] || 0) + 1; return counts; }, {});
    const serviceStatus = { labels: ['Operasional', 'Terdegradasi', 'Pemeliharaan'], datasets: [{ data: [serviceStatusCounts.operational || 0, serviceStatusCounts.degraded || 0, serviceStatusCounts.maintenance || 0], backgroundColor: ['#4b3f88', '#b16ac0', '#4f75b8'], borderWidth: 0 }] };
    const totalDowntime = data.monthly.reduce((total, row) => total + Number(row.downtime_hours), 0);
    const totalIncidents = data.monthly.reduce((total, row) => total + Number(row.incidents), 0);

    const investigate = async (incident) => {
        setTransitioningId(incident.id);
        try {
            await http.patch(`/it/incidents/${incident.id}/investigate`);
            notify('Insiden masuk tahap investigasi.');
            reload();
        } catch (transitionError) {
            notify(requestError(transitionError, 'Status insiden tidak dapat diperbarui.'), 'error');
        } finally {
            setTransitioningId(null);
        }
    };
    const openResolve = (incident) => { setSelectedIncident(incident); setModal('resolve'); };
    const closeModal = () => { setModal(null); setSelectedIncident(null); };
    const saved = () => { closeModal(); reload(); };

    return <Page>
        <GlanceHeader eyebrow="Reliabilitas digital" title="Technology at a Glance" description="Ketersediaan layanan, insiden, waktu pemulihan, dan mutu data dihitung dari ledger operasional yang dapat diaudit." period={period} remote={remote} actions={<>{canManageQuality && <button className="secondary-button compact" onClick={() => setModal('quality')}><Database size={17} />Audit data</button>}{canManageIncidents && <button className="primary-button compact" onClick={() => setModal('incident')}><Plus size={17} />Catat insiden</button>}</>} />
        <div className="dashboard-layout"><GlanceRail primaryIcon={Server} primaryTitle={<>Reliabilitas<br />Sistem</>} primaryDescription="Pantau ketersediaan per layanan tanpa double-count saat insiden tumpang tindih." secondaryIcon={RefreshCw} secondaryTitle={<>Respons &<br />Mutu Data</>} secondaryDescription="Ukur pemulihan insiden dan kualitas dataset operasional secara terpisah." /><div className="dashboard-main">
            <section className="dashboard-band band-primary animate-in"><div className="band-summary"><span>Ketersediaan sistem</span><strong>{data.summary.uptime}%</strong><small>downtime unik lintas layanan</small></div><article className="dashboard-chart"><h2>Keparahan insiden</h2><AccessibleChart label="Keparahan insiden" summary={data.severity_breakdown.map((item) => `${item.severity} ${item.count}`).join(', ')} hasData={data.severity_breakdown.some((item) => Number(item.count) > 0)}><Doughnut data={severity} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><h2>Insiden dan downtime</h2><AccessibleChart label="Insiden dan downtime bulanan" summary={`${totalIncidents} insiden dengan total downtime unik ${totalDowntime.toFixed(1)} jam.`} hasData={totalIncidents > 0}><Bar data={chart} options={{ ...glanceChartOptions, plugins: { ...glanceChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 7 } } } }} /></AccessibleChart></article><div className="band-stat-list"><div><span>Insiden terbuka</span><strong>{data.summary.open_incidents}</strong><small>aktif pada akhir periode</small></div><div><span>Total downtime</span><strong>{Number(data.summary.downtime_hours).toLocaleString('id-ID')}</strong><small>jam setelah interval digabung</small></div></div></section>
            <section className="dashboard-band band-secondary animate-in"><div className="band-summary"><span>Waktu pemulihan</span><strong>{data.summary.mttr_hours}</strong><small>jam rata-rata cohort resolved</small></div><article className="dashboard-chart"><h2>Status layanan</h2><AccessibleChart label="Status layanan teknologi" summary={`${serviceStatusCounts.operational || 0} operasional, ${serviceStatusCounts.degraded || 0} terdegradasi, ${serviceStatusCounts.maintenance || 0} pemeliharaan.`} hasData={data.services.length > 0}><Doughnut data={serviceStatus} options={glanceDonutOptions} /></AccessibleChart></article><article className="dashboard-chart trend-chart"><h2>Mutu data bulanan</h2><AccessibleChart label="Tren kualitas data" summary={`Skor mutu data periode terakhir ${data.summary.data_quality}%.`} hasData={data.monthly.some((row) => Number(row.data_quality) > 0)}><Line data={qualityTrend} options={{ ...glanceChartOptions, plugins: { ...glanceChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 7 } } }, scales: { ...glanceChartOptions.scales, y: { ...glanceChartOptions.scales.y, min: 0, max: 100 } } }} /></AccessibleChart></article><div className="band-stat-list"><div><span>Kualitas data</span><strong>{data.summary.data_quality}%</strong><small>{Number(data.summary.quality_records).toLocaleString('id-ID')} rekam diaudit</small></div><div><span>Insiden kritis</span><strong>{data.summary.critical_incidents}</strong><small>dimulai dalam jendela analisis</small></div></div></section>
            <PrototypeNotice period={data.period} />
            <section className="dashboard-evidence-grid grid-flow-dense"><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Downtime per bulan</h3><span>5 bulan terakhir</span></div><EvidenceBars rows={data.monthly.slice(-5).map((row) => ({ label: row.period, value: row.downtime_hours, display: `${row.downtime_hours} jam` }))} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Kesehatan layanan</h3><span>{data.services.length} layanan</span></div><div className="evidence-donut"><Doughnut data={serviceStatus} options={glanceDonutOptions} /></div></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Peta layanan</h3><span>availability periode</span></div><EvidenceList rows={data.services.slice(0, 4).map((service) => ({ key: service.id, title: service.name, detail: `${service.uptime}% uptime · ${service.open_incidents} insiden aktif`, tone: service.meets_target ? 'operational' : 'degraded', trailing: <StatusBadge status={service.meets_target ? 'on_track' : 'watch'} /> }))} /></article><article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Audit data terbaru</h3><span>{data.data_quality_runs.length} rekaman</span></div><EvidenceList rows={data.data_quality_runs.slice(0, 4).map((run) => ({ key: run.id, title: run.dataset_name, detail: `${run.source_system} · ${Number(run.total_records).toLocaleString('id-ID')} rekam`, tone: run.quality_score >= 98 ? 'operational' : 'degraded', trailing: <strong>{run.quality_score}%</strong> }))} /></article></section>
            <DataTable title="Reliabilitas per layanan" columns={['Layanan', 'Pemilik', 'Uptime', 'Target', 'Downtime', 'Insiden aktif', 'Status']} rows={data.services.map((service) => ({ key: service.id, cells: [<strong>{service.name}</strong>, service.owner, `${service.uptime}%`, `${service.target_uptime}%`, `${service.downtime_hours} jam`, service.open_incidents, <StatusBadge status={service.meets_target ? 'on_track' : 'watch'} />] }))} />
            <DataTable title="Register insiden" columns={['Referensi', 'Layanan', 'Mulai', 'Dampak', 'Status', 'Tindakan']} rows={data.incidents.map((incident) => ({ key: incident.id, cells: [<strong>{incident.reference}</strong>, <div><strong>{incident.service.name}</strong><small>{incident.summary}</small></div>, new Date(incident.started_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }), <StatusBadge status={incident.severity} />, <StatusBadge status={incident.status} />, canManageIncidents && incident.status === 'open' ? <button className="table-action" type="button" disabled={transitioningId === incident.id} onClick={() => investigate(incident)}>{transitioningId === incident.id ? <RefreshCw className="spin" size={15} aria-hidden="true" /> : <RefreshCw size={15} aria-hidden="true" />}{transitioningId === incident.id ? 'Menyimpan…' : 'Mulai investigasi'}</button> : canManageIncidents && incident.status === 'investigating' ? <button className="table-action" type="button" onClick={() => openResolve(incident)}><Check size={15} aria-hidden="true" />Selesaikan</button> : <span className="muted">Terekam</span>] }))} />
            <DataTable title="Ledger kualitas data" columns={['Referensi', 'Dataset', 'Sumber', 'Dinilai', 'Valid / Total', 'Skor', 'Temuan']} rows={data.data_quality_runs.map((run) => ({ key: run.id, cells: [<strong>{run.reference}</strong>, run.dataset_name, run.source_system, new Date(run.assessed_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }), `${Number(run.valid_records).toLocaleString('id-ID')} / ${Number(run.total_records).toLocaleString('id-ID')}`, <strong>{run.quality_score}%</strong>, `${run.missing_required_records} missing · ${run.duplicate_records} duplikat · ${run.freshness_failures} stale`] }))} />
        </div></div>
        {modal === 'incident' && canManageIncidents && <IncidentForm services={data.services} onClose={closeModal} onSaved={saved} />}
        {modal === 'resolve' && canManageIncidents && selectedIncident && <IncidentResolveForm incident={selectedIncident} onClose={closeModal} onSaved={saved} />}
        {modal === 'quality' && canManageQuality && <DataQualityRunForm onClose={closeModal} onSaved={saved} />}
    </Page>;
}

function IncidentForm({ services, onClose, onSaved }) {
    const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const [form, setForm] = useState({ it_service_id: services[0]?.id || '', reference: `INC-${new Date().getFullYear()}-${Date.now().toString().slice(-5)}`, severity: 'medium', started_at: localNow, summary: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/it/incidents', form); notify('Insiden berhasil dicatat.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Insiden tidak dapat dicatat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Catat insiden layanan" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Insiden baru dimulai pada status Open. Mulai investigasi sebelum insiden dapat ditutup agar lifecycle dan audit trail tetap jelas.</p><label>Layanan<select value={form.it_service_id} onChange={(e) => setForm({ ...form, it_service_id: e.target.value })}>{services.map((service) => <option value={service.id} key={service.id}>{service.name}</option>)}</select><FieldError errors={errors} name="it_service_id" /></label><div className="form-grid"><label>Referensi<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} required /><FieldError errors={errors} name="reference" /></label><label>Dampak<select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}><option value="low">Rendah</option><option value="medium">Sedang</option><option value="high">Tinggi</option><option value="critical">Kritis</option></select><FieldError errors={errors} name="severity" /></label></div><label>Waktu mulai<input type="datetime-local" value={form.started_at} onChange={(e) => setForm({ ...form, started_at: e.target.value })} required /><FieldError errors={errors} name="started_at" /></label><label>Ringkasan<textarea value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} required /><FieldError errors={errors} name="summary" /></label><button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? 'Menyimpan…' : 'Simpan insiden'}</button></form></Modal>;
}

function IncidentResolveForm({ incident, onClose, onSaved }) {
    const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const [form, setForm] = useState({ resolved_at: localNow, resolution_summary: '', root_cause: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.patch(`/it/incidents/${incident.id}/resolve`, form); notify('Insiden selesai dan bukti resolusi tersimpan.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Insiden tidak dapat diselesaikan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Selesaikan · ${incident.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">MTTR dihitung dari waktu mulai hingga waktu selesai. Ringkasan resolusi wajib disimpan untuk menjaga keterlacakan operasional.</p><label>Waktu selesai<input type="datetime-local" value={form.resolved_at} onChange={(e) => setForm({ ...form, resolved_at: e.target.value })} required /><FieldError errors={errors} name="resolved_at" /></label><label>Ringkasan resolusi<textarea value={form.resolution_summary} onChange={(e) => setForm({ ...form, resolution_summary: e.target.value })} required /><FieldError errors={errors} name="resolution_summary" /><FieldError errors={errors} name="status" /></label><label>Akar masalah<textarea value={form.root_cause} onChange={(e) => setForm({ ...form, root_cause: e.target.value })} placeholder="Opsional, isi setelah RCA tersedia" /><FieldError errors={errors} name="root_cause" /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Selesaikan insiden'}</button></form></Modal>;
}

function DataQualityRunForm({ onClose, onSaved }) {
    const localNow = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const [form, setForm] = useState({ reference: `DQ-${new Date().getFullYear()}-${Date.now().toString().slice(-6)}`, dataset_name: '', source_system: '', assessed_at: localNow, total_records: '', valid_records: '', missing_required_records: 0, duplicate_records: 0, freshness_failures: 0, notes: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/it/data-quality-runs', form); notify('Audit kualitas data berhasil dicatat dan KPI diperbarui otomatis.'); onSaved(); } catch (err) { setErrors(fieldErrors(err)); notify(requestError(err, 'Audit data tidak dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Catat audit kualitas data" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">IT-DATA dihitung otomatis sebagai total rekam valid dibagi total rekam yang diaudit pada periode yang sama. Temuan dapat tumpang tindih dan disimpan sebagai evidence terpisah.</p><div className="form-grid"><label>Referensi<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} required /><FieldError errors={errors} name="reference" /></label><label>Waktu audit<input type="datetime-local" value={form.assessed_at} onChange={(e) => setForm({ ...form, assessed_at: e.target.value })} required /><FieldError errors={errors} name="assessed_at" /></label></div><div className="form-grid"><label>Dataset<input value={form.dataset_name} onChange={(e) => setForm({ ...form, dataset_name: e.target.value })} required /><FieldError errors={errors} name="dataset_name" /></label><label>Sistem sumber<input value={form.source_system} onChange={(e) => setForm({ ...form, source_system: e.target.value })} required /><FieldError errors={errors} name="source_system" /></label></div><div className="form-grid"><label>Total rekam<input type="number" min="1" value={form.total_records} onChange={(e) => setForm({ ...form, total_records: e.target.value })} required /><FieldError errors={errors} name="total_records" /></label><label>Rekam valid<input type="number" min="0" value={form.valid_records} onChange={(e) => setForm({ ...form, valid_records: e.target.value })} required /><FieldError errors={errors} name="valid_records" /></label></div><div className="form-grid"><label>Missing required<input type="number" min="0" value={form.missing_required_records} onChange={(e) => setForm({ ...form, missing_required_records: e.target.value })} /></label><label>Duplikat<input type="number" min="0" value={form.duplicate_records} onChange={(e) => setForm({ ...form, duplicate_records: e.target.value })} /></label></div><label>Freshness failure<input type="number" min="0" value={form.freshness_failures} onChange={(e) => setForm({ ...form, freshness_failures: e.target.value })} /></label><label>Catatan<textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan audit data'}</button></form></Modal>;
}

function GovernancePage() {
    const period = useReportingPeriod();
    const remote = useRemote('/governance');
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const [selected, setSelected] = useState(null);
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const capaRows = data.findings.flatMap((finding) => (finding.actions || []).map((action) => ({ finding, action })));
    const openModal = (name, record = null) => { setSelected(record); setModal(name); };
    const closeModal = () => { setSelected(null); setModal(null); };
    const saved = () => { closeModal(); reload(); };

    return <Page>
        <GlanceHeader eyebrow="Governance & assurance" title="Mutu & Kepatuhan at a Glance" description="Temuan, CAPA, banding, masa berlaku, provenance data, rekonsiliasi, dan audit trail dalam satu register yang dapat ditelusuri." period={period} remote={remote} showPeriod={false} actions={<>{data.permissions.manage_findings && <button className="secondary-button compact" type="button" onClick={() => openModal('finding')}><Plus size={16} />Temuan</button>}{data.permissions.manage_appeals && <button className="secondary-button compact" type="button" onClick={() => openModal('appeal')}><Plus size={16} />Banding</button>}{data.permissions.manage_registry && <button className="primary-button compact" type="button" onClick={() => openModal('obligation')}><ShieldCheck size={16} />Obligation</button>}</>} />
        <section className="metric-grid governance-metrics animate-in">
            <MetricCard icon={AlertTriangle} label="Temuan terbuka" value={data.summary.open_findings} tone={data.summary.open_findings ? 'gold' : 'teal'} />
            <MetricCard icon={ClipboardCheck} label="CAPA terlambat" value={data.summary.overdue_capa} tone={data.summary.overdue_capa ? 'gold' : 'teal'} />
            <MetricCard icon={BadgeCheck} label="Banding aktif" value={data.summary.open_appeals} tone={data.summary.open_appeals ? 'violet' : 'teal'} />
            <MetricCard icon={Target} label="Risiko masa berlaku ≤90 hari" value={data.summary.expiry_risks_90d} tone={data.summary.expiry_risks_90d ? 'blue' : 'teal'} />
            <MetricCard icon={Database} label="Exception rekonsiliasi" value={data.summary.import_exceptions} tone={data.summary.import_exceptions ? 'gold' : 'teal'} />
        </section>

        <section className="dashboard-evidence-grid grid-flow-dense">
            <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Expiry watchlist</h3><span>{data.expiry_risks.length} risiko</span></div><EvidenceList rows={data.expiry_risks.slice(0, 6).map((risk) => ({ key: `${risk.type}-${risk.code}`, title: `${risk.code} · ${risk.name}`, detail: `${risk.type} · berlaku sampai ${new Date(risk.valid_until).toLocaleDateString('id-ID')} · ${risk.days_remaining} hari`, tone: risk.status, trailing: <StatusBadge status={risk.status} /> }))} /></article>
            <article className="dashboard-card animate-in"><div className="dashboard-card-head"><h3>Provenance terbaru</h3><span>{data.imports.length} batch</span></div><EvidenceList rows={data.imports.slice(0, 6).map((batch) => ({ key: batch.id, title: `${batch.reference} · ${batch.dataset_name}`, detail: `${batch.file_name} · SHA ${batch.sha256.slice(0, 10)}… · ${batch.accepted_rows}/${batch.total_rows} diterima`, tone: batch.reconciliation_status === 'exception' ? 'critical' : 'on_track', trailing: <StatusBadge status={batch.reconciliation_status} /> }))} /></article>
        </section>

        <DataTable title="Temuan kepatuhan" columns={['Referensi', 'Severity', 'Temuan', 'Pemilik', 'Tenggat', 'Status', 'Tindakan']} rows={data.findings.map((finding) => ({ key: finding.id, cells: [<strong>{finding.reference}</strong>, <StatusBadge status={finding.severity} />, <span><strong>{finding.title}</strong><br /><small>{finding.source} · {finding.category}</small></span>, finding.owner_name, finding.due_at ? new Date(finding.due_at).toLocaleDateString('id-ID') : '—', <StatusBadge status={finding.status} />, data.permissions.manage_findings ? <span className="inline-actions"><button type="button" onClick={() => openModal('capa', finding)} disabled={finding.status === 'closed'}>CAPA</button>{finding.status !== 'closed' && <button type="button" onClick={() => openModal('finding-close', finding)}>Tutup</button>}</span> : '—'] }))} />

        <DataTable title="Corrective & Preventive Action (CAPA)" columns={['Temuan', 'Aksi', 'Pemilik', 'Tenggat', 'Status', 'Evidence']} rows={capaRows.map(({ finding, action }) => ({ key: action.id, cells: [finding.reference, <strong>{action.title}</strong>, action.owner_name, new Date(action.due_date).toLocaleDateString('id-ID'), data.permissions.manage_findings ? <button type="button" className="table-action" onClick={() => openModal('capa-update', action)}><StatusBadge status={action.status} /></button> : <StatusBadge status={action.status} />, action.evidence || '—'] }))} />

        <DataTable title="Register banding sertifikasi" columns={['Referensi', 'Batch', 'Pemohon', 'Diterima', 'Tenggat', 'Status', 'Keputusan']} rows={data.appeals.map((appeal) => ({ key: appeal.id, cells: [<strong>{appeal.reference}</strong>, appeal.batch?.code || '—', appeal.appellant_reference, new Date(appeal.received_at).toLocaleDateString('id-ID'), appeal.due_at ? new Date(appeal.due_at).toLocaleDateString('id-ID') : '—', data.permissions.manage_appeals ? <button type="button" className="table-action" onClick={() => openModal('appeal-update', appeal)}><StatusBadge status={appeal.status} /></button> : <StatusBadge status={appeal.status} />, appeal.decision || '—'] }))} />

        <DataTable title="Register obligation & validity" columns={['Kode', 'Kewajiban', 'Otoritas', 'Berlaku sampai', 'Pemilik', 'Status']} rows={data.obligations.map((row) => ({ key: row.id, cells: [<strong>{row.code}</strong>, row.title, row.authority || '—', row.valid_until ? new Date(row.valid_until).toLocaleDateString('id-ID') : '—', row.owner_name, <StatusBadge status={row.status} />] }))} />

        <section className="master-data-workspace animate-in">
            <div className="master-data-context"><div><h2>Data provenance & reconciliation</h2><p>Setiap impor memiliki sumber, checksum SHA-256, hasil validasi, dan status rekonsiliasi.</p></div>{data.permissions.manage_provenance && <button className="secondary-button compact" type="button" onClick={() => openModal('source')}><Plus size={16} />Sumber data</button>}</div>
            <DataTable title="Import batches" columns={['Referensi', 'Dataset', 'Sumber', 'File / checksum', 'Diterima', 'Ditolak', 'Rekonsiliasi', 'Tindakan']} rows={data.imports.map((batch) => ({ key: batch.id, cells: [<strong>{batch.reference}</strong>, batch.dataset_name, batch.source?.name || 'Upload tanpa source register', <span>{batch.file_name}<br /><small>{batch.sha256}</small></span>, batch.accepted_rows, batch.rejected_rows, <StatusBadge status={batch.reconciliation_status} />, data.permissions.manage_provenance ? (batch.dataset_type ? <NavLink className="table-action" to="/integrations">Kelola integrasi</NavLink> : <button type="button" className="table-action" onClick={() => openModal('reconcile', batch)}>Rekonsiliasi</button>) : '—'] }))} />
            <DataTable title="Data source hierarchy" columns={['Kode', 'Sumber', 'Tipe', 'Authority rank', 'Pemilik', 'Status']} rows={data.data_sources.map((source) => ({ key: source.id, cells: [<strong>{source.code}</strong>, source.name, source.source_type, source.authority_rank, source.owner_name || '—', <StatusBadge status={source.is_active ? 'active' : 'inactive'} />] }))} />
        </section>

        {data.permissions.view_audit && <section className="master-activity panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Audit trail lintas modul</p><h2>Aktivitas terbaru</h2></div><span>{data.audit_logs.length} log</span></div><EvidenceList rows={data.audit_logs.map((log) => ({ key: log.id, title: `${log.action} · ${log.entity_type}${log.entity_id ? ` #${log.entity_id}` : ''}`, detail: `${log.user} · ${new Date(log.created_at).toLocaleString('id-ID')} · IP ${log.ip_address || '—'}`, tone: 'on_track', trailing: <span className="audit-action">Audit</span> }))} /></section>}

        {modal === 'finding' && data.permissions.manage_findings && <FindingForm onClose={closeModal} onSaved={saved} />}
        {modal === 'capa' && data.permissions.manage_findings && <CapaForm finding={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'finding-close' && data.permissions.manage_findings && <FindingCloseForm finding={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'capa-update' && data.permissions.manage_findings && <CapaUpdateForm action={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'appeal' && <AppealForm batches={data.catalogs.certification_batches} onClose={closeModal} onSaved={saved} />}
        {modal === 'appeal-update' && <AppealUpdateForm appeal={selected} onClose={closeModal} onSaved={saved} />}
        {modal === 'obligation' && data.permissions.manage_registry && <ObligationForm onClose={closeModal} onSaved={saved} />}
        {modal === 'source' && data.permissions.manage_provenance && <DataSourceForm onClose={closeModal} onSaved={saved} />}
        {modal === 'reconcile' && <ReconcileImportForm batch={selected} onClose={closeModal} onSaved={saved} />}
    </Page>;
}

function GovernanceSubmit({ url, method = 'post', form, onSaved, onError, success }) {
    return null;
}

function FindingForm({ onClose, onSaved }) {
    const [form, setForm] = useState({ reference: `FND-${new Date().getFullYear()}-`, source: 'Audit internal', category: 'Proses', severity: 'medium', title: '', description: '', owner_name: '', opened_at: new Date().toISOString().slice(0, 16), due_at: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/governance/findings', form); notify('Temuan kepatuhan berhasil dicatat.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Temuan belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Catat temuan kepatuhan" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><div className="form-grid"><label>Referensi<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} required /><FieldError errors={errors} name="reference" /></label><label>Severity<select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}><option value="low">Rendah</option><option value="medium">Sedang</option><option value="high">Tinggi</option><option value="critical">Kritis</option></select></label></div><div className="form-grid"><label>Sumber<input value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })} required /></label><label>Kategori<input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} required /></label></div><label>Judul<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /></label><div className="form-grid"><label>Pemilik<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /></label><label>Dibuka<input type="datetime-local" value={form.opened_at} onChange={(e) => setForm({ ...form, opened_at: e.target.value })} required /></label></div><label>Tenggat<input type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan temuan'}</button></form></Modal>;
}

function CapaForm({ finding, onClose, onSaved }) {
    const [form, setForm] = useState({ title: '', description: '', owner_name: finding?.owner_name || '', due_date: finding?.due_at ? String(finding.due_at).slice(0, 10) : '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/governance/findings/${finding.id}/actions`, form); notify('CAPA berhasil ditambahkan.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'CAPA belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Tambah CAPA · ${finding.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><label>Aksi<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /><FieldError errors={errors} name="title" /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /></label><div className="form-grid"><label>Pemilik<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /></label><label>Tenggat<input type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} required /></label></div><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan CAPA'}</button></form></Modal>;
}

function FindingCloseForm({ finding, onClose, onSaved }) {
    const [evidence, setEvidence] = useState(''); const [error, setError] = useState(''); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { await http.patch(`/governance/findings/${finding.id}`, { status: 'closed', closure_evidence: evidence }); notify('Temuan ditutup dengan evidence.'); onSaved(); } catch (err) { setError(requestError(err, 'Temuan belum dapat ditutup.')); } finally { setBusy(false); } };
    return <Modal title={`Tutup temuan · ${finding.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Semua CAPA harus berstatus selesai sebelum temuan dapat ditutup.</p><label>Bukti penutupan<textarea value={evidence} onChange={(e) => setEvidence(e.target.value)} required /></label>{error && <small className="field-error">{error}</small>}<button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : 'Tutup temuan'}</button></form></Modal>;
}

function CapaUpdateForm({ action, onClose, onSaved }) {
    const [form, setForm] = useState({ status: action.status, evidence: action.evidence || '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.patch(`/governance/actions/${action.id}`, form); notify('Status CAPA diperbarui.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'CAPA belum dapat diperbarui.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Perbarui CAPA · ${action.title}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><label>Status<select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}><option value="open">Terbuka</option><option value="in_progress">Dikerjakan</option><option value="blocked">Terhambat</option><option value="completed">Selesai</option></select></label><label>Evidence<textarea value={form.evidence} onChange={(e) => setForm({ ...form, evidence: e.target.value })} /><FieldError errors={errors} name="evidence" /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Perbarui CAPA'}</button></form></Modal>;
}

function AppealForm({ batches, onClose, onSaved }) {
    const [form, setForm] = useState({ certification_batch_id: batches[0]?.id || '', reference: `APL-${new Date().getFullYear()}-`, appellant_reference: '', received_at: new Date().toISOString().slice(0, 16), due_at: '', reason: '', owner_name: '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/governance/appeals', form); notify('Banding berhasil diregistrasikan.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Banding belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Registrasi banding sertifikasi" onClose={onClose}><form className="stack-form" onSubmit={submit}><label>Batch<select value={form.certification_batch_id} onChange={(e) => setForm({ ...form, certification_batch_id: e.target.value })}>{batches.map((batch) => <option value={batch.id} key={batch.id}>{batch.code}</option>)}</select></label><div className="form-grid"><label>Referensi<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} required /></label><label>Referensi pemohon<input value={form.appellant_reference} onChange={(e) => setForm({ ...form, appellant_reference: e.target.value })} required /></label></div><label>Alasan<textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required /></label><div className="form-grid"><label>Diterima<input type="datetime-local" value={form.received_at} onChange={(e) => setForm({ ...form, received_at: e.target.value })} required /></label><label>Tenggat<input type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></label></div><label>Pemilik<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan banding'}</button></form></Modal>;
}

function AppealUpdateForm({ appeal, onClose, onSaved }) {
    const [form, setForm] = useState({ status: appeal.status, decision: appeal.decision || '', resolution_summary: appeal.resolution_summary || '', decision_at: appeal.decision_at ? String(appeal.decision_at).slice(0, 16) : '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.patch(`/governance/appeals/${appeal.id}`, form); notify('Banding berhasil diperbarui.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Banding belum dapat diperbarui.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Proses banding · ${appeal.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><label>Status<select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}><option value="received">Diterima</option><option value="reviewing">Ditinjau</option><option value="decided">Diputuskan</option><option value="closed">Ditutup</option></select></label><label>Keputusan<select value={form.decision} onChange={(e) => setForm({ ...form, decision: e.target.value })}><option value="">Belum diputuskan</option><option value="maintained">Keputusan dipertahankan</option><option value="changed">Keputusan diubah</option><option value="reassessment">Asesmen ulang</option></select><FieldError errors={errors} name="decision" /></label><label>Ringkasan resolusi<textarea value={form.resolution_summary} onChange={(e) => setForm({ ...form, resolution_summary: e.target.value })} /></label><label>Waktu keputusan<input type="datetime-local" value={form.decision_at} onChange={(e) => setForm({ ...form, decision_at: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Perbarui banding'}</button></form></Modal>;
}

function ObligationForm({ onClose, onSaved }) {
    const [form, setForm] = useState({ code: '', title: '', category: 'license', authority: '', valid_from: '', valid_until: '', status: 'active', owner_name: '', evidence_reference: '', notes: '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/governance/obligations', form); notify('Obligation berhasil ditambahkan.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Obligation belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Tambah obligation register" onClose={onClose}><form className="stack-form" onSubmit={submit}><div className="form-grid"><label>Kode<input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required /></label><label>Kategori<input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} required /></label></div><label>Judul<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></label><div className="form-grid"><label>Otoritas<input value={form.authority} onChange={(e) => setForm({ ...form, authority: e.target.value })} /></label><label>Pemilik<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /></label></div><div className="form-grid"><label>Berlaku mulai<input type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} /></label><label>Berlaku sampai<input type="date" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} /></label></div><label>Referensi bukti<input value={form.evidence_reference} onChange={(e) => setForm({ ...form, evidence_reference: e.target.value })} /></label><label>Catatan<textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan obligation'}</button></form></Modal>;
}

function DataSourceForm({ onClose, onSaved }) {
    const [form, setForm] = useState({ code: '', name: '', source_type: 'internal', authority_rank: 80, owner_name: '', location: '', notes: '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/governance/data-sources', form); notify('Sumber data berhasil diregistrasikan.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Sumber data belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Registrasi sumber data" onClose={onClose}><form className="stack-form" onSubmit={submit}><div className="form-grid"><label>Kode<input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required /></label><label>Tipe<select value={form.source_type} onChange={(e) => setForm({ ...form, source_type: e.target.value })}><option value="internal">Internal</option><option value="regulatory">Regulatory</option><option value="public">Public</option><option value="manual">Manual</option></select></label></div><label>Nama sumber<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label><div className="form-grid"><label>Authority rank<input type="number" min="1" max="100" value={form.authority_rank} onChange={(e) => setForm({ ...form, authority_rank: e.target.value })} required /></label><label>Pemilik<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} /></label></div><label>Lokasi / endpoint<input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} /></label><label>Catatan<textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan sumber'}</button></form></Modal>;
}

function ReconcileImportForm({ batch, onClose, onSaved }) {
    const [form, setForm] = useState({ reconciliation_status: batch.reconciliation_status === 'reconciled' ? 'reconciled' : 'exception', notes: batch.notes || '' }); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { await http.patch(`/governance/import-batches/${batch.id}/reconcile`, form); notify('Status rekonsiliasi diperbarui.'); onSaved(); } catch (err) { setError(requestError(err, 'Rekonsiliasi belum dapat disimpan.')); } finally { setBusy(false); } };
    return <Modal title={`Rekonsiliasi · ${batch.reference}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Checksum: {batch.sha256}</p><label>Status<select value={form.reconciliation_status} onChange={(e) => setForm({ ...form, reconciliation_status: e.target.value })}><option value="reconciled">Terekonsiliasi</option><option value="exception">Pengecualian</option></select></label><label>Catatan rekonsiliasi<textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} required /></label>{error && <small className="field-error">{error}</small>}<button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan rekonsiliasi'}</button></form></Modal>;
}



function IntegrationPage() {
    const remote = useRemote('/integrations', 15000);
    const { data, loading, error, reload } = remote;
    const [showStage, setShowStage] = useState(false);
    const [mappingBatch, setMappingBatch] = useState(null);
    const [busyBatchId, setBusyBatchId] = useState(null);
    const notify = useToast();
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const datasetByType = Object.fromEntries(data.datasets.map((dataset) => [dataset.type, dataset]));
    const openBatch = async (batch) => {
        setBusyBatchId(batch.id);
        try { const { data: response } = await http.get(`/integrations/batches/${batch.id}`); setMappingBatch(response.batch); }
        catch (e) { notify(requestError(e, 'Batch belum dapat dibuka.'), 'error'); }
        finally { setBusyBatchId(null); }
    };
    const publish = async (batch) => {
        setBusyBatchId(batch.id);
        try { const { data: response } = await http.post(`/integrations/batches/${batch.id}/publish`, { allow_partial: false }); notify(`Batch ${response.batch.reference} dipublikasikan ke data operasional.`); reload(); }
        catch (e) { notify(requestError(e, 'Batch belum dapat dipublikasikan.'), 'error'); }
        finally { setBusyBatchId(null); }
    };

    return <Page>
        <GlanceHeader eyebrow="Real data onboarding" title="Integrasi Data" description="Stage, validasi, deduplikasi, dan publish data perusahaan dengan provenance per record." remote={remote} showPeriod={false} actions={<button className="primary-button compact" type="button" onClick={() => setShowStage(true)}><Upload size={17} />Stage CSV</button>} />
        <section className="master-data-intro integration-intro animate-in"><div><span className="dashboard-kicker">CONTROLLED INGESTION</span><h2>Data nyata masuk melalui staging, bukan langsung ke ledger.</h2><p>Setiap file diberi SHA-256, setiap baris memiliki external key dan row hash. Mapping dapat disesuaikan sebelum publish, sehingga impor berulang tidak membuat duplikasi.</p></div><div className="master-data-summary"><div><strong>{data.summary.staged}</strong><span>Menunggu publish</span></div><div><strong>{data.summary.published}</strong><span>Published</span></div><div><strong>{data.summary.exceptions}</strong><span>Exception</span></div><div><strong>{Number(data.summary.rows_published).toLocaleString('id-ID')}</strong><span>Baris diterbitkan</span></div></div></section>
        <section className="integration-catalog panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Dataset contract</p><h2>Template onboarding</h2></div><span>{data.datasets.length} dataset tersedia</span></div><div className="integration-dataset-grid">{data.datasets.map((dataset) => <article key={dataset.type}><div><strong>{dataset.label}</strong><small>{dataset.description}</small></div><span>{dataset.mutable ? 'Upsert terkendali' : 'Immutable ledger'}</span><a className="table-action" href={`/api/integrations/template/${dataset.type}`}><Download size={14} />Template</a></article>)}</div></section>
        <DataTable title="Batch integrasi terbaru" columns={['Batch & sumber', 'Dataset', 'Validasi', 'Publish', 'Rekonsiliasi', 'Waktu', 'Tindakan']} rows={data.batches.map((batch) => ({ key: batch.id, cells: [<div><strong>{batch.reference}</strong><small>{batch.source?.code} · {batch.file_name}</small></div>, <div><strong>{datasetByType[batch.dataset_type]?.label || batch.dataset_type}</strong><small>{batch.dataset_type}</small></div>, <div><strong>{batch.accepted_rows}/{batch.total_rows} valid</strong><small>{batch.rejected_rows} invalid · {batch.duplicate_rows} duplicate</small></div>, <div><strong>{batch.inserted_rows} insert · {batch.updated_rows} update</strong><small>{batch.skipped_rows} skip</small></div>, <StatusBadge status={batch.reconciliation_status || batch.status} />, <div>{new Date(batch.imported_at).toLocaleString('id-ID')}<small>{batch.published_at ? `Published ${new Date(batch.published_at).toLocaleString('id-ID')}` : batch.status}</small></div>, <span className="inline-actions"><button type="button" disabled={busyBatchId === batch.id} onClick={() => openBatch(batch)}>Review</button>{batch.status === 'validated' && !batch.published_at && <button type="button" disabled={busyBatchId === batch.id} onClick={() => publish(batch)}>Publish</button>}</span>] }))} />
        {showStage && <IntegrationStageForm data={data} onClose={() => setShowStage(false)} onStaged={(batch) => { setShowStage(false); setMappingBatch(batch); reload(); }} />}
        {mappingBatch && <IntegrationMappingForm batch={mappingBatch} datasets={data.datasets} onClose={() => setMappingBatch(null)} onChanged={(batch) => { setMappingBatch(batch); reload(); }} />}
    </Page>;
}

function IntegrationStageForm({ data, onClose, onStaged }) {
    const defaultDataset = data.datasets[0]?.type || '';
    const defaultSource = data.sources[0]?.id || '';
    const [form, setForm] = useState({ dataset_type: defaultDataset, data_source_id: defaultSource, profile_id: '', dataset_name: '' });
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const notify = useToast();
    const profiles = data.profiles.filter((profile) => profile.dataset_type === form.dataset_type && Number(profile.data_source_id) === Number(form.data_source_id));
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        const body = new FormData(); body.append('file', file); body.append('dataset_type', form.dataset_type); body.append('data_source_id', form.data_source_id); if (form.profile_id) body.append('profile_id', form.profile_id); if (form.dataset_name) body.append('dataset_name', form.dataset_name);
        try { const { data: response } = await http.post('/integrations/stage', body); notify(`Batch ${response.batch.reference} berhasil di-stage.`, response.batch.rejected_rows ? 'warning' : 'success'); onStaged(response.batch); }
        catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'File belum dapat di-stage.'), 'error'); }
        finally { setBusy(false); }
    };
    return <Modal title="Stage data perusahaan" onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Gunakan template untuk hasil paling cepat. File dengan nama kolom berbeda tetap dapat di-stage lalu dipetakan pada langkah review.</p><label>Dataset<select value={form.dataset_type} onChange={(e) => setForm({ ...form, dataset_type: e.target.value, profile_id: '' })}>{data.datasets.map((dataset) => <option key={dataset.type} value={dataset.type}>{dataset.label}</option>)}</select></label><label>Sumber data<select value={form.data_source_id} onChange={(e) => setForm({ ...form, data_source_id: e.target.value, profile_id: '' })}>{data.sources.map((source) => <option key={source.id} value={source.id}>{source.code} · {source.name} · rank {source.authority_rank}</option>)}</select></label><label>Profile mapping<select value={form.profile_id} onChange={(e) => setForm({ ...form, profile_id: e.target.value })}><option value="">Auto-map berdasarkan header</option>{profiles.map((profile) => <option key={profile.id} value={profile.id}>{profile.name}</option>)}</select></label><label>Nama dataset <small>(opsional)</small><input value={form.dataset_name} onChange={(e) => setForm({ ...form, dataset_name: e.target.value })} placeholder="Contoh: Export Sertifikasi September 2026" /></label><label>File CSV<input type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files?.[0] || null)} required /><FieldError errors={errors} name="file" /></label><FieldError errors={errors} name="dataset_type" /><FieldError errors={errors} name="data_source_id" /><div className="form-actions"><button className="secondary-button" type="button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy || !file}>{busy ? 'Memvalidasi…' : 'Stage & validasi'}</button></div></form></Modal>;
}

function IntegrationMappingForm({ batch, datasets, onClose, onChanged }) {
    const dataset = datasets.find((item) => item.type === batch.dataset_type);
    const [mapping, setMapping] = useState(batch.column_mapping || {});
    const [profileName, setProfileName] = useState('');
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    if (!dataset) return null;
    const saveMapping = async () => { setBusy(true); try { const { data } = await http.patch(`/integrations/batches/${batch.id}/mapping`, { column_mapping: mapping, defaults: batch.mapping_defaults || {} }); notify(data.batch.rejected_rows ? `${data.batch.rejected_rows} baris masih invalid.` : 'Mapping valid. Batch siap dipublish.', data.batch.rejected_rows ? 'warning' : 'success'); onChanged(data.batch); } catch (e) { notify(requestError(e, 'Mapping belum dapat diterapkan.'), 'error'); } finally { setBusy(false); } };
    const saveProfile = async () => { if (!profileName.trim()) return; setBusy(true); try { await http.post('/integrations/profiles', { data_source_id: batch.data_source_id, dataset_type: batch.dataset_type, name: profileName.trim(), column_mapping: mapping, defaults: batch.mapping_defaults || {} }); notify('Profile mapping disimpan untuk impor berikutnya.'); setProfileName(''); } catch (e) { notify(requestError(e, 'Profile belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Review · ${batch.reference}`} onClose={onClose}><div className="stack-form"><div className="integration-review-summary"><div><strong>{batch.accepted_rows}</strong><span>Valid</span></div><div><strong>{batch.rejected_rows}</strong><span>Invalid</span></div><div><strong>{batch.duplicate_rows}</strong><span>Duplicate</span></div></div><p className="form-helper">External key: <strong>{dataset.key_field}</strong>. Baris duplicate dengan hash identik akan di-skip; record immutable yang berubah harus dikoreksi melalui workflow domain.</p><div className="mapping-editor">{dataset.fields.map((field) => <label key={field.name}><span>{field.label}{field.required ? ' *' : ''}<small>{field.name}</small></span><select value={mapping[field.name] || ''} disabled={Boolean(batch.published_at)} onChange={(e) => setMapping({ ...mapping, [field.name]: e.target.value || null })}><option value="">Tidak dipetakan</option>{(batch.source_headers || []).map((header) => <option key={header} value={header}>{header}</option>)}</select></label>)}</div>{!batch.published_at && <><button className="primary-button" type="button" disabled={busy} onClick={saveMapping}>{busy ? 'Memvalidasi…' : 'Terapkan mapping & validasi ulang'}</button><div className="profile-save"><input placeholder="Nama profile mapping" value={profileName} onChange={(e) => setProfileName(e.target.value)} /><button className="secondary-button" type="button" disabled={busy || !profileName.trim()} onClick={saveProfile}>Simpan profile</button></div></>}<div className="integration-preview"><strong>Preview baris</strong>{(batch.preview_rows || []).slice(0, 8).map((row) => <div key={row.id} className={row.status}><span>#{row.row_number} · {row.external_key || 'tanpa key'}</span><b>{row.planned_action || row.status}</b>{row.validation_errors?.length ? <small>{row.validation_errors.join(' · ')}</small> : <small>{Object.entries(row.normalized_payload || {}).slice(0, 3).map(([key, value]) => `${key}: ${value ?? '—'}`).join(' · ')}</small>}</div>)}</div></div></Modal>;
}


function KpiCatalogPage() {
    const remote = useRemote('/kpi-catalog');
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const period = useReportingPeriod();
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const activeCount = data.definitions.filter((kpi) => Boolean(kpi.current_configuration?.is_active)).length;
    const systemCount = data.definitions.filter((kpi) => kpi.is_system_derived).length;
    const rows = data.definitions.map((kpi) => {
        const config = kpi.current_configuration;
        return { key: kpi.id, cells: [
            <div><strong>{kpi.code}</strong><small className="table-subline">{kpi.department?.name}</small></div>,
            <div><strong>{kpi.name}</strong><small className="table-subline">{kpi.is_system_derived ? 'System-derived' : 'Manual / custom'}</small></div>,
            config ? `${Number(config.target).toLocaleString('id-ID')} ${kpi.unit}` : '—',
            config?.warning_threshold == null ? '—' : `${Number(config.warning_threshold).toLocaleString('id-ID')} ${kpi.unit}`,
            config ? Number(config.weight).toLocaleString('id-ID') : '—',
            config?.owner_name || '—',
            <StatusBadge status={config?.is_active ? 'active' : 'inactive'} />,
            <div className="table-actions"><button type="button" className="table-action" onClick={() => setModal({ type: 'history', kpi })}>Riwayat</button>{data.can_manage && <><button type="button" className="table-action" onClick={() => setModal({ type: 'meta', kpi })}>Edit</button><button type="button" className="table-action strong" onClick={() => setModal({ type: 'config', kpi })}>Konfigurasi</button></>}</div>,
        ] };
    });

    return <Page>
        <GlanceHeader eyebrow="Kamus kinerja perusahaan" title="KPI Catalog" description="Target, ambang waspada, bobot, pemilik, dan periode efektif dikelola tanpa mengubah source code." period={period} remote={remote} showPeriod={false} actions={data.can_manage ? <button className="primary-button compact" type="button" onClick={() => setModal({ type: 'create' })}><Plus size={17} />Tambah KPI custom</button> : null} />
        <section className="master-data-intro animate-in"><div><span className="dashboard-kicker">VERSIONED CONFIGURATION</span><h2>Perubahan target tidak menulis ulang histori.</h2><p>Setiap konfigurasi memiliki periode efektif dan alasan perubahan. Dashboard periode lama tetap membaca target yang berlaku pada periode tersebut.</p></div><div className="master-data-summary"><div><strong>{data.definitions.length}</strong><span>Total KPI</span></div><div><strong>{activeCount}</strong><span>Aktif saat ini</span></div><div><strong>{systemCount}</strong><span>Dihitung otomatis</span></div><div><strong>{data.definitions.reduce((sum, kpi) => sum + (kpi.configurations?.length || 0), 0)}</strong><span>Versi konfigurasi</span></div></div></section>
        <DataTable title="Katalog KPI" columns={['Kode', 'Indikator', 'Target', 'Batas waspada', 'Bobot', 'Pemilik', 'Status', 'Aksi']} rows={rows} />
        {modal?.type === 'create' && <KpiCreateForm data={data} onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
        {modal?.type === 'meta' && <KpiMetadataForm kpi={modal.kpi} onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
        {modal?.type === 'config' && <KpiConfigurationForm kpi={modal.kpi} onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
        {modal?.type === 'history' && <KpiHistoryModal kpi={modal.kpi} onClose={() => setModal(null)} />}
    </Page>;
}

function KpiCreateForm({ data, onClose, onSaved }) {
    const firstDepartment = data.departments[0]?.id || '';
    const [form, setForm] = useState({ department_id: firstDepartment, code: '', name: '', description: '', unit: '%', direction: 'higher', cadence: 'monthly', data_source: 'Input operasional terverifikasi', target: '', warning_threshold: '', weight: '5', owner_name: '', effective_from: new Date().toISOString().slice(0, 10), change_reason: 'KPI baru disepakati untuk pemantauan perusahaan.' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/kpi-catalog', { ...form, warning_threshold: form.warning_threshold === '' ? null : form.warning_threshold }); notify('KPI custom dibuat dengan konfigurasi awal.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'KPI belum dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Tambah KPI custom" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">KPI bawaan sistem tetap dihitung dari ledger. KPI baru menggunakan pengukuran manual/terintegrasi sampai calculation engine khusus tersedia.</p><div className="form-grid"><label>Divisi<select value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}>{data.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label><label>Kode<input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '') })} placeholder="CERT-CUSTOM-01" required /><FieldError errors={errors} name="code" /></label></div><label>Nama KPI<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /><FieldError errors={errors} name="name" /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label><div className="form-grid"><label>Unit<input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} required /></label><label>Arah<select value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}><option value="higher">Semakin tinggi semakin baik</option><option value="lower">Semakin rendah semakin baik</option></select></label></div><div className="form-grid"><label>Target<input type="number" step="0.01" value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })} required /><FieldError errors={errors} name="target" /></label><label>Batas waspada<input type="number" step="0.01" value={form.warning_threshold} onChange={(e) => setForm({ ...form, warning_threshold: e.target.value })} /><FieldError errors={errors} name="warning_threshold" /></label></div><div className="form-grid"><label>Bobot<input type="number" min="0.01" max="100" step="0.01" value={form.weight} onChange={(e) => setForm({ ...form, weight: e.target.value })} required /></label><label>Cadence<select value={form.cadence} onChange={(e) => setForm({ ...form, cadence: e.target.value })}><option value="monthly">Bulanan</option><option value="quarterly">Triwulanan</option><option value="annual">Tahunan</option></select></label></div><label>Pemilik KPI<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /><FieldError errors={errors} name="owner_name" /></label><label>Sumber data<input value={form.data_source} onChange={(e) => setForm({ ...form, data_source: e.target.value })} required /></label><label>Berlaku mulai<input type="date" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} required /></label><label>Alasan konfigurasi<textarea value={form.change_reason} onChange={(e) => setForm({ ...form, change_reason: e.target.value })} required /><FieldError errors={errors} name="change_reason" /></label><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Buat KPI'}</button></div></form></Modal>;
}

function KpiMetadataForm({ kpi, onClose, onSaved }) {
    const [form, setForm] = useState({ name: kpi.name, description: kpi.description || '', unit: kpi.unit, cadence: kpi.cadence, data_source: kpi.data_source || '', change_reason: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.patch(`/kpi-catalog/${kpi.id}`, form); notify('Metadata KPI diperbarui.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Metadata belum dapat diperbarui.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Edit metadata · ${kpi.code}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Kode, arah perhitungan, dan calculation mode tidak diubah untuk menjaga kontrak data dan histori.</p><label>Nama<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label><label>Deskripsi<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label><div className="form-grid"><label>Unit<input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} required /></label><label>Cadence<select value={form.cadence} onChange={(e) => setForm({ ...form, cadence: e.target.value })}><option value="monthly">Bulanan</option><option value="quarterly">Triwulanan</option><option value="annual">Tahunan</option></select></label></div><label>Sumber data<input value={form.data_source} onChange={(e) => setForm({ ...form, data_source: e.target.value })} required /></label><label>Alasan perubahan<textarea value={form.change_reason} onChange={(e) => setForm({ ...form, change_reason: e.target.value })} required /><FieldError errors={errors} name="change_reason" /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan metadata'}</button></form></Modal>;
}

function KpiConfigurationForm({ kpi, onClose, onSaved }) {
    const current = kpi.current_configuration || {};
    const [form, setForm] = useState({ target: current.target ?? '', warning_threshold: current.warning_threshold ?? '', weight: current.weight ?? '', owner_name: current.owner_name || '', effective_from: new Date().toISOString().slice(0, 10), effective_until: '', is_active: current.is_active !== false, change_reason: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/kpi-catalog/${kpi.id}/configurations`, { ...form, warning_threshold: form.warning_threshold === '' ? null : form.warning_threshold, effective_until: form.effective_until || null }); notify('Versi konfigurasi KPI baru dibuat.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Konfigurasi belum dapat disimpan.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Konfigurasi · ${kpi.code}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Versi sebelumnya akan ditutup sehari sebelum periode baru. Histori tidak dapat ditimpa atau dihapus.</p><div className="form-grid"><label>Target<input type="number" step="0.01" value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })} required /><FieldError errors={errors} name="target" /></label><label>Batas waspada<input type="number" step="0.01" value={form.warning_threshold} onChange={(e) => setForm({ ...form, warning_threshold: e.target.value })} /><FieldError errors={errors} name="warning_threshold" /></label></div><div className="form-grid"><label>Bobot<input type="number" min="0.01" max="100" step="0.01" value={form.weight} onChange={(e) => setForm({ ...form, weight: e.target.value })} required /></label><label>Status<select value={String(form.is_active)} onChange={(e) => setForm({ ...form, is_active: e.target.value === 'true' })}><option value="true">Aktif</option><option value="false">Nonaktif</option></select></label></div><label>Pemilik KPI<input value={form.owner_name} onChange={(e) => setForm({ ...form, owner_name: e.target.value })} required /></label><div className="form-grid"><label>Berlaku mulai<input type="date" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} required /><FieldError errors={errors} name="effective_from" /></label><label>Berlaku sampai <small>(opsional)</small><input type="date" value={form.effective_until} onChange={(e) => setForm({ ...form, effective_until: e.target.value })} /><FieldError errors={errors} name="effective_until" /></label></div><label>Alasan perubahan<textarea value={form.change_reason} onChange={(e) => setForm({ ...form, change_reason: e.target.value })} required /><FieldError errors={errors} name="change_reason" /></label><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Buat versi konfigurasi'}</button></div></form></Modal>;
}

function KpiHistoryModal({ kpi, onClose }) {
    return <Modal title={`Riwayat konfigurasi · ${kpi.code}`} onClose={onClose}><div className="stack-form"><p className="form-helper">Urutan terbaru ke terlama. Periode yang sudah berlalu dipertahankan sebagai bukti keputusan pada saat itu.</p><div className="integration-preview">{(kpi.configurations || []).map((config) => <div key={config.id} className={config.is_active ? 'valid' : 'skipped'}><span>{new Date(config.effective_from).toLocaleDateString('id-ID')} → {config.effective_until ? new Date(config.effective_until).toLocaleDateString('id-ID') : 'seterusnya'}</span><b>{config.is_active ? 'Aktif' : 'Nonaktif'} · target {Number(config.target).toLocaleString('id-ID')} · bobot {Number(config.weight).toLocaleString('id-ID')}</b><small>{config.owner_name} · {config.change_reason} · dicatat {config.creator?.name || 'Sistem'}{config.created_at ? ` · ${new Date(config.created_at).toLocaleString('id-ID')}` : ''}</small></div>)}</div></div></Modal>;
}

const masterDataDefinitions = {
    'certification-schemes': {
        label: 'Skema sertifikasi',
        shortLabel: 'Skema',
        description: 'Daftar ruang lingkup kompetensi yang dapat dipilih pada batch asesmen.',
        columns: ['Kode', 'Nama skema', 'Kategori', 'Unit', 'Berlaku sampai', 'Status'],
        initial: { code: '', name: '', category: 'Okupasi', units_count: '', is_active: true, valid_until: '', evidence_reference: '' },
        fields: [
            { name: 'code', label: 'Kode skema', placeholder: 'Contoh: SRT-DRL-001' },
            { name: 'name', label: 'Nama skema', placeholder: 'Nama resmi skema sertifikasi' },
            { name: 'category', label: 'Kategori', placeholder: 'Contoh: Okupasi' },
            { name: 'units_count', label: 'Jumlah unit kompetensi', type: 'number', min: 1 },
            { name: 'is_active', label: 'Status', type: 'select', options: [[true, 'Aktif'], [false, 'Nonaktif']] },
            { name: 'valid_until', label: 'Berlaku sampai', type: 'date', required: false },
            { name: 'evidence_reference', label: 'Referensi bukti', placeholder: 'Nomor dokumen / lokasi bukti', required: false },
        ],
        rows: (records) => records.map((record) => ({ key: record.id, cells: [<strong>{record.code}</strong>, record.name, record.category, record.units_count, record.valid_until ? new Date(record.valid_until).toLocaleDateString('id-ID') : '—', <StatusBadge status={record.is_active ? 'active' : 'inactive'} />] })),
    },
    tuks: {
        label: 'Tempat Uji Kompetensi',
        shortLabel: 'TUK',
        description: 'Lokasi pelaksanaan asesmen beserta kapasitas operasionalnya.',
        columns: ['Kode', 'Nama TUK', 'Kota', 'Kapasitas/bulan', 'Verifikasi sampai', 'Status'],
        initial: { code: '', name: '', city: '', monthly_capacity: '', status: 'active', verification_valid_until: '', evidence_reference: '' },
        fields: [
            { name: 'code', label: 'Kode TUK', placeholder: 'Contoh: TUK-JKT-03' },
            { name: 'name', label: 'Nama TUK', placeholder: 'Nama resmi tempat uji' },
            { name: 'city', label: 'Kota', placeholder: 'Lokasi kota' },
            { name: 'monthly_capacity', label: 'Kapasitas asesi per bulan', type: 'number', min: 1 },
            { name: 'status', label: 'Status', type: 'select', options: [['active', 'Aktif'], ['maintenance', 'Pemeliharaan'], ['inactive', 'Nonaktif']] },
            { name: 'verification_valid_until', label: 'Verifikasi berlaku sampai', type: 'date', required: false },
            { name: 'evidence_reference', label: 'Referensi bukti', placeholder: 'Nomor dokumen / lokasi bukti', required: false },
        ],
        rows: (records) => records.map((record) => ({ key: record.id, cells: [<strong>{record.code}</strong>, record.name, record.city, Number(record.monthly_capacity).toLocaleString('id-ID'), record.verification_valid_until ? new Date(record.verification_valid_until).toLocaleDateString('id-ID') : '—', <StatusBadge status={record.status} />] })),
    },
    assessors: {
        label: 'Asesor kompetensi',
        shortLabel: 'Asesor',
        description: 'Tenaga asesor, spesialisasi, dan masa berlaku registrasinya.',
        columns: ['Registrasi', 'Nama asesor', 'Spesialisasi', 'Berlaku sampai', 'Status'],
        initial: { registration_no: '', name: '', specialization: '', valid_until: new Date(new Date().setFullYear(new Date().getFullYear() + 1)).toISOString().slice(0, 10), status: 'active' },
        fields: [
            { name: 'registration_no', label: 'Nomor registrasi', placeholder: 'Contoh: MET.007500' },
            { name: 'name', label: 'Nama asesor', placeholder: 'Nama lengkap asesor' },
            { name: 'specialization', label: 'Spesialisasi', placeholder: 'Bidang kompetensi utama' },
            { name: 'valid_until', label: 'Berlaku sampai', type: 'date' },
            { name: 'status', label: 'Status', type: 'select', options: [['active', 'Aktif'], ['expiring', 'Segera habis'], ['inactive', 'Nonaktif']] },
        ],
        rows: (records) => records.map((record) => ({ key: record.id, cells: [<strong>{record.registration_no}</strong>, record.name, record.specialization, new Date(record.valid_until).toLocaleDateString('id-ID'), <StatusBadge status={record.status} />] })),
    },
    'it-services': {
        label: 'Layanan teknologi',
        shortLabel: 'Layanan IT',
        description: 'Sistem digital yang dipantau beserta pemilik dan target ketersediaannya.',
        columns: ['Layanan', 'Penanggung jawab', 'Dipantau sejak', 'Target uptime', 'Status'],
        initial: { name: '', owner: '', monitoring_started_at: new Date().toISOString().slice(0, 10), target_uptime: '99.5', status: 'operational' },
        fields: [
            { name: 'name', label: 'Nama layanan', placeholder: 'Contoh: Portal Sertifikasi' },
            { name: 'owner', label: 'Penanggung jawab', placeholder: 'Contoh: Tim Aplikasi' },
            { name: 'monitoring_started_at', label: 'Mulai pemantauan SLA', type: 'date' },
            { name: 'target_uptime', label: 'Target uptime (%)', type: 'number', min: 90, max: 100, step: '0.01' },
            { name: 'status', label: 'Status', type: 'select', options: [['operational', 'Operasional'], ['degraded', 'Terdegradasi'], ['maintenance', 'Pemeliharaan']] },
        ],
        rows: (records) => records.map((record) => ({ key: record.id, cells: [<strong>{record.name}</strong>, record.owner, record.monitoring_started_at ? new Date(record.monitoring_started_at).toLocaleDateString('id-ID') : 'Legacy / sebelum NADI', `${Number(record.target_uptime).toLocaleString('id-ID')}%`, <StatusBadge status={record.status} />] })),
    },
};

function MasterDataPage() {
    const period = useReportingPeriod();
    const remote = useRemote('/master-data');
    const { data, loading, error, reload } = remote;
    const [selectedType, setSelectedType] = useState(null);
    const [formType, setFormType] = useState(null);
    const [editRecord, setEditRecord] = useState(null);
    const [lifecycleRecord, setLifecycleRecord] = useState(null);
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;

    const activeType = data.available_types.includes(selectedType) ? selectedType : data.available_types[0];
    const activeDefinition = masterDataDefinitions[activeType];
    const activeRecords = data.catalogs[activeType] || [];
    const baseRows = activeDefinition.rows(activeRecords);
    const rowsWithActions = baseRows.map((row, index) => ({ ...row, cells: [...row.cells, <div className="table-actions"><button type="button" className="table-action" disabled={Boolean(activeRecords[index].archived_at)} onClick={() => setEditRecord(activeRecords[index])}>Edit</button><button type="button" className={`table-action ${activeRecords[index].archived_at ? 'strong' : ''}`} onClick={() => setLifecycleRecord(activeRecords[index])}>{activeRecords[index].archived_at ? 'Aktifkan' : 'Arsipkan'}</button></div>] }));

    return <Page>
        <GlanceHeader eyebrow="Fondasi data operasional" title="Data at a Glance" description="Kelola data referensi langsung dari aplikasi tanpa bergantung pada berkas CSV atau Excel." period={period} remote={remote} showPeriod={false} actions={<button className="primary-button compact" type="button" onClick={() => setFormType(activeType)}><Plus size={17} aria-hidden="true" />Tambah {activeDefinition.shortLabel}</button>} />
        <section className="master-data-intro animate-in"><div><span className="dashboard-kicker">SATU SUMBER DATA</span><h2>Referensi yang dipakai seluruh modul</h2><p>Data yang ditambahkan di sini langsung tersedia pada formulir batch sertifikasi dan pencatatan insiden. Semua perubahan dicatat dalam audit trail.</p></div><div className="master-data-summary">{data.available_types.map((type) => <div key={type}><strong>{(data.catalogs[type] || []).length}</strong><span>{masterDataDefinitions[type].shortLabel}</span></div>)}</div></section>
        <section className="master-data-workspace animate-in">
            <div className="master-data-tabs" role="tablist" aria-label="Jenis data referensi">{data.available_types.map((type) => <button type="button" role="tab" aria-selected={activeType === type} className={activeType === type ? 'active' : ''} key={type} onClick={() => setSelectedType(type)}><span>{masterDataDefinitions[type].shortLabel}</span><small>{(data.catalogs[type] || []).length} data</small></button>)}</div>
            <div className="master-data-context"><div><h2>{activeDefinition.label}</h2><p>{activeDefinition.description}</p></div><button className="secondary-button compact" type="button" onClick={() => setFormType(activeType)}><Plus size={16} aria-hidden="true" />Tambah data</button></div>
            <DataTable title={activeDefinition.label} columns={[...activeDefinition.columns, 'Aksi']} rows={rowsWithActions} />
        </section>
        <section className="master-activity panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Audit trail</p><h2>Aktivitas data terbaru</h2></div><span>{data.activity.length} aktivitas</span></div><EvidenceList rows={data.activity.map((activity) => ({ key: activity.id, title: activity.label, detail: `${masterDataDefinitions[activity.type]?.shortLabel || 'Data'} · ${activity.user} · ${new Date(activity.created_at).toLocaleString('id-ID')}`, tone: 'on_track', trailing: <span className="audit-action">{activity.action === 'create' ? 'Ditambahkan' : 'Diperbarui'}</span> }))} /></section>
        {formType && <MasterDataForm type={formType} onClose={() => setFormType(null)} onSaved={() => { setFormType(null); reload(); }} />}
        {editRecord && <MasterDataEditForm type={activeType} record={editRecord} onClose={() => setEditRecord(null)} onSaved={() => { setEditRecord(null); reload(); }} />}
        {lifecycleRecord && <MasterDataLifecycleForm type={activeType} record={lifecycleRecord} onClose={() => setLifecycleRecord(null)} onSaved={() => { setLifecycleRecord(null); reload(); }} />}
    </Page>;
}

function MasterDataForm({ type, onClose, onSaved }) {
    const definition = masterDataDefinitions[type];
    const [form, setForm] = useState(definition.initial);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const notify = useToast();
    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await http.post(`/master-data/${type}`, form);
            notify(`${definition.shortLabel} berhasil ditambahkan dan langsung tersedia.`);
            onSaved();
        } catch (error) {
            setErrors(fieldErrors(error));
            notify(requestError(error, 'Data referensi belum dapat disimpan.'), 'error');
        } finally {
            setBusy(false);
        }
    };

    return <Modal title={`Tambah ${definition.label}`} onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><p className="form-helper">Data ini akan digunakan oleh pilihan pada modul terkait dan tercatat dalam audit trail.</p>{definition.fields.map((field) => <label key={field.name}>{field.label}{field.type === 'select' ? <select value={String(form[field.name])} onChange={(event) => { const option = field.options.find(([value]) => String(value) === event.target.value); setForm({ ...form, [field.name]: option?.[0] ?? event.target.value }); }}>{field.options.map(([value, label]) => <option value={String(value)} key={String(value)}>{label}</option>)}</select> : <input type={field.type || 'text'} min={field.min} max={field.max} step={field.step} placeholder={field.placeholder} value={form[field.name]} onChange={(event) => setForm({ ...form, [field.name]: event.target.value })} required={field.required !== false} />}<FieldError errors={errors} name={field.name} /></label>)}<div className="form-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Batal</button><button className="primary-button" disabled={busy} aria-busy={busy}>{busy ? 'Menyimpan…' : `Simpan ${definition.shortLabel}`}</button></div></form></Modal>;
}


function MasterDataEditForm({ type, record, onClose, onSaved }) {
    const definition = masterDataDefinitions[type];
    const immutable = { 'certification-schemes': 'code', tuks: 'code', assessors: 'registration_no', 'it-services': 'name' }[type];
    const initial = Object.fromEntries(definition.fields.map((field) => [field.name, field.type === 'date' ? String(record[field.name] || '').slice(0, 10) : (record[field.name] ?? '')]));
    const [form, setForm] = useState({ ...initial, change_reason: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setErrors({}); const payload = Object.fromEntries(Object.entries(form).filter(([key]) => key !== immutable)); try { await http.patch(`/master-data/${type}/${record.id}`, payload); notify(`${definition.shortLabel} diperbarui tanpa mengubah histori.`); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Data belum dapat diperbarui.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Edit ${definition.shortLabel}`} onClose={onClose}><form className="stack-form" onSubmit={submit}>{definition.fields.map((field) => <label key={field.name}>{field.label}{field.name === immutable ? <input value={form[field.name]} disabled /> : field.type === 'select' ? <select value={String(form[field.name])} onChange={(event) => { const option = field.options.find(([value]) => String(value) === event.target.value); setForm({ ...form, [field.name]: option?.[0] ?? event.target.value }); }}>{field.options.map(([value, label]) => <option value={String(value)} key={String(value)}>{label}</option>)}</select> : <input type={field.type || 'text'} min={field.min} max={field.max} step={field.step} value={form[field.name] ?? ''} onChange={(event) => setForm({ ...form, [field.name]: event.target.value })} required={field.required !== false} />}<FieldError errors={errors} name={field.name} /></label>)}<label>Alasan perubahan<textarea value={form.change_reason} onChange={(e) => setForm({ ...form, change_reason: e.target.value })} required /><FieldError errors={errors} name="change_reason" /></label><div className="form-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan perubahan'}</button></div></form></Modal>;
}

function MasterDataLifecycleForm({ type, record, onClose, onSaved }) {
    const definition = masterDataDefinitions[type];
    const restoring = Boolean(record.archived_at);
    const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const submit = async (event) => { event.preventDefault(); setBusy(true); setError(''); try { await http.post(`/master-data/${type}/${record.id}/${restoring ? 'restore' : 'archive'}`, { reason }); notify(restoring ? `${definition.shortLabel} diaktifkan kembali.` : `${definition.shortLabel} diarsipkan.`); onSaved(); } catch (err) { setError(requestError(err, 'Lifecycle data belum dapat diubah.')); } finally { setBusy(false); } };
    return <Modal title={`${restoring ? 'Aktifkan kembali' : 'Arsipkan'} ${definition.shortLabel}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">{restoring ? 'Record historis dipertahankan dan kembali tersedia untuk proses baru.' : 'Arsip tidak menghapus histori. Sistem akan menolak arsip jika masih ada proses aktif yang bergantung pada record ini.'}</p><label>Alasan<textarea value={reason} onChange={(e) => setReason(e.target.value)} required /></label>{error && <div className="form-error">{error}</div>}<button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : restoring ? 'Aktifkan kembali' : 'Arsipkan data'}</button></form></Modal>;
}

function DataTable({ title, columns, rows }) {
    return <section className="table-panel panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Data operasional</p><h2>{title}</h2></div><span>{rows.length} rekaman</span></div><div className="table-scroll"><table><caption className="sr-only">{title}, {rows.length} rekaman</caption><thead><tr>{columns.map((column) => <th scope="col" key={column}>{column}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, index) => { const normalized = Array.isArray(row) ? { key: index, cells: row } : row; return <tr key={normalized.key}>{normalized.cells.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}</tr>; }) : <tr><td colSpan={columns.length}><EmptyState compact title="Belum ada rekaman" description="Pilih periode lain atau masukkan data baru." /></td></tr>}</tbody></table></div></section>;
}

function Modal({ title, onClose, children }) {
    const dialog = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const previouslyFocused = document.activeElement;
        const focusable = () => [...dialog.current.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]')];
        focusable()[0]?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
            if (event.key !== 'Tab') return;
            const elements = focusable();
            if (! elements.length) return;
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (! event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => { document.removeEventListener('keydown', onKeyDown); previouslyFocused?.focus(); };
    }, [onClose]);

    return <div className="modal-backdrop"><section className="modal" ref={dialog} role="dialog" aria-modal="true" aria-labelledby={titleId}><header><h2 id={titleId}>{title}</h2><button className="icon-button" type="button" aria-label={`Tutup ${title}`} onClick={onClose}><X size={20} aria-hidden="true" /></button></header>{children}</section></div>;
}


function PasswordChangeForm({ forced = false }) {
    const { changePassword } = useAuth();
    const notify = useToast();
    const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const submit = async (event) => {
        event.preventDefault(); setBusy(true); setErrors({});
        try {
            await changePassword(form);
            setForm({ current_password: '', password: '', password_confirmation: '' });
            notify('Kata sandi berhasil diperbarui.');
        } catch (error) {
            setErrors(fieldErrors(error));
            notify(requestError(error, 'Kata sandi belum dapat diperbarui.'), 'error');
        } finally { setBusy(false); }
    };
    return <form className="stack-form security-password-form" onSubmit={submit} noValidate>
        {forced && <div className="security-callout"><ShieldCheck size={19} /><div><strong>Penggantian kata sandi wajib</strong><p>Akun memakai kata sandi sementara. Modul operasional akan terbuka setelah kata sandi pribadi berhasil dibuat.</p></div></div>}
        <label>Kata sandi saat ini<input type="password" autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} required /><FieldError errors={errors} name="current_password" /></label>
        <label>Kata sandi baru<input type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required /><small>Minimal 12 karakter, huruf besar/kecil, angka, dan simbol.</small><FieldError errors={errors} name="password" /></label>
        <label>Konfirmasi kata sandi<input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} required /></label>
        <button className="primary-button" disabled={busy}>{busy ? 'Memperbarui…' : 'Perbarui kata sandi'}</button>
    </form>;
}

function ForcedPasswordPage() {
    const { user, logout } = useAuth();
    return <main className="forced-password-page"><section className="forced-password-card"><div className="brand"><BrandMark /><span>NADI</span></div><p className="eyebrow">Keamanan akun</p><h1>Buat kata sandi pribadi</h1><p>{user.name}, administrator memberikan kata sandi sementara untuk akses pertama atau pemulihan akun.</p><PasswordChangeForm forced /><button type="button" className="text-button" onClick={logout}>Keluar dari akun</button></section></main>;
}

function AccountPage() {
    const { user } = useAuth();
    const allowed = Object.entries(user.permissions || {}).filter(([, enabled]) => enabled).map(([permission]) => permission);
    return <Page>
        <PageHeader eyebrow="Identity & security" title="Akun Saya" description="Identitas, akses efektif, dan keamanan kata sandi untuk sesi NADI Anda." />
        <section className="security-grid animate-in">
            <article className="panel security-profile"><div className="security-profile-head"><span className="avatar">{user.name.split(' ').map((x) => x[0]).slice(0, 2).join('')}</span><div><h2>{user.name}</h2><p>{user.position}</p></div></div><dl><div><dt>Email</dt><dd>{user.email}</dd></div><div><dt>Divisi</dt><dd>{user.department?.name || '—'}</dd></div><div><dt>Role</dt><dd>{user.role.replaceAll('_', ' ')}</dd></div><div><dt>Login terakhir</dt><dd>{user.last_login_at ? new Date(user.last_login_at).toLocaleString('id-ID') : 'Belum tercatat'}</dd></div></dl></article>
            <article className="panel"><div className="panel-heading"><div><p className="eyebrow">Credential</p><h2>Ganti kata sandi</h2></div><KeyRound size={20} /></div><PasswordChangeForm /></article>
        </section>
        <section className="panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Effective access</p><h2>Permission aktif</h2></div><span>{allowed.length} izin</span></div><div className="permission-chip-list">{allowed.map((permission) => <span key={permission}>{permission}</span>)}</div></section>
    </Page>;
}

function UserAdminPage() {
    const remote = useRemote('/admin/users', 30000);
    const { data, loading, error, reload } = remote;
    const [modal, setModal] = useState(null);
    const [selected, setSelected] = useState(null);
    if (error) return <Page><ErrorState message={error} retry={reload} /></Page>;
    if (loading) return <Page loading />;
    const open = (name, user = null) => { setSelected(user); setModal(name); };
    const close = () => { setSelected(null); setModal(null); };
    const saved = () => { close(); reload(); };
    const activeUsers = data.users.filter((user) => user.is_active).length;
    const forcedChanges = data.users.filter((user) => user.must_change_password).length;
    const failedLogins = data.authentication_events.filter((event) => !event.successful).length;
    return <Page>
        <PageHeader eyebrow="Identity & access management" title="Pengguna & Akses" description="Kelola akun, role, permission override, keamanan credential, dan jejak autentikasi." actions={<button className="primary-button compact" type="button" onClick={() => open('create')}><Plus size={17} />Tambah pengguna</button>} />
        <section className="metric-grid animate-in"><MetricCard icon={Users} label="Pengguna aktif" value={activeUsers} /><MetricCard icon={KeyRound} label="Wajib ganti password" value={forcedChanges} tone={forcedChanges ? 'gold' : 'teal'} /><MetricCard icon={ShieldCheck} label="Role Pimpinan" value={data.users.filter((user) => user.role === 'director' && user.is_active).length} tone="violet" /><MetricCard icon={AlertTriangle} label="Event login gagal terbaru" value={failedLogins} tone={failedLogins ? 'gold' : 'teal'} /></section>
        <DataTable title="Direktori pengguna" columns={['Pengguna', 'Role & divisi', 'Status', 'Login terakhir', 'Kontrol akses', 'Tindakan']} rows={data.users.map((account) => ({ key: account.id, cells: [<div><strong>{account.name}</strong><small>{account.email}</small></div>, <div><strong>{account.role.replaceAll('_', ' ')}</strong><small>{account.department?.name || '—'} · {account.position}</small></div>, <div><StatusBadge status={account.is_active ? 'active' : 'inactive'} />{account.must_change_password && <small>Wajib ganti password</small>}</div>, account.last_login_at ? <div>{new Date(account.last_login_at).toLocaleString('id-ID')}<small>{account.last_login_ip || 'IP —'}</small></div> : 'Belum login', <div><strong>{Object.values(account.effective_permissions || {}).filter(Boolean).length} izin aktif</strong><small>{account.permission_overrides.length} override</small></div>, <span className="inline-actions"><button type="button" onClick={() => open('edit', account)}>Edit</button><button type="button" onClick={() => open('permissions', account)}>Permission</button><button type="button" onClick={() => open('reset', account)}>Reset password</button><button type="button" onClick={() => open('status', account)}>{account.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button></span>] }))} />
        <section className="panel animate-in"><div className="panel-heading"><div><p className="eyebrow">Authentication audit</p><h2>Aktivitas autentikasi terbaru</h2></div><span>{data.authentication_events.length} event</span></div><EvidenceList rows={data.authentication_events.map((event) => ({ key: event.id, title: `${event.event.replaceAll('_', ' ')} · ${event.user || event.email || 'unknown'}`, detail: `${new Date(event.occurred_at).toLocaleString('id-ID')} · IP ${event.ip_address || '—'}`, tone: event.successful ? 'on_track' : 'critical', trailing: <StatusBadge status={event.successful ? 'active' : 'critical'} /> }))} /></section>
        {modal === 'create' && <UserCreateForm data={data} onClose={close} onSaved={saved} />}
        {modal === 'edit' && selected && <UserEditForm account={selected} data={data} onClose={close} onSaved={saved} />}
        {modal === 'permissions' && selected && <UserPermissionForm account={selected} catalog={data.permission_catalog} onClose={close} onSaved={saved} />}
        {modal === 'reset' && selected && <UserResetPasswordForm account={selected} onClose={close} onSaved={saved} />}
        {modal === 'status' && selected && <UserStatusForm account={selected} onClose={close} onSaved={saved} />}
    </Page>;
}

function UserCreateForm({ data, onClose, onSaved }) {
    const [form, setForm] = useState({ name: '', email: '', department_id: data.departments[0]?.id || '', role: 'viewer', position: '', temporary_password: '', temporary_password_confirmation: '' });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post('/admin/users', form); notify('Pengguna dibuat dengan kewajiban mengganti kata sandi sementara.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Pengguna belum dapat dibuat.'), 'error'); } finally { setBusy(false); } };
    return <Modal title="Tambah pengguna" onClose={onClose}><form className="stack-form" onSubmit={submit} noValidate><div className="form-grid"><label>Nama<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /><FieldError errors={errors} name="name" /></label><label>Email<input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /><FieldError errors={errors} name="email" /></label></div><div className="form-grid"><label>Divisi<select value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}>{data.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label><label>Role<select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>{data.roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}</select><FieldError errors={errors} name="role" /></label></div><label>Jabatan<input value={form.position} onChange={(e) => setForm({ ...form, position: e.target.value })} required /></label><div className="form-grid"><label>Password sementara<input type="password" value={form.temporary_password} onChange={(e) => setForm({ ...form, temporary_password: e.target.value })} required /><FieldError errors={errors} name="temporary_password" /></label><label>Konfirmasi<input type="password" value={form.temporary_password_confirmation} onChange={(e) => setForm({ ...form, temporary_password_confirmation: e.target.value })} required /></label></div><p className="form-helper">Pengguna wajib mengganti password ini setelah login pertama.</p><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Buat pengguna'}</button></form></Modal>;
}

function UserEditForm({ account, data, onClose, onSaved }) {
    const [form, setForm] = useState({ name: account.name, email: account.email, department_id: account.department_id, role: account.role, position: account.position });
    const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.patch(`/admin/users/${account.id}`, form); notify('Profil dan role pengguna diperbarui.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Pengguna belum dapat diperbarui.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Edit · ${account.name}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><div className="form-grid"><label>Nama<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label><label>Email<input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label></div><div className="form-grid"><label>Divisi<select value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}>{data.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select></label><label>Role<select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>{data.roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}</select><FieldError errors={errors} name="role" /></label></div><label>Jabatan<input value={form.position} onChange={(e) => setForm({ ...form, position: e.target.value })} /></label><button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan perubahan'}</button></form></Modal>;
}

function UserPermissionForm({ account, catalog, onClose, onSaved }) {
    const initial = Object.fromEntries((account.permission_overrides || []).map((row) => [row.permission, { mode: row.allowed ? 'allow' : 'deny', reason: row.reason || '' }]));
    const [overrides, setOverrides] = useState(initial); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const setMode = (permission, mode) => setOverrides((current) => mode === 'default' ? Object.fromEntries(Object.entries(current).filter(([key]) => key !== permission)) : { ...current, [permission]: { mode, reason: current[permission]?.reason || '' } });
    const setReason = (permission, reason) => setOverrides((current) => ({ ...current, [permission]: { ...current[permission], reason } }));
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); const payload = Object.entries(overrides).map(([permission, value]) => ({ permission, allowed: value.mode === 'allow', reason: value.reason || 'Override administratif' })); try { await http.put(`/admin/users/${account.id}/permissions`, { overrides: payload }); notify('Permission override diperbarui.'); onSaved(); } catch (err) { setError(requestError(err, 'Permission belum dapat diperbarui.')); } finally { setBusy(false); } };
    return <Modal title={`Permission · ${account.name}`} onClose={onClose}><form className="stack-form permission-form" onSubmit={submit}><p className="form-helper">Default mengikuti role dan divisi. Gunakan override hanya untuk kebutuhan khusus yang memiliki alasan bisnis.</p><div className="permission-editor">{catalog.map((item) => { const current = overrides[item.permission]; return <div className="permission-row" key={item.permission}><div><strong>{item.label}</strong><small>{item.permission}{item.sensitive ? ' · sensitif' : ''}</small></div><select value={current?.mode || 'default'} onChange={(e) => setMode(item.permission, e.target.value)}><option value="default">Default role</option><option value="allow">Izinkan</option><option value="deny">Tolak</option></select>{current && <input placeholder="Alasan override" value={current.reason} onChange={(e) => setReason(item.permission, e.target.value)} required />}</div>; })}</div>{error && <div className="form-error">{error}</div>}<button className="primary-button" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan permission'}</button></form></Modal>;
}

function UserResetPasswordForm({ account, onClose, onSaved }) {
    const [form, setForm] = useState({ temporary_password: '', temporary_password_confirmation: '', reason: '' }); const [errors, setErrors] = useState({}); const [busy, setBusy] = useState(false); const notify = useToast();
    const submit = async (e) => { e.preventDefault(); setBusy(true); setErrors({}); try { await http.post(`/admin/users/${account.id}/reset-password`, form); notify('Password sementara diterapkan dan seluruh sesi pengguna diakhiri.'); onSaved(); } catch (error) { setErrors(fieldErrors(error)); notify(requestError(error, 'Reset password gagal.'), 'error'); } finally { setBusy(false); } };
    return <Modal title={`Reset password · ${account.name}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">Semua sesi aktif akan dihentikan. Pengguna wajib membuat password pribadi pada login berikutnya.</p><label>Password sementara<input type="password" value={form.temporary_password} onChange={(e) => setForm({ ...form, temporary_password: e.target.value })} required /><FieldError errors={errors} name="temporary_password" /></label><label>Konfirmasi<input type="password" value={form.temporary_password_confirmation} onChange={(e) => setForm({ ...form, temporary_password_confirmation: e.target.value })} required /></label><label>Alasan<textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required /><FieldError errors={errors} name="reason" /></label><button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : 'Reset password'}</button></form></Modal>;
}

function UserStatusForm({ account, onClose, onSaved }) {
    const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const notify = useToast();
    const targetState = !account.is_active;
    const submit = async (e) => { e.preventDefault(); setBusy(true); setError(''); try { await http.patch(`/admin/users/${account.id}/status`, { is_active: targetState, reason }); notify(targetState ? 'Akun diaktifkan.' : 'Akun dinonaktifkan dan sesi aktif dihentikan.'); onSaved(); } catch (err) { setError(requestError(err, 'Status akun belum dapat diubah.')); } finally { setBusy(false); } };
    return <Modal title={`${targetState ? 'Aktifkan' : 'Nonaktifkan'} · ${account.name}`} onClose={onClose}><form className="stack-form" onSubmit={submit}><p className="form-helper">{targetState ? 'Akun akan kembali dapat login.' : 'Nonaktifkan akses tanpa menghapus histori pengguna atau audit trail.'}</p><label>Alasan<textarea value={reason} onChange={(e) => setReason(e.target.value)} required /></label>{error && <div className="form-error">{error}</div>}<button className="primary-button" disabled={busy}>{busy ? 'Memproses…' : targetState ? 'Aktifkan akun' : 'Nonaktifkan akun'}</button></form></Modal>;
}

function ProtectedApp() {
    const { user, loading } = useAuth();
    if (loading) return <div className="full-loading"><BrandMark /><span>NADI</span></div>;
    if (!user) return <Navigate to="/login" replace />;
    if (user.must_change_password) return <ForcedPasswordPage />;
    return <AppShell />;
}

function Root() {
    return <BrowserRouter><ToastProvider><AuthProvider><Routes><Route path="/login" element={<LoginPage />} /><Route path="/*" element={<ProtectedApp />} /></Routes></AuthProvider></ToastProvider></BrowserRouter>;
}

createRoot(document.getElementById('app')).render(<Root />);
