import { useMemo } from "react";
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ReferenceArea,
} from "recharts";
import {
  dateLabel,
  moods,
  offsetDate,
  periods,
  localDate,
  type Entry,
  type Period,
} from "@/lib/diary";
const colors = { morning: "#6c9477", afternoon: "#d5b26c", night: "#9696b4" };
export default function MoodChart({
  entries,
  days = 7,
  demo = false,
}: {
  entries: Entry[];
  days?: number;
  demo?: boolean;
}) {
  const data = useMemo<
    (Record<Period, number | null> & { date: string; label: string })[]
  >(
    () =>
      Array.from({ length: days }, (_, i) => {
        const date = offsetDate(localDate(), i - days + 1);
        const day = entries.filter(e => e.date === date);
        return {
          date,
          label: dateLabel(date, { day: "2-digit", month: "2-digit" }),
          ...(Object.fromEntries(
            periods.map(p => [
              p.id,
              day.find(e => e.period === p.id)?.mood ?? null,
            ])
          ) as Record<Period, number | null>),
        };
      }),
    [entries, days]
  );
  return (
    <>
      <div className="chart-legend">
        {periods.map(p => (
          <span key={p.id}>
            <i style={{ background: colors[p.id] }} />
            {p.label}
          </span>
        ))}
        {demo && <span className="demo-tag">Dados ilustrativos</span>}
      </div>
      <div
        className="mood-chart"
        role="img"
        aria-label={`Gráfico descritivo de humor nos últimos ${days} dias, de depressão -3 a euforia +3. ${demo ? "Dados ilustrativos, não são registros pessoais." : "Consulte também a tabela de dados abaixo."}`}
      >
        <ResponsiveContainer width="100%" height="100%">
          <LineChart
            data={data}
            margin={{ top: 15, right: 16, left: 8, bottom: 2 }}
          >
            <ReferenceArea
              y1={-0.45}
              y2={0.45}
              fill="#ecf2e9"
              fillOpacity={0.7}
            />
            <CartesianGrid
              stroke="#e8ebe6"
              strokeDasharray="3 5"
              vertical={false}
            />
            <XAxis
              dataKey="label"
              tickLine={false}
              axisLine={false}
              tick={{ fill: "#7d857e", fontSize: 11 }}
              interval={days > 7 ? Math.floor(days / 7) : 0}
              dy={10}
            />
            <YAxis
              ticks={[-3, 0, 3]}
              domain={[-3, 3]}
              tickFormatter={v =>
                v === 3 ? "Euforia" : v === 0 ? "Estável" : "Depressão"
              }
              tick={{ fill: "#7d857e", fontSize: 10 }}
              width={65}
              axisLine={false}
              tickLine={false}
            />
            <Tooltip
              labelFormatter={(_, payload) =>
                payload?.[0] ? dateLabel(payload[0].payload.date) : ""
              }
              formatter={(value: number, name: string) => [
                moods[value + 3]?.label ?? "Sem registro",
                periods.find(p => p.id === name)?.label,
              ]}
              contentStyle={{
                border: "1px solid #e2e7df",
                borderRadius: 12,
                fontSize: 12,
                boxShadow: "0 4px 18px #233d2010",
              }}
            />
            {periods.map(p => (
              <Line
                key={p.id}
                dataKey={p.id}
                type="linear"
                connectNulls={false}
                stroke={colors[p.id]}
                strokeWidth={2.2}
                dot={{ r: 3, strokeWidth: 2, fill: "#fff" }}
                activeDot={{ r: 5 }}
                isAnimationActive={false}
              />
            ))}
          </LineChart>
        </ResponsiveContainer>
      </div>
      <details className="chart-table">
        <summary>Ver os dados em texto</summary>
        <div className="table-scroll">
          <table>
            <caption>
              {demo
                ? "Exemplo de visualização, não são dados pessoais"
                : "Registros de humor por período"}
            </caption>
            <thead>
              <tr>
                <th>Data</th>
                {periods.map(p => (
                  <th key={p.id}>{p.label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.map(d => (
                <tr key={d.date}>
                  <th>{d.label}</th>
                  {periods.map(p => (
                    <td key={p.id}>
                      {d[p.id] === null
                        ? "Sem registro"
                        : moods[Number(d[p.id]) + 3].label}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </>
  );
}
