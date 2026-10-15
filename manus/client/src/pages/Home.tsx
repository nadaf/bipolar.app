import { useEffect, useMemo, useState } from "react";
import { Link, useLocation } from "wouter";
import { toast } from "sonner";
import {
  BookOpen,
  CalendarDays,
  ChartNoAxesCombined,
  HeartHandshake,
  Settings2,
  ShieldCheck,
  Download,
  ChevronLeft,
  ChevronRight,
  ArrowUpRight,
  Leaf,
  Sun,
  Sunset,
  Moon,
  Check,
  LockKeyhole,
  X,
  LogOut,
  FileDown,
  Info,
  Laptop,
  Smartphone,
  Heart,
  Phone,
  Plus,
  Loader2,
  CircleHelp,
  Send,
} from "lucide-react";
import {
  apiUrl,
  getEntries,
  getSession,
  request,
  saveEntry,
  serverEnabled,
  uploadMedia,
  type Session,
} from "@/lib/api";
import {
  clearLocalEntries,
  dateLabel,
  deleteLocalEntry,
  exportEntries,
  localDate,
  moods,
  offsetDate,
  periods,
  readLocalEntries,
  sampleEntries,
  saveLocalEntry,
  type Entry,
  type Period,
} from "@/lib/diary";
import EntryForm from "@/components/diary/EntryForm";
import MoodFace from "@/components/diary/MoodFace";
import MoodChart from "@/components/diary/MoodChart";
import History, { EntryList } from "@/components/diary/History";
import Modal from "@/components/diary/Modal";

import ReportsPage from "@/components/automation/ReportsPage";
import NotificationsPanel from "@/components/automation/NotificationsPanel";
import { useAutomation, useReminderEngine } from "@/hooks/useAutomation";

const navigation = [
  { path: "/", label: "Meu diário", short: "Diário", icon: BookOpen },
  {
    path: "/historico",
    label: "Histórico",
    short: "Histórico",
    icon: CalendarDays,
  },
  {
    path: "/panorama",
    label: "Meu panorama",
    short: "Panorama",
    icon: ChartNoAxesCombined,
  },
  { path: "/relatorios", label: "Relatórios", short: "Relatórios", icon: Send },
  {
    path: "/cuidado",
    label: "Meu cuidado",
    short: "Cuidado",
    icon: HeartHandshake,
  },
  {
    path: "/configuracoes",
    label: "Configurações",
    short: "Ajustes",
    icon: Settings2,
  },
];
function readPreference(key: string) {
  try {
    return localStorage.getItem(key) === "true";
  } catch {
    return false;
  }
}
const defaultSession: Session = {
  user: null,
  csrf: "",
  providers: { google: false, x: false, microsoft: false },
};
type InstallPrompt = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: string }>;
};
function Mark({ small = false }: { small?: boolean }) {
  return (
    <span className={`brand-mark ${small ? "small" : ""}`} aria-hidden="true">
      <svg viewBox="0 0 32 32" fill="none">
        <path
          d="M8 22V14a8 8 0 0116 0v8M8 17a8 8 0 0016 0"
          stroke="currentColor"
          strokeWidth="2.7"
          strokeLinecap="round"
        />
        <path
          d="M12 12v8m8-8v8"
          stroke="currentColor"
          strokeWidth="2.2"
          strokeLinecap="round"
        />
      </svg>
    </span>
  );
}
export default function Home() {
  const [location, navigate] = useLocation();
  const [entries, setEntries] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [session, setSession] = useState<Session>(defaultSession);
  const [localMode, setLocalMode] = useState(!serverEnabled);
  const [error, setError] = useState("");
  const [mediaBusy, setMediaBusy] = useState(false);
  const [draftDirty, setDraftDirty] = useState(false);
  const [automationDirty, setAutomationDirty] = useState(false);
  const [date, setDate] = useState(localDate());
  const [editingPeriod, setEditingPeriod] = useState<Period | undefined>();
  const [modal, setModal] = useState<
    "login" | "install" | "privacy" | "scale" | "export" | "clear" | null
  >(null);
  const [deleting, setDeleting] = useState<Entry | null>(null);
  const [showExamples, setShowExamples] = useState(true);
  const [chartDays, setChartDays] = useState(7);
  const [installPrompt, setInstallPrompt] = useState<InstallPrompt | null>(
    null
  );
  const [installed, setInstalled] = useState(
    () => window.matchMedia("(display-mode: standalone)").matches
  );
  const [largeText, setLargeText] = useState(() =>
    readPreference("bipolar-large-text")
  );
  const [reducedMotion, setReducedMotion] = useState(() =>
    readPreference("bipolar-reduce-motion")
  );
  const current = navigation.find(n => n.path === location);
  const examples = useMemo(() => sampleEntries(), []);
  const illustrative = entries.length === 0 && showExamples;
  const chartEntries = illustrative ? examples : entries;
  const todayEntries = entries.filter(e => e.date === date);
  const latest = [...entries].sort((a, b) =>
    `${b.date}-${periods.findIndex(p => p.id === b.period)}`.localeCompare(
      `${a.date}-${periods.findIndex(p => p.id === a.period)}`
    )
  );
  const recordedDays = new Set(entries.map(e => e.date)).size;
  const sleepByDay = [...new Set(entries.map(e => e.date))]
    .map(
      day =>
        entries
          .filter(e => e.date === day && e.sleep !== null)
          .sort((a, b) => b.createdAt.localeCompare(a.createdAt))[0]?.sleep
    )
    .filter((v): v is number => v != null);
  const averageSleep = sleepByDay.length
    ? (
        sleepByDay.reduce((sum, n) => sum + n, 0) / sleepByDay.length
      ).toLocaleString("pt-BR", { maximumFractionDigits: 1 })
    : "—";
  useEffect(() => {
    document.documentElement.dataset.largeText = String(largeText);
    try {
      localStorage.setItem("bipolar-large-text", String(largeText));
    } catch {
      /* A preferência funciona nesta sessão. */
    }
  }, [largeText]);
  useEffect(() => {
    document.documentElement.dataset.reduceMotion = String(reducedMotion);
    try {
      localStorage.setItem("bipolar-reduce-motion", String(reducedMotion));
    } catch {
      /* A preferência funciona nesta sessão. */
    }
  }, [reducedMotion]);
  useEffect(() => {
    document.title = `${current?.label ?? "Página não encontrada"} — Bipolar`;
  }, [current]);
  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault();
      setInstallPrompt(e as InstallPrompt);
    };
    const installedHandler = () => {
      setInstalled(true);
      setInstallPrompt(null);
    };
    window.addEventListener("beforeinstallprompt", handler);
    window.addEventListener("appinstalled", installedHandler);
    return () => {
      window.removeEventListener("beforeinstallprompt", handler);
      window.removeEventListener("appinstalled", installedHandler);
    };
  }, []);
  useEffect(() => {
    let active = true;
    async function load() {
      setLoading(true);
      setError("");
      try {
        if (localMode) {
          const records = await readLocalEntries();
          if (active) setEntries(records);
          if (serverEnabled) {
            const s = await getSession().catch(() => defaultSession);
            if (active) setSession(s);
          }
        } else {
          const s = await getSession();
          if (active) setSession(s);
          const records = s.user ? await getEntries() : [];
          if (active) setEntries(records);
        }
      } catch (err) {
        if (active) {
          setError(
            err instanceof Error
              ? err.message
              : "Não foi possível carregar seus dados."
          );
          setEntries([]);
        }
      } finally {
        if (active) setLoading(false);
      }
    }
    load();
    return () => {
      active = false;
    };
  }, [localMode]);
  useEffect(() => {
    const pendingMedia =
      !localMode && entries.some(e => e.attachments.some(a => a.blob));
    const handler = (event: BeforeUnloadEvent) => {
      if (mediaBusy || busy || pendingMedia || draftDirty || automationDirty) {
        event.preventDefault();
        event.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [entries, localMode, mediaBusy, busy, draftDirty, automationDirty]);
  async function save(entry: Entry): Promise<Entry | undefined> {
    if (error || mediaBusy) {
      toast.error(
        "Seus dados precisam carregar corretamente antes de salvar. Finalize também qualquer gravação."
      );
      return undefined;
    }
    setBusy(true);
    try {
      let saved: Entry;
      if (localMode) saved = await saveLocalEntry(entry);
      else {
        saved = await saveEntry(
          entry,
          entries.some(e => e.id === entry.id)
        );
        const prior = entries.find(e => e.id === saved.id);
        for (const removed of prior?.attachments.filter(
          a =>
            !!a.url &&
            !a.blob &&
            !entry.attachments.some(item => item.id === a.id)
        ) ?? [])
          await request("delete_media", { id: removed.id });
        const pending = entry.attachments.filter(a => a.blob);
        const successful = [];
        const failed = [];
        for (const attachment of pending) {
          try {
            successful.push(await uploadMedia(saved.id, attachment));
          } catch {
            failed.push(attachment);
          }
        }
        saved.attachments = [
          ...saved.attachments.filter(a =>
            entry.attachments.some(item => item.id === a.id)
          ),
          ...successful,
          ...failed,
        ];
        if (failed.length)
          toast.warning(
            "Humor e texto salvos. Alguns anexos não foram enviados; mantenha esta página aberta e tente salvar novamente."
          );
      }
      setEntries(prev => [
        ...prev.filter(
          e =>
            e.id !== saved.id &&
            !(e.date === saved.date && e.period === saved.period)
        ),
        saved,
      ]);
      if (!saved.attachments.some(a => a.blob) || localMode)
        toast.success("Registro salvo. Um momento de cuidado com você.");
      return saved;
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "O registro não foi salvo. Tente novamente."
      );
      return undefined;
    } finally {
      setBusy(false);
    }
  }
  function canLeaveDraft() {
    return (
      !(draftDirty || automationDirty) ||
      window.confirm("Há alterações não salvas. Deseja sair e descartá-las?")
    );
  }
  function edit(entry: Entry) {
    if (mediaBusy || busy || error) {
      toast.info(
        "Finalize a gravação ou aguarde o carregamento antes de editar."
      );
      return;
    }
    if (!canLeaveDraft()) return;
    setDate(entry.date);
    setEditingPeriod(entry.period);
    navigate("/");
    window.scrollTo({ top: 0, behavior: "instant" });
  }
  async function removeEntry() {
    if (!deleting || error) return;
    setBusy(true);
    try {
      if (localMode) await deleteLocalEntry(deleting.id);
      else await request("delete_entry", { id: deleting.id });
      setEntries(prev => prev.filter(e => e.id !== deleting.id));
      setDeleting(null);
      toast.success("Registro excluído.");
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : "Não foi possível excluir."
      );
    } finally {
      setBusy(false);
    }
  }
  async function install() {
    if (installed) {
      toast.info("O aplicativo já está aberto em modo instalado.");
      return;
    }
    if (!installPrompt) {
      setModal("install");
      return;
    }
    await installPrompt.prompt();
    const result = await installPrompt.userChoice;
    setInstallPrompt(null);
    if (result.outcome === "accepted")
      toast.success("Instalação solicitada ao navegador.");
  }
  async function logout() {
    try {
      if ("serviceWorker" in navigator) {
        try {
          const registration =
            await navigator.serviceWorker.getRegistration("/");
          const subscription =
            await registration?.pushManager?.getSubscription();
          if (subscription) {
            try {
              await request("push_unsubscribe", {
                endpoint: subscription.endpoint,
              });
            } finally {
              await subscription.unsubscribe();
            }
          }
        } catch {
          toast.warning(
            "Confira as permissões de notificação do navegador se os lembretes continuarem neste dispositivo."
          );
        }
      }
      await request("logout", {});
      setSession(defaultSession);
      setEntries([]);
      toast.success("Você saiu da sua conta.");
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : "Não foi possível sair."
      );
    }
  }
  async function clear() {
    setBusy(true);
    try {
      await clearLocalEntries();
      setEntries([]);
      setModal(null);
      toast.success("Registros deste navegador apagados.");
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : "Não foi possível apagar."
      );
    } finally {
      setBusy(false);
    }
  }
  const automation = useAutomation(localMode, session.user?.id ?? null);
  useReminderEngine(automation, entries, !loading && !error);
  const PersonName = session.user?.name?.split(" ")[0];
  const description =
    current?.path === "/"
      ? "Um momento para olhar para você. No seu ritmo."
      : current?.path === "/historico"
        ? "Sua história não precisa caber na memória. Ela está aqui."
        : current?.path === "/panorama"
          ? "Observe seus registros. Leve suas percepções para a consulta."
          : current?.path === "/relatorios"
            ? "Seu diário pode aproximar sua rede de cuidado. Você escolhe o que compartilhar."
            : current?.path === "/cuidado"
              ? "Você não precisa atravessar tudo sozinho."
              : "Um espaço pessoal, do seu jeito.";
  const heading =
    current?.path === "/"
      ? `Bom te ver por aqui${PersonName ? `, ${PersonName}` : ""}.`
      : (current?.label ?? "Esta página não foi encontrada.");
  const Page = current?.path;
  return (
    <div
      className="app-shell"
      onClickCapture={e => {
        const anchor = (e.target as HTMLElement).closest("a[href]");
        if (
          anchor &&
          !anchor.getAttribute("target") &&
          !mediaBusy &&
          !busy &&
          !canLeaveDraft()
        ) {
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        if (mediaBusy || busy) {
          const target = e.target as HTMLElement;
          const leavesPage = target.closest("a[href]");
          if (leavesPage && !leavesPage.getAttribute("target")) {
            e.preventDefault();
            e.stopPropagation();
            toast.info(
              "Finalize a gravação ou aguarde o salvamento antes de sair."
            );
          }
        }
      }}
    >
      <a className="skip-link" href="#main">
        Pular para o conteúdo
      </a>
      <aside className="sidebar">
        <Link href="/" className="brand">
          <Mark />
          <div>
            <span className="brand-name">
              bipolar<span className="brand-dot">.</span>
            </span>
            <span className="brand-tagline">seu diário de humor</span>
          </div>
        </Link>
        <span className="nav-eyebrow">SEU ESPAÇO</span>
        <nav aria-label="Navegação principal">
          {navigation.map(n => (
            <Link
              key={n.path}
              href={n.path}
              className={`nav-item ${location === n.path ? "active" : ""}`}
              aria-current={location === n.path ? "page" : undefined}
            >
              <n.icon size={20} />
              <span>{n.label}</span>
              {location === n.path && <i />}
            </Link>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <div className="sidebar-note">
            <Leaf size={23} />
            <p>
              Não é sobre estar bem
              <br />
              todos os dias.
              <br />
              <strong>É sobre se perceber.</strong>
            </p>
          </div>
          <button className="install-button" onClick={install}>
            <Download size={17} />
            <span>{installed ? "App instalado" : "Instalar aplicativo"}</span>
            <ArrowUpRight size={15} />
          </button>
          <button className="profile-button" onClick={() => setModal("login")}>
            <span className="profile-avatar">
              {session.user && !localMode ? (
                session.user.name?.charAt(0) || "U"
              ) : (
                <BookOpen size={17} />
              )}
            </span>
            <span>
              <strong>
                {session.user && !localMode
                  ? session.user.name
                  : "Seu diário pessoal"}
              </strong>
              <small>
                {localMode
                  ? "Neste dispositivo"
                  : session.user
                    ? "Conta conectada"
                    : "Entrar na sua conta"}
              </small>
            </span>
            <ChevronRight size={16} />
          </button>
        </div>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div className="mobile-brand">
            <Mark small />
            <span>bipolar.</span>
          </div>
          <span className="topbar-caption">Um espaço de autocuidado.</span>
          <div className="topbar-actions">
            <button
              className="privacy-pill"
              onClick={() => setModal("privacy")}
            >
              <ShieldCheck size={15} />
              <span>Privacidade em primeiro lugar</span>
            </button>
            <span className="topbar-divider" />
            <button
              className="account-top-button"
              onClick={() => setModal("login")}
            >
              {session.user && !localMode
                ? session.user.name?.split(" ")[0]
                : "Entrar"}
              <ChevronRight size={15} />
            </button>
          </div>
        </header>
        <main id="main" className="main-content">
          <div className="page-heading">
            <div>
              <div className="page-eyebrow">
                <span className="tiny-leaf">
                  <Leaf size={14} />
                </span>
                {Page === "/"
                  ? "CADA REGISTRO, UM PEQUENO CUIDADO"
                  : "SEU DIÁRIO DE HUMOR"}
              </div>
              <h1>{heading}</h1>
              <p>{description}</p>
            </div>
            {Page === "/" ? (
              <div className="date-navigation">
                <button
                  className="icon-button"
                  onClick={() => {
                    if (!canLeaveDraft()) return;
                    setDate(offsetDate(date, -1));
                    setEditingPeriod(undefined);
                  }}
                  disabled={mediaBusy || busy}
                  aria-label="Dia anterior"
                >
                  <ChevronLeft size={17} />
                </button>
                <label>
                  <CalendarDays size={16} />
                  <span>
                    {date === localDate() ? "Hoje, " : ""}
                    {dateLabel(date, { day: "numeric", month: "short" })}
                  </span>
                  <input
                    type="date"
                    disabled={mediaBusy || busy}
                    value={date}
                    max={localDate()}
                    onChange={e => {
                      if (e.target.value && e.target.value <= localDate()) {
                        if (!canLeaveDraft()) return;
                        setDate(e.target.value);
                        setEditingPeriod(undefined);
                      }
                    }}
                    aria-label="Selecionar data do registro"
                  />
                </label>
                <button
                  className="icon-button"
                  disabled={date >= localDate() || mediaBusy || busy}
                  onClick={() => {
                    if (!canLeaveDraft()) return;
                    setDate(offsetDate(date, 1));
                    setEditingPeriod(undefined);
                  }}
                  aria-label="Próximo dia"
                >
                  <ChevronRight size={17} />
                </button>
              </div>
            ) : (
              <button
                className="outline-button"
                onClick={() => setModal("export")}
              >
                <FileDown size={17} />
                Exportar registros
              </button>
            )}
          </div>
          <div className="mode-banner">
            <span>
              <span className="status-dot" />
              {localMode
                ? "Diário neste dispositivo"
                : session.user
                  ? "Diário sincronizado com sua conta"
                  : "Você ainda não entrou"}
            </span>
            <span>
              {localMode
                ? "Sem envio à nuvem. Não é protegido por senha."
                : "Seus registros pessoais só aparecem após entrar."}
            </span>
            <button
              className="text-button"
              onClick={() => setModal(localMode ? "privacy" : "login")}
            >
              {localMode ? "Entenda a privacidade" : "Entrar na conta"}
              <ArrowUpRight size={13} />
            </button>
          </div>
          {error && (
            <div className="error-banner" role="alert">
              {error}
              <button
                className="text-button"
                onClick={() => window.location.reload()}
              >
                Tentar novamente
              </button>
            </div>
          )}
          {loading ? (
            <div className="loading-state" role="status">
              <Loader2 size={24} className="spin" />
              Carregando seu diário…
            </div>
          ) : (
            <>
              {Page === "/" && (
                <div className="dashboard-layout">
                  <div className="dashboard-primary">
                    <EntryForm
                      key={`${date}-${editingPeriod ?? "current"}`}
                      date={date}
                      entries={entries}
                      onSave={save}
                      busy={busy}
                      canSave={!error && (localMode || !!session.user)}
                      loadBlocked={!!error}
                      onInteractionLock={setMediaBusy}
                      onDirtyChange={setDraftDirty}
                      onLogin={() => setModal("login")}
                      onScale={() => setModal("scale")}
                      initialPeriod={editingPeriod}
                    />
                    <section className="card chart-card">
                      <div className="card-heading">
                        <div>
                          <h2>Um olhar para a sua semana</h2>
                          <p>Pequenos registros ajudam a enxergar o todo.</p>
                        </div>
                        <Link href="/panorama" className="text-button">
                          Ver panorama
                          <ArrowUpRight size={15} />
                        </Link>
                      </div>
                      <MoodChart entries={chartEntries} demo={illustrative} />
                      <div className="chart-footnote">
                        <span>Os pontos são registros, não diagnósticos.</span>
                        {entries.length === 0 && (
                          <button
                            className="text-button"
                            onClick={() => setShowExamples(!showExamples)}
                          >
                            {showExamples
                              ? "Ocultar exemplo"
                              : "Ver exemplo de gráfico"}
                          </button>
                        )}
                      </div>
                    </section>
                  </div>
                  <aside className="dashboard-aside">
                    <section className="card today-card">
                      <div className="card-heading">
                        <h2>Seu dia, em momentos</h2>
                        <span className="day-icon">
                          <Sun size={20} />
                        </span>
                      </div>
                      <div className="progress-intro">
                        <div
                          className="progress-ring"
                          style={
                            {
                              "--progress": `${todayEntries.length * 120}deg`,
                            } as React.CSSProperties
                          }
                        >
                          <span>
                            <strong>{todayEntries.length}</strong>
                            <small>de 3</small>
                          </span>
                        </div>
                        <p>
                          {todayEntries.length === 3
                            ? "Seu dia está registrado."
                            : todayEntries.length
                              ? "Mais um momento para se perceber."
                              : "Seu primeiro momento pode ser agora."}
                          <small>Não precisa ser perfeito.</small>
                        </p>
                      </div>
                      <div className="today-periods">
                        {periods.map(p => {
                          const record = todayEntries.find(
                            e => e.period === p.id
                          );
                          const Icon =
                            p.id === "morning"
                              ? Sun
                              : p.id === "afternoon"
                                ? Sunset
                                : Moon;
                          return (
                            <button
                              key={p.id}
                              onClick={() => {
                                if (record) edit(record);
                                else {
                                  if (!mediaBusy && !busy && canLeaveDraft())
                                    setEditingPeriod(p.id);
                                }
                              }}
                              className={record ? "done" : ""}
                            >
                              <Icon size={16} />
                              <span>{p.label}</span>
                              {record ? (
                                <>
                                  <small>{moods[record.mood + 3].label}</small>
                                  <Check size={15} />
                                </>
                              ) : (
                                <small>
                                  Não registrado<span>—</span>
                                </small>
                              )}
                            </button>
                          );
                        })}
                      </div>
                    </section>
                    <section className="gentle-card">
                      <span className="gentle-icon">
                        <Leaf size={23} />
                      </span>
                      <span className="gentle-eyebrow">UM LEMBRETE GENTIL</span>
                      <h3>Você é mais que o seu humor de hoje.</h3>
                      <p>
                        Todos os sentimentos têm espaço aqui. Observe, registre
                        e siga no seu tempo.
                      </p>
                      <div className="gentle-lines" aria-hidden="true" />
                    </section>
                    <section className="support-card">
                      <span className="support-icon">
                        <HeartHandshake size={22} />
                      </span>
                      <div>
                        <h3>Você tem com quem contar.</h3>
                        <p>
                          Seu diário acompanha o cuidado.
                          <br />
                          Não substitui sua equipe de saúde.
                        </p>
                        <Link href="/cuidado" className="text-button">
                          Conhecer recursos de apoio
                          <ArrowUpRight size={14} />
                        </Link>
                      </div>
                    </section>
                  </aside>
                  <section className="card recent-card">
                    <div className="card-heading">
                      <div>
                        <h2>Seus últimos registros</h2>
                        <p>Uma história construída um momento de cada vez.</p>
                      </div>
                      <Link href="/historico" className="text-button">
                        Ver histórico
                        <ArrowUpRight size={15} />
                      </Link>
                    </div>
                    {entries.length ? (
                      <EntryList
                        entries={latest.slice(0, 3)}
                        onEdit={edit}
                        onDelete={setDeleting}
                        compact
                      />
                    ) : (
                      <div className="recent-empty">
                        <span className="soft-icon">
                          <BookOpen size={22} />
                        </span>
                        <div>
                          <h3>Seu diário começa com um registro.</h3>
                          <p>
                            Escolha um humor acima. Os próximos momentos ficam
                            guardados aqui.
                          </p>
                        </div>
                      </div>
                    )}
                  </section>
                </div>
              )}
              {Page === "/historico" && (
                <History
                  entries={entries}
                  onEdit={edit}
                  onDelete={setDeleting}
                />
              )}
              {Page === "/panorama" && (
                <div className="panorama-layout">
                  <div className="summary-cards">
                    <div className="card summary-card">
                      <span className="soft-icon">
                        <BookOpen size={21} />
                      </span>
                      <div>
                        <span>Registros pessoais</span>
                        <strong>{entries.length}</strong>
                        <small>Todos os períodos anotados</small>
                      </div>
                    </div>
                    <div className="card summary-card">
                      <span className="soft-icon">
                        <CalendarDays size={21} />
                      </span>
                      <div>
                        <span>Dias com anotações</span>
                        <strong>{recordedDays}</strong>
                        <small>Cada dia tem sua história</small>
                      </div>
                    </div>
                    <div className="card summary-card">
                      <span className="soft-icon">
                        <Moon size={21} />
                      </span>
                      <div>
                        <span>Sono informado</span>
                        <strong>
                          {averageSleep}
                          <small> h</small>
                        </strong>
                        <small>
                          Média de {sleepByDay.length} dias, uma informação por
                          dia
                        </small>
                      </div>
                    </div>
                  </div>
                  <section className="card chart-card">
                    <div className="card-heading">
                      <div>
                        <h2>Seu humor ao longo do tempo</h2>
                        <p>
                          Sem médias de humor: cada período mantém seu próprio
                          registro.
                        </p>
                      </div>
                      <select
                        aria-label="Intervalo do gráfico"
                        value={chartDays}
                        onChange={e => setChartDays(Number(e.target.value))}
                      >
                        <option value={7}>Últimos 7 dias</option>
                        <option value={30}>Últimos 30 dias</option>
                        <option value={90}>Últimos 90 dias</option>
                      </select>
                    </div>
                    <MoodChart
                      entries={chartEntries}
                      days={chartDays}
                      demo={illustrative}
                    />
                    <div className="chart-footnote">
                      <span>
                        Lacunas significam ausência de registro, não
                        estabilidade.
                      </span>
                      {entries.length === 0 && (
                        <button
                          className="text-button"
                          onClick={() => setShowExamples(!showExamples)}
                        >
                          {showExamples
                            ? "Ocultar dados ilustrativos"
                            : "Mostrar dados ilustrativos"}
                        </button>
                      )}
                    </div>
                  </section>
                  <div className="insight-card">
                    <Info size={23} />
                    <div>
                      <h3>Perceber padrões é um trabalho em conjunto.</h3>
                      <p>
                        O gráfico não identifica episódios de mania ou
                        depressão. Mudanças no sono, irritabilidade e sintomas
                        mistos merecem contexto. Exporte seus registros e
                        converse com sua psicóloga ou psiquiatra.
                      </p>
                      <button
                        className="text-button"
                        onClick={() => setModal("export")}
                      >
                        Preparar registros para a consulta
                        <ArrowUpRight size={15} />
                      </button>
                    </div>
                  </div>
                </div>
              )}
              {Page === "/relatorios" && (
                <ReportsPage
                  entries={entries}
                  controller={automation}
                  onDirtyChange={setAutomationDirty}
                  dataError={!!error}
                />
              )}
              {Page === "/cuidado" && (
                <div className="care-layout">
                  <section className="care-hero">
                    <span className="soft-icon">
                      <HeartHandshake size={30} />
                    </span>
                    <h2>
                      Cuidar de você também
                      <br />é pedir companhia.
                    </h2>
                    <p>
                      O diário é uma ferramenta de observação, não um
                      atendimento. Se perceber mudanças intensas, menos
                      necessidade de sono ou dificuldade para sua rotina,
                      procure sua equipe de cuidado. Não mude a medicação com
                      base no aplicativo.
                    </p>
                    <button
                      className="outline-button"
                      onClick={() => setModal("export")}
                    >
                      <FileDown size={17} />
                      Levar meu diário à consulta
                    </button>
                  </section>
                  <section className="card crisis-card">
                    <div className="card-heading">
                      <h2>Apoio quando você precisar</h2>
                      <Heart size={20} />
                    </div>
                    <p>
                      Se estiver difícil lidar com o que sente, procure alguém
                      de confiança e um profissional de saúde.
                    </p>
                    <a className="contact-resource" href="tel:188">
                      <span className="soft-icon">
                        <Phone size={19} />
                      </span>
                      <span>
                        <strong>CVV · 188</strong>
                        <small>Apoio emocional gratuito, 24 horas</small>
                      </span>
                      <ArrowUpRight size={19} />
                    </a>
                    <a className="contact-resource urgent" href="tel:192">
                      <span className="soft-icon">
                        <Phone size={19} />
                      </span>
                      <span>
                        <strong>SAMU · 192</strong>
                        <small>Emergência médica no Brasil</small>
                      </span>
                      <ArrowUpRight size={19} />
                    </a>
                    <div className="care-notice">
                      Se houver risco imediato à sua segurança ou à de outra
                      pessoa, ligue 192 ou vá a um pronto-socorro. O CVV não
                      substitui atendimento de emergência.
                    </div>
                    <a
                      href="https://cvv.org.br/"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-button"
                    >
                      Outras formas de falar com o CVV
                      <ArrowUpRight size={14} />
                    </a>
                  </section>
                  <section className="card scale-reference">
                    <h2>
                      Uma escala para se descrever,
                      <br />
                      não para se diagnosticar.
                    </h2>
                    <p>
                      Entre humor muito baixo e euforia, há muitos jeitos de se
                      sentir. Combine com sua psicóloga o significado dos níveis
                      para você. Mania também pode envolver irritabilidade, não
                      apenas euforia.
                    </p>
                    <button
                      className="text-button"
                      onClick={() => setModal("scale")}
                    >
                      Entender todos os níveis
                      <ArrowUpRight size={15} />
                    </button>
                  </section>
                </div>
              )}
              {Page === "/configuracoes" && (
                <div className="settings-layout">
                  <NotificationsPanel
                    controller={automation}
                    onDirtyChange={setAutomationDirty}
                  />
                  <section className="card settings-card">
                    <h2>
                      <Send size={21} />
                      Relatórios para sua rede de cuidado
                    </h2>
                    <div className="setting-row">
                      <div>
                        <strong>Compartilhar no seu ritmo</strong>
                        <p>
                          Prepare um relatório ou escolha envios diários,
                          semanais, mensais ou personalizados. Você controla
                          destinatários e conteúdo.
                        </p>
                      </div>
                      <Link href="/relatorios" className="outline-button">
                        Abrir relatórios
                      </Link>
                    </div>
                  </section>
                  <section className="card settings-card">
                    <h2>
                      <ShieldCheck size={21} />
                      Seu diário, seus dados
                    </h2>
                    <div className="setting-row">
                      <div>
                        <strong>Armazenamento atual</strong>
                        <p>
                          {localMode
                            ? "Neste navegador. Os registros não são enviados a um servidor."
                            : "Na sua conta, com acesso protegido por sessão."}
                        </p>
                      </div>
                      <span className="status-label">
                        {localMode ? "Local" : "Conta"}
                      </span>
                    </div>
                    <div className="setting-row">
                      <div>
                        <strong>Conta e sincronização</strong>
                        <p>
                          {session.user && !localMode
                            ? `Conectado como ${session.user.name}.`
                            : "Google, X ou Microsoft. A integração precisa estar configurada no servidor."}
                        </p>
                      </div>
                      <button
                        className="outline-button"
                        onClick={() => setModal("login")}
                      >
                        Gerenciar conta
                      </button>
                    </div>
                    <div className="setting-row">
                      <div>
                        <strong>Exportar seus registros</strong>
                        <p>
                          Baixe JSON ou CSV para guardar ou levar à consulta.
                          Mídias não são incluídas.
                        </p>
                      </div>
                      <button
                        className="outline-button"
                        onClick={() => setModal("export")}
                      >
                        <FileDown size={16} />
                        Exportar
                      </button>
                    </div>
                    <div className="setting-row">
                      <div>
                        <strong>Sobre sua privacidade</strong>
                        <p>
                          Saiba o que é salvo e os cuidados com dispositivos
                          compartilhados.
                        </p>
                      </div>
                      <button
                        className="text-button"
                        onClick={() => setModal("privacy")}
                      >
                        Ler detalhes
                        <ArrowUpRight size={14} />
                      </button>
                    </div>
                    {localMode && (
                      <div className="setting-row danger-row">
                        <div>
                          <strong>Apagar os registros deste navegador</strong>
                          <p>
                            Remove o diário e os anexos locais. Não pode ser
                            desfeito.
                          </p>
                        </div>
                        <button
                          className="outline-button danger-button"
                          onClick={() => setModal("clear")}
                          disabled={!entries.length || !!error}
                        >
                          Apagar
                        </button>
                      </div>
                    )}
                  </section>
                  <section className="card settings-card">
                    <h2>
                      <Settings2 size={21} />
                      Conforto e acessibilidade
                    </h2>
                    <label className="setting-row">
                      <span>
                        <strong>Texto maior</strong>
                        <p>Mais conforto para ler e preencher.</p>
                      </span>
                      <input
                        className="switch-control"
                        type="checkbox"
                        checked={largeText}
                        onChange={e => setLargeText(e.target.checked)}
                      />
                    </label>
                    <label className="setting-row">
                      <span>
                        <strong>Reduzir movimentos</strong>
                        <p>
                          O app também respeita a preferência do seu sistema.
                        </p>
                      </span>
                      <input
                        className="switch-control"
                        type="checkbox"
                        checked={reducedMotion}
                        onChange={e => setReducedMotion(e.target.checked)}
                      />
                    </label>
                    <div className="setting-row">
                      <div>
                        <strong>Instalar no dispositivo</strong>
                        <p>
                          Acesse seu diário pela tela inicial, sem precisar de
                          loja de aplicativos.
                        </p>
                      </div>
                      <button className="outline-button" onClick={install}>
                        <Download size={16} />
                        {installed ? "Instalado" : "Instalar"}
                      </button>
                    </div>
                  </section>
                  <div className="version-note">
                    <Mark small />
                    <span>
                      Bipolar · Versão 1.1
                      <br />
                      Feito para observar. Não para julgar.
                    </span>
                  </div>
                </div>
              )}
              {!current && (
                <div className="empty-state">
                  <BookOpen size={34} />
                  <h2>Vamos voltar ao seu diário?</h2>
                  <Link href="/" className="primary-button">
                    Abrir meu diário
                  </Link>
                </div>
              )}
            </>
          )}
          <footer className="page-footer">
            <span>
              <Mark small />
              Cada registro conta. No seu ritmo.
            </span>
            <button className="text-button" onClick={() => setModal("privacy")}>
              Privacidade
            </button>
            <span>Não substitui acompanhamento profissional.</span>
          </footer>
        </main>
      </div>
      <nav className="mobile-nav" aria-label="Navegação no celular">
        {navigation.map(n => (
          <Link
            key={n.path}
            href={n.path}
            className={location === n.path ? "active" : ""}
            aria-current={location === n.path ? "page" : undefined}
          >
            <n.icon size={21} />
            <span>{n.short}</span>
          </Link>
        ))}
      </nav>
      <Modal
        open={modal === "login"}
        onClose={() => setModal(null)}
        title="Seu diário acompanha você."
        description="Entre para acessar os registros da sua conta."
      >
        {session.user && !localMode ? (
          <div className="account-panel">
            <span className="soft-icon">
              <ShieldCheck size={28} />
            </span>
            <h3>{session.user.name}</h3>
            <p>
              {session.user.email || "Conta conectada por provedor externo."}
            </p>
            <button
              className="outline-button"
              onClick={async () => {
                await logout();
                setModal(null);
              }}
            >
              <LogOut size={16} />
              Sair da conta
            </button>
          </div>
        ) : (
          <>
            <div className="auth-buttons">
              {(["google", "x", "microsoft"] as const).map(provider => (
                <button
                  key={provider}
                  className="oauth-button"
                  disabled={!serverEnabled || !session.providers[provider]}
                  onClick={() => {
                    request<{ url: string }>("oauth_start", undefined, {
                      provider,
                    })
                      .then(data => window.location.assign(data.url))
                      .catch(err => toast.error(err.message));
                  }}
                >
                  <span className={`provider-logo ${provider}`}>
                    {provider === "google" ? (
                      "G"
                    ) : provider === "x" ? (
                      "𝕏"
                    ) : (
                      <span className="microsoft-mark">
                        <i />
                        <i />
                        <i />
                        <i />
                      </span>
                    )}
                  </span>
                  Continuar com{" "}
                  {provider === "google"
                    ? "Google"
                    : provider === "x"
                      ? "X"
                      : "Microsoft"}
                  <ChevronRight size={16} />
                </button>
              ))}
            </div>
            {!serverEnabled ||
            !Object.values(session.providers).some(Boolean) ? (
              <div className="auth-notice">
                <Info size={17} />
                <p>
                  O login social ainda não está conectado nesta prévia. Os
                  botões serão habilitados quando o backend PHP e as credenciais
                  dos provedores forem configurados. Nenhuma conta é simulada.
                </p>
              </div>
            ) : (
              <p className="field-hint">
                Você será direcionado ao provedor. O diário nunca recebe sua
                senha.
              </p>
            )}
            {session.user && localMode && (
              <button
                className="primary-button full-width"
                onClick={() => {
                  setLocalMode(false);
                  setModal(null);
                }}
              >
                Abrir os registros da minha conta
              </button>
            )}
            <div className="auth-divider">
              <span>ou</span>
            </div>
            <button
              className="outline-button full-width"
              onClick={() => {
                setLocalMode(true);
                setModal(null);
              }}
            >
              {localMode
                ? "Continuar neste dispositivo"
                : "Usar apenas neste dispositivo"}
            </button>
            <p className="field-hint centered">
              Modo local não tem senha nem sincronização. Registros locais não
              são importados automaticamente para uma conta.
            </p>
          </>
        )}
      </Modal>
      <Modal
        open={modal === "privacy"}
        onClose={() => setModal(null)}
        title="Privacidade sem letras miúdas."
        description="Saúde é pessoal. Você decide o que registrar."
      >
        <div className="prose">
          <h3>No modo local</h3>
          <p>
            Humor, notas e mídia ficam no IndexedDB deste navegador. Não são
            enviados à nuvem e não estão criptografados ou protegidos por login.
            Quem usa seu dispositivo e este perfil de navegador pode acessar o
            diário.
          </p>
          <p>
            Limpar os dados do navegador ou desinstalar o app pode apagar
            registros. Exporte regularmente. Arquivos de áudio e vídeo não
            entram no CSV ou JSON; guarde os originais quando necessário.
          </p>
          <h3>Com uma conta conectada</h3>
          <p>
            O backend PHP utiliza sessões autenticadas, verificações de
            propriedade e criptografia para conteúdo sensível. Não existe
            sincronização neste ambiente enquanto o backend não for instalado e
            os provedores configurados.
          </p>
          <h3>Você está no controle</h3>
          <p>
            Mídia é opcional e depende de consentimento. Não usamos seus relatos
            para diagnósticos, publicidade ou análise por inteligência
            artificial. Exportar gera um arquivo sem senha: compartilhe apenas
            com quem você escolher.
          </p>
          <div className="care-notice">
            Este diário não é monitorado por profissionais e não envia alertas
            de emergência. Em risco imediato no Brasil, ligue 192.
          </div>
        </div>
        <button
          className="primary-button full-width"
          onClick={() => setModal(null)}
        >
          Entendi
        </button>
      </Modal>
      <Modal
        open={modal === "scale"}
        onClose={() => setModal(null)}
        title="Seu humor tem mais de um tom."
        description="Sete níveis descritivos para conversar com sua equipe de cuidado."
      >
        <div className="scale-modal-list">
          {moods
            .slice()
            .reverse()
            .map(m => (
              <div key={m.value}>
                <span style={{ color: m.color }}>
                  <MoodFace value={m.value} size={35} />
                </span>
                <div>
                  <strong>
                    {m.value > 0 ? "+" : ""}
                    {m.value} · {m.label}
                  </strong>
                  <p>{m.description}</p>
                </div>
              </div>
            ))}
        </div>
        <div className="care-notice">
          Esta é uma escala subjetiva simplificada, não um instrumento clínico
          validado. Humor elevado não significa automaticamente hipomania ou
          mania, e humor baixo não confirma um episódio depressivo. Sintomas
          mistos são registrados à parte.
        </div>
        <p className="field-hint">
          Referências:{" "}
          <a
            href="https://www.bipolaruk.org/get-support/track-your-mood/mood-scale/"
            target="_blank"
            rel="noopener noreferrer"
          >
            Bipolar UK
          </a>{" "}
          e{" "}
          <a
            href="https://my.clevelandclinic.org/health/diseases/9294-bipolar-disorder"
            target="_blank"
            rel="noopener noreferrer"
          >
            Cleveland Clinic
          </a>
          . A escala original da Bipolar UK tem 11 níveis; esta não é uma
          reprodução dela.
        </p>
      </Modal>
      <Modal
        open={modal === "install"}
        onClose={() => setModal(null)}
        title="Um lugar na sua tela inicial."
        description="Instale o diário pelo navegador, sem precisar de uma loja."
      >
        <div className="install-guide">
          <div>
            <Smartphone size={26} />
            <h3>No iPhone ou iPad</h3>
            <p>
              Abra o endereço no Safari. Toque em Compartilhar e depois em
              “Adicionar à Tela de Início”.
            </p>
          </div>
          <div>
            <Laptop size={26} />
            <h3>No Android ou computador</h3>
            <p>
              Abra no Chrome ou Edge. Procure o ícone de instalar na barra de
              endereço, ou “Instalar aplicativo” no menu do navegador.
            </p>
          </div>
        </div>
        <div className="auth-notice">
          <Info size={18} />
          <p>
            O ícone depende do navegador, do HTTPS e dos critérios de
            instalação. A prévia incorporada pode não permitir instalar; abra o
            endereço em uma aba própria.
          </p>
        </div>
        <button
          className="primary-button full-width"
          onClick={() => setModal(null)}
        >
          Entendi
        </button>
      </Modal>
      <Modal
        open={modal === "export"}
        onClose={() => setModal(null)}
        title="Suas anotações, com você."
        description="Escolha o formato para guardar ou levar à consulta."
      >
        <div className="export-info">
          <FileDown size={30} />
          <strong>{entries.length} registros pessoais</strong>
          <p>
            Inclui humor, datas, períodos, notas, sono e contexto. Não inclui
            arquivos de áudio e vídeo, nem dados ilustrativos.
          </p>
        </div>
        <div className="export-actions">
          <button
            className="outline-button"
            disabled={!entries.length || !!error}
            onClick={() => {
              exportEntries(entries, "csv");
              toast.success("CSV preparado para download.");
            }}
          >
            Baixar CSV
          </button>
          <button
            className="primary-button"
            disabled={!entries.length || !!error}
            onClick={() => {
              exportEntries(entries, "json");
              toast.success("JSON preparado para download.");
            }}
          >
            Baixar JSON
          </button>
        </div>
        <p className="care-notice">
          O arquivo contém dados sensíveis e não tem senha. Guarde com segurança
          e compartilhe conscientemente.
        </p>
      </Modal>
      <Modal
        open={!!deleting}
        onClose={() => !busy && setDeleting(null)}
        title="Excluir este registro?"
        description="O humor, os detalhes e os anexos deste período serão removidos."
      >
        <p className="care-notice">Esta ação não pode ser desfeita.</p>
        <div className="dialog-actions">
          <button
            className="outline-button"
            disabled={busy}
            onClick={() => setDeleting(null)}
          >
            Manter registro
          </button>
          <button
            className="primary-button danger-solid"
            disabled={busy}
            onClick={removeEntry}
          >
            {busy ? "Excluindo…" : "Excluir registro"}
          </button>
        </div>
      </Modal>
      <Modal
        open={modal === "clear"}
        onClose={() => !busy && setModal(null)}
        title="Apagar seu diário local?"
        description={`Os ${entries.length} registros e seus anexos serão apagados apenas deste navegador.`}
      >
        <p className="care-notice">
          Não pode ser desfeito. Considere exportar seus registros primeiro.
          Isso não apaga registros de uma conta no servidor.
        </p>
        <div className="dialog-actions">
          <button
            className="outline-button"
            disabled={busy}
            onClick={() => setModal(null)}
          >
            Cancelar
          </button>
          <button
            className="primary-button danger-solid"
            disabled={busy}
            onClick={clear}
          >
            {busy ? "Apagando…" : "Apagar registros locais"}
          </button>
        </div>
      </Modal>
    </div>
  );
}
