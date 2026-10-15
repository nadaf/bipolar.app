export default function MoodFace({
  value,
  size = 42,
}: {
  value: number;
  size?: number;
}) {
  const y = value < 0 ? 29 : value > 0 ? 24 : 26;
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="24" cy="24" r="21" fill="currentColor" opacity=".13" />
      <circle
        cx="24"
        cy="24"
        r="19"
        stroke="currentColor"
        strokeWidth="1.4"
        opacity=".7"
      />
      {value === 3 ? (
        <>
          <path
            d="M15 19l3-2 3 2m7 0l3-2 3 2"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
          />
          <ellipse
            cx="24"
            cy="29"
            rx="5"
            ry="4.3"
            stroke="currentColor"
            strokeWidth="1.7"
          />
        </>
      ) : (
        <>
          <circle cx="17.5" cy="20" r="1.6" fill="currentColor" />
          <circle cx="30.5" cy="20" r="1.6" fill="currentColor" />
          {value === 0 ? (
            <path
              d="M18 28q6 4 12 0"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
            />
          ) : (
            <path
              d={`M17 ${y} Q24 ${value < 0 ? y - 7 - Math.abs(value) : y + 7 + value} 31 ${y}`}
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
            />
          )}
          {value === -3 && (
            <path
              d="M31 23q-2 3 0 4q2-1 0-4"
              fill="currentColor"
              opacity=".7"
            />
          )}
        </>
      )}
    </svg>
  );
}
