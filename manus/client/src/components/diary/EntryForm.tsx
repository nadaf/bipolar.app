import { useEffect, useState } from "react";
import {
  Check,
  ChevronDown,
  ChevronUp,
  Sun,
  Sunset,
  Moon,
  PenLine,
  ShieldCheck,
  Loader2,
  Info,
} from "lucide-react";
import {
  dateLabel,
  emptyEntry,
  moods,
  periods,
  symptoms,
  factors,
  localDate,
  type Entry,
  type Period,
} from "@/lib/diary";
import MoodFace from "./MoodFace";
import MediaRecorder from "./MediaRecorder";

export default function EntryForm({
  date,
  entries,
  onSave,
  busy,
  canSave,
  onLogin,
  onScale,
  initialPeriod,
  onInteractionLock,
  onDirtyChange,
  loadBlocked = false,
}: {
  date: string;
  entries: Entry[];
  onSave: (entry: Entry) => Promise<Entry | undefined>;
  busy: boolean;
  canSave: boolean;
  onLogin: () => void;
  onScale: () => void;
  initialPeriod?: Period;
  onInteractionLock?: (busy: boolean) => void;
  onDirtyChange?: (dirty: boolean) => void;
  loadBlocked?: boolean;
}) {
  const [period, setPeriod] = useState<Period>(
    initialPeriod ?? emptyEntry().period
  );
  const [draft, setDraft] = useState<Entry>(() => emptyEntry(date, period));
  const [chosen, setChosen] = useState(false);
  const [details, setDetails] = useState(false);
  const [consent, setConsent] = useState(false);
  const [recorderBusy, setRecorderBusy] = useState(false);
  useEffect(() => {
    onInteractionLock?.(recorderBusy);
    return () => onInteractionLock?.(false);
  }, [recorderBusy, onInteractionLock]);
  const existing = entries.find(e => e.date === date && e.period === period);
  useEffect(() => {
    if (initialPeriod) setPeriod(initialPeriod);
  }, [initialPeriod]);
  useEffect(() => {
    const found = entries.find(e => e.date === date && e.period === period);
    setDraft(found ?? emptyEntry(date, period));
    setChosen(!!found);
    setConsent(!!found?.attachments.length);
  }, [date, period, entries]);
  const baseline = existing ?? emptyEntry(date, period);
  const dirty =
    (chosen && !existing) ||
    [
      "mood",
      "note",
      "sleep",
      "medication",
      "mixed",
      "symptoms",
      "factors",
    ].some(
      key =>
        JSON.stringify(draft[key as keyof Entry]) !==
        JSON.stringify(baseline[key as keyof Entry])
    ) ||
    draft.attachments.map(a => a.id).join(",") !==
      baseline.attachments.map(a => a.id).join(",");
  useEffect(() => {
    onDirtyChange?.(dirty);
    return () => onDirtyChange?.(false);
  }, [dirty, onDirtyChange]);
  function update<K extends keyof Entry>(key: K, value: Entry[K]) {
    setDraft(prev => ({ ...prev, [key]: value }));
  }
  function toggle(key: "symptoms" | "factors", value: string) {
    update(
      key,
      draft[key].includes(value)
        ? draft[key].filter(v => v !== value)
        : [...draft[key], value]
    );
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (loadBlocked || recorderBusy || busy) return;
    if (!canSave) {
      onLogin();
      return;
    }
    if (!chosen) return;
    const saved = await onSave(draft);
    if (saved) {
      setDraft(saved);
      setChosen(true);
    }
  }
  const PeriodIcons = { morning: Sun, afternoon: Sunset, night: Moon };
  return (
    <form className="card entry-card" onSubmit={submit}>
      <fieldset disabled={busy || loadBlocked} className="entry-controls">
        <div className="card-heading">
          <div className="heading-with-icon">
            <span className="soft-icon">
              <PenLine size={19} />
            </span>
            <div>
              <h2>Como você está se sentindo?</h2>
              <p>
                {date === localDate()
                  ? "Uma pausa para se perceber. Sem julgamentos."
                  : `Seu registro de ${dateLabel(date)}.`}
              </p>
            </div>
          </div>
          <button
            className="icon-button help-scale"
            type="button"
            onClick={onScale}
            aria-label="Entender a escala de humor"
          >
            <Info size={19} />
          </button>
        </div>
        <fieldset className="period-field">
          <legend className="sr-only">Período do registro</legend>
          <div className="period-tabs">
            {periods.map(p => {
              const Icon = PeriodIcons[p.id];
              const done = entries.some(
                e => e.date === date && e.period === p.id
              );
              return (
                <label
                  key={p.id}
                  className={`period-tab ${period === p.id ? "active" : ""}`}
                >
                  <input
                    type="radio"
                    name="period"
                    disabled={recorderBusy || busy}
                    checked={period === p.id}
                    onChange={() => {
                      if (
                        !dirty ||
                        window.confirm(
                          "Há alterações não salvas. Deseja trocar de período e descartá-las?"
                        )
                      )
                        setPeriod(p.id);
                    }}
                  />
                  <Icon size={18} />
                  <span>{p.label}</span>
                  <small>{p.hours}</small>
                  {done && (
                    <Check
                      size={14}
                      className="period-done"
                      aria-label="Já registrado"
                    />
                  )}
                </label>
              );
            })}
          </div>
        </fieldset>
        <div className="scale-topline">
          <span>Selecione seu humor</span>
          <button type="button" className="text-button" onClick={onScale}>
            Sobre a escala
          </button>
        </div>
        <fieldset className="mood-field">
          <legend className="sr-only">
            Humor de depressão -3 a euforia +3; escala descritiva, não
            diagnóstica
          </legend>
          <div className="mood-options">
            {moods.map(m => (
              <label
                key={m.value}
                className={`mood-option ${chosen && draft.mood === m.value ? "selected" : ""}`}
                style={
                  {
                    "--mood-color": m.color,
                    "--mood-bg": m.bg,
                  } as React.CSSProperties
                }
              >
                <input
                  type="radio"
                  name="mood"
                  value={m.value}
                  checked={chosen && draft.mood === m.value}
                  onChange={() => {
                    update("mood", m.value);
                    setChosen(true);
                  }}
                />
                <span className="mood-symbol">
                  <MoodFace value={m.value} />
                </span>
                <span className="mood-label">{m.label}</span>
                <span className="mood-number">
                  {m.value > 0 ? "+" : ""}
                  {m.value}
                </span>
                {chosen && draft.mood === m.value && (
                  <span className="mood-check">
                    <Check size={11} strokeWidth={3} />
                  </span>
                )}
              </label>
            ))}
          </div>
        </fieldset>
        <div className="selected-description" aria-live="polite">
          {chosen ? (
            <>
              <span
                className="color-dot"
                style={{ background: moods[draft.mood + 3].color }}
              />
              <strong>{moods[draft.mood + 3].label}.</strong>{" "}
              {moods[draft.mood + 3].description}
            </>
          ) : (
            <>
              <span className="color-dot" />O que mais se aproxima de como você
              se sente neste período?
            </>
          )}
        </div>
        {chosen && Math.abs(draft.mood) === 3 && (
          <p className="care-notice">
            Se essa sensação for intensa ou incomum, vale entrar em contato com
            sua equipe de cuidado. Se houver risco imediato, procure atendimento
            de urgência.
          </p>
        )}
        <div className="entry-note">
          <label htmlFor="quick-note">
            Quer contar um pouco mais? <span>Opcional</span>
          </label>
          <textarea
            id="quick-note"
            value={draft.note}
            onChange={e => update("note", e.target.value)}
            placeholder="O que aconteceu, o que você percebeu… Este espaço é seu."
            maxLength={5000}
            rows={2}
          />
        </div>
        <button
          className="details-toggle"
          type="button"
          onClick={() => setDetails(!details)}
          disabled={recorderBusy || busy}
          aria-expanded={details}
          aria-controls="entry-details"
        >
          <span>
            <span className="plus-symbol">{details ? "−" : "+"}</span>Sono,
            contexto, áudio e vídeo <small>Opcional</small>
          </span>
          {details ? <ChevronUp size={17} /> : <ChevronDown size={17} />}
        </button>
        {details && (
          <div id="entry-details" className="entry-details">
            <div className="field-row">
              <div className="form-field">
                <label htmlFor="sleep">Horas de sono</label>
                <input
                  id="sleep"
                  type="number"
                  min="0"
                  max="24"
                  step="0.5"
                  value={draft.sleep ?? ""}
                  onChange={e =>
                    update(
                      "sleep",
                      e.target.value === "" ? null : Number(e.target.value)
                    )
                  }
                  placeholder="Ex.: 7,5"
                />
                <span className="field-hint">
                  Última noite. Não precisa repetir em cada período.
                </span>
              </div>
              <div className="form-field">
                <label htmlFor="medication">Medicação prescrita</label>
                <select
                  id="medication"
                  value={draft.medication}
                  onChange={e =>
                    update("medication", e.target.value as Entry["medication"])
                  }
                >
                  <option value="">Prefiro não informar</option>
                  <option value="taken">Tomei conforme a prescrição</option>
                  <option value="not-taken">Não tomei / esqueci</option>
                  <option value="not-applicable">Não se aplica</option>
                </select>
                <span className="field-hint">
                  Apenas registro. Não altere a prescrição pelo app.
                </span>
              </div>
            </div>
            <fieldset className="tag-field">
              <legend>O que você percebeu?</legend>
              <div className="tag-options">
                {symptoms.map(s => (
                  <button
                    key={s}
                    type="button"
                    className={`tag-button ${draft.symptoms.includes(s) ? "active" : ""}`}
                    aria-pressed={draft.symptoms.includes(s)}
                    onClick={() => toggle("symptoms", s)}
                  >
                    {s}
                  </button>
                ))}
              </div>
            </fieldset>
            <label className="check-label mixed-label">
              <input
                type="checkbox"
                checked={draft.mixed}
                onChange={e => update("mixed", e.target.checked)}
              />
              <span>
                Sinto tristeza e aceleração/agitação ao mesmo tempo.
                <small>
                  Sintomas mistos são anotados separadamente; não são um nível
                  intermediário.
                </small>
              </span>
            </label>
            <fieldset className="tag-field">
              <legend>O que fez parte do seu dia?</legend>
              <div className="tag-options">
                {factors.map(f => (
                  <button
                    key={f}
                    type="button"
                    className={`tag-button ${draft.factors.includes(f) ? "active" : ""}`}
                    aria-pressed={draft.factors.includes(f)}
                    onClick={() => toggle("factors", f)}
                  >
                    {f}
                  </button>
                ))}
              </div>
            </fieldset>
            <div className="media-heading">
              <h3>Às vezes, falar é mais fácil.</h3>
              <p>Se preferir, registre com sua voz ou em vídeo.</p>
            </div>
            <MediaRecorder
              attachments={draft.attachments}
              onChange={v => update("attachments", v)}
              consent={consent}
              setConsent={setConsent}
              onBusyChange={setRecorderBusy}
            />
          </div>
        )}
        <div className="entry-footer">
          <span>
            <ShieldCheck size={15} />
            Seu registro é pessoal.
          </span>
          <button
            className="primary-button"
            type="submit"
            disabled={busy || !chosen || recorderBusy || loadBlocked}
          >
            {busy ? (
              <Loader2 size={17} className="spin" />
            ) : (
              <Check size={17} />
            )}
            {busy
              ? "Salvando…"
              : existing
                ? "Atualizar registro"
                : "Salvar registro"}
          </button>
        </div>
      </fieldset>
    </form>
  );
}
