import { useMemo, useState } from "react";
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  PenLine,
  Trash2,
  Search,
  Filter,
  Mic,
  Video,
  BookOpen,
} from "lucide-react";
import {
  dateLabel,
  dateObject,
  localDate,
  moods,
  offsetDate,
  periods,
  type Entry,
  type Period,
} from "@/lib/diary";
import MoodFace from "./MoodFace";
import { MediaPreview } from "./MediaRecorder";

export function EntryList({
  entries,
  onEdit,
  onDelete,
  demo = false,
  compact = false,
}: {
  entries: Entry[];
  onEdit: (e: Entry) => void;
  onDelete: (e: Entry) => void;
  demo?: boolean;
  compact?: boolean;
}) {
  const [expanded, setExpanded] = useState<string | null>(null);
  return (
    <div className={`entries-list ${compact ? "compact-list" : ""}`}>
      {entries.map(e => (
        <article className="entry-item" key={e.id}>
          <span
            className="entry-face"
            style={{ color: moods[e.mood + 3].color }}
          >
            <MoodFace value={e.mood} size={38} />
          </span>
          <div className="entry-item-content">
            <div className="entry-item-title">
              <strong>{moods[e.mood + 3].label}</strong>
              {e.mixed && <span className="mixed-tag">Sintomas mistos</span>}
              <span>
                {dateLabel(e.date, { day: "2-digit", month: "short" })} ·{" "}
                {periods.find(p => p.id === e.period)?.label}
              </span>
            </div>
            {e.note && <p>{e.note}</p>}
            {!compact && (
              <div className="entry-metadata">
                {e.sleep !== null && <span>{e.sleep} h de sono</span>}
                {e.symptoms.map(s => (
                  <span key={s}>{s}</span>
                ))}
                {e.attachments.length > 0 && (
                  <button
                    className="text-button"
                    onClick={() => setExpanded(expanded === e.id ? null : e.id)}
                    aria-expanded={expanded === e.id}
                  >
                    {e.attachments.some(a => a.type === "video") ? (
                      <Video size={14} />
                    ) : (
                      <Mic size={14} />
                    )}
                    {e.attachments.length}{" "}
                    {e.attachments.length === 1 ? "anexo" : "anexos"}
                  </button>
                )}
              </div>
            )}
            {expanded === e.id &&
              e.attachments.map(a => (
                <MediaPreview key={a.id} attachment={a} />
              ))}
          </div>
          {!demo && (
            <div className="entry-item-actions">
              <button
                className="icon-button"
                onClick={() => onEdit(e)}
                aria-label={`Editar registro de ${dateLabel(e.date)}, ${periods.find(p => p.id === e.period)?.label}`}
              >
                <PenLine size={16} />
              </button>
              {!compact && (
                <button
                  className="icon-button"
                  onClick={() => onDelete(e)}
                  aria-label={`Excluir registro de ${dateLabel(e.date)}, ${periods.find(p => p.id === e.period)?.label}`}
                >
                  <Trash2 size={16} />
                </button>
              )}
            </div>
          )}
        </article>
      ))}
    </div>
  );
}
export default function History({
  entries,
  onEdit,
  onDelete,
}: {
  entries: Entry[];
  onEdit: (e: Entry) => void;
  onDelete: (e: Entry) => void;
}) {
  const [month, setMonth] = useState(() => localDate().slice(0, 7));
  const [selected, setSelected] = useState<string | null>(null);
  const [period, setPeriod] = useState<Period | "">("");
  const [mood, setMood] = useState("");
  const [search, setSearch] = useState("");
  const [limit, setLimit] = useState(15);
  const first = dateObject(`${month}-01`);
  const start = (first.getDay() + 6) % 7;
  const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
  function changeMonth(delta: number) {
    const next = new Date(first);
    next.setMonth(next.getMonth() + delta);
    setMonth(localDate(next).slice(0, 7));
    setSelected(null);
  }
  const filtered = useMemo(
    () =>
      entries
        .filter(
          e =>
            e.date.startsWith(month) &&
            (!selected || e.date === selected) &&
            (!period || e.period === period) &&
            (mood === "" || e.mood === Number(mood)) &&
            (!search ||
              `${e.note} ${e.symptoms.join(" ")} ${e.factors.join(" ")}`
                .toLocaleLowerCase("pt-BR")
                .includes(search.toLocaleLowerCase("pt-BR")))
        )
        .sort((a, b) =>
          `${b.date}-${periods.findIndex(p => p.id === b.period)}`.localeCompare(
            `${a.date}-${periods.findIndex(p => p.id === a.period)}`
          )
        ),
    [entries, month, selected, period, mood, search]
  );
  return (
    <div className="history-layout">
      <section className="card calendar-card">
        <div className="card-heading">
          <h2>
            <CalendarDays size={19} />
            {first.toLocaleDateString("pt-BR", {
              month: "long",
              year: "numeric",
            })}
          </h2>
          <div className="calendar-arrows">
            <button
              className="icon-button"
              onClick={() => changeMonth(-1)}
              aria-label="Mês anterior"
            >
              <ChevronLeft size={19} />
            </button>
            <button
              className="icon-button"
              onClick={() => changeMonth(1)}
              aria-label="Próximo mês"
            >
              <ChevronRight size={19} />
            </button>
          </div>
        </div>
        <div className="calendar-grid">
          {["S", "T", "Q", "Q", "S", "S", "D"].map((d, i) => (
            <span className="weekday" key={i}>
              {d}
            </span>
          ))}
          {Array.from({ length: start }, (_, i) => (
            <span key={`blank-${i}`} />
          ))}
          {Array.from({ length: days }, (_, i) => {
            const date = `${month}-${String(i + 1).padStart(2, "0")}`;
            const dayEntries = entries.filter(e => e.date === date);
            return (
              <button
                key={date}
                className={`calendar-day ${date === localDate() ? "today" : ""} ${selected === date ? "selected" : ""}`}
                onClick={() => {
                  setSelected(selected === date ? null : date);
                  setLimit(15);
                }}
                aria-pressed={selected === date}
                aria-label={`${dateLabel(date)}, ${dayEntries.length} registros`}
              >
                <span>{i + 1}</span>
                <span className="calendar-dots">
                  {dayEntries.map(e => (
                    <i
                      key={e.id}
                      style={{ background: moods[e.mood + 3].color }}
                    />
                  ))}
                </span>
              </button>
            );
          })}
        </div>
        <button
          className="outline-button full-width"
          onClick={() => {
            setMonth(localDate().slice(0, 7));
            setSelected(null);
          }}
        >
          Voltar para este mês
        </button>
        <div className="calendar-note">
          <i className="color-dot" />
          Cada ponto representa um período registrado.
        </div>
      </section>
      <section className="card history-timeline">
        <div className="card-heading">
          <div>
            <h2>{selected ? dateLabel(selected) : "Suas anotações"}</h2>
            <p>
              {filtered.length}{" "}
              {filtered.length === 1
                ? "registro encontrado"
                : "registros encontrados"}
              {selected && (
                <button
                  className="text-button"
                  onClick={() => setSelected(null)}
                >
                  Todos os dias
                </button>
              )}
            </p>
          </div>
          <Filter size={19} />
        </div>
        <div className="history-filters">
          <label className="search-field">
            <Search size={16} />
            <input
              aria-label="Buscar nas anotações"
              value={search}
              onChange={e => {
                setSearch(e.target.value);
                setLimit(15);
              }}
              placeholder="Buscar nas anotações"
            />
          </label>
          <select
            aria-label="Filtrar período"
            value={period}
            onChange={e => {
              setPeriod(e.target.value as Period | "");
              setLimit(15);
            }}
          >
            <option value="">Todos os períodos</option>
            {periods.map(p => (
              <option key={p.id} value={p.id}>
                {p.label}
              </option>
            ))}
          </select>
          <select
            aria-label="Filtrar humor"
            value={mood}
            onChange={e => {
              setMood(e.target.value);
              setLimit(15);
            }}
          >
            <option value="">Todos os humores</option>
            {moods.map(m => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
        </div>
        {filtered.length ? (
          <>
            <EntryList
              entries={filtered.slice(0, limit)}
              onEdit={onEdit}
              onDelete={onDelete}
            />
            {filtered.length > limit && (
              <button
                className="outline-button full-width"
                onClick={() => setLimit(limit + 15)}
              >
                Carregar mais registros
              </button>
            )}
          </>
        ) : (
          <div className="empty-state">
            <BookOpen size={34} />
            <h3>Um espaço para a sua história.</h3>
            <p>
              {entries.length
                ? "Não há registros para estes filtros. Experimente outro período ou mês."
                : "Seus registros aparecerão aqui depois de salvar o primeiro humor."}
            </p>
          </div>
        )}
      </section>
    </div>
  );
}
