import { useState, useRef, useCallback, useEffect } from "react";

const WS_URL = import.meta.env.VITE_WS_URL || "ws://localhost:8000/ws";

export default function App() {
  const wsRef = useRef(null);
  const [screen, setScreen] = useState("landing");
  const [name, setName] = useState("");
  const [joinCode, setJoinCode] = useState("");
  const [playerId, setPlayerId] = useState(null);
  const [roomCode, setRoomCode] = useState(null);
  const [isHost, setIsHost] = useState(false);
  const [players, setPlayers] = useState([]);
  const [hostId, setHostId] = useState(null);
  const [submitted, setSubmitted] = useState([]);
  const [result, setResult] = useState(null);
  const [round, setRound] = useState(1);
  const [gameOver, setGameOver] = useState(false);
  const [matchWinnerId, setMatchWinnerId] = useState(null);
  const [value, setValue] = useState("");
  const [error, setError] = useState("");
  const [submittedSelf, setSubmittedSelf] = useState(false);
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem("bc-dark") === "1"; } catch { return false; }
  });
  const [mode, setMode] = useState(null); // null | 'single' | 'multi'
  const [botCount, setBotCount] = useState(3);
  useEffect(() => {
    document.documentElement.classList.toggle("dark", dark);
    try { localStorage.setItem("bc-dark", dark ? "1" : "0"); } catch {}
  }, [dark]);

  const connect = useCallback((onOpen) => {
    const ws = new WebSocket(WS_URL);
    wsRef.current = ws;
    ws.onopen = () => { if (onOpen) onOpen(ws); };
    ws.onmessage = (ev) => {
      let msg; try { msg = JSON.parse(ev.data); } catch { return; }
      switch (msg.type) {
        case "joined":
          setPlayerId(msg.playerId); setRoomCode(msg.code); setIsHost(msg.isHost); setPlayers(msg.players); setScreen("lobby"); break;
        case "lobby_update":
          setPlayers(msg.players); setHostId(msg.hostId); break;
        case "game_started":
          setScreen("game"); setRound(msg.round || 1); setSubmitted([]); setSubmittedSelf(false); setResult(null); setGameOver(false); setMatchWinnerId(null); break;
        case "round_start":
          setSubmitted(msg.submitted || []); setSubmittedSelf(false); if (msg.round) setRound(msg.round); break;
        case "submission_update":
          setSubmitted(msg.submitted || []); break;
        case "round_result":
          setResult(msg); setSubmitted(msg.submitted || []); setGameOver(!!msg.gameOver); setMatchWinnerId(msg.matchWinnerId); break;
        case "error":
          setError(msg.message); break;
        default: break;
      }
    };
    return ws;
  }, []);

  const createRoom = () => {
    if (!name.trim()) { setError("Enter your name"); return; }
    setError("");
    const isSingle = mode === "single";
    connect((ws) => ws.send(JSON.stringify({
      type: "create_room",
      name: name.trim(),
      mode: isSingle ? "single" : "multi",
      bots: isSingle ? botCount : 0,
    })));
  };
  const joinRoom = () => {
    if (!name.trim()) { setError("Enter your name"); return; }
    if (!joinCode.trim()) { setError("Enter room code"); return; }
    setError("");
    connect((ws) => ws.send(JSON.stringify({ type: "join_room", code: joinCode.trim(), name: name.trim() })));
  };
  const startGame = () => wsRef.current?.send(JSON.stringify({ type: "start_game" }));
  const submit = () => {
    const v = parseFloat(value);
    if (isNaN(v)) { setError("Enter a number 0-100"); return; }
    if (v < 0 || v > 100) { setError("Number must be 0-100"); return; }
    setError("");
    wsRef.current?.send(JSON.stringify({ type: "submit", value: v }));
    setSubmittedSelf(true);
  };
  const nextRound = () => wsRef.current?.send(JSON.stringify({ type: "next_round" }));

  const submittedSet = new Set(submitted);

  if (screen === "landing") {
    return (
      <div className="min-h-screen flex items-center justify-center px-4 py-10 relative">
        <button
          onClick={() => setDark((d) => !d)}
          aria-label="Toggle dark mode"
          className="absolute top-4 right-4 card px-3 py-1.5 text-sm font-medium hover:opacity-80"
        >
          {dark ? "Light" : "Dark"}
        </button>
        <div className="card animate-pop-in w-full max-w-md p-8">
          <div className="mb-6 text-center">
            <h1 className="text-3xl font-bold tracking-tight">Beauty Contest</h1>
            <p className="mt-2 text-sm text-gray-500 dark:text-[#a89fc0]">Guess 2/3 of the average. Last one standing wins.</p>
          </div>

          <div className="mb-5">
            <p className="text-sm font-medium mb-2">Game mode</p>
            <div className="grid grid-cols-2 gap-2">
              <button
                onClick={() => setMode("single")}
                className={`py-2 rounded-xl font-semibold border transition-colors ${mode === "single" ? "bg-emerald-600 text-white border-emerald-600" : "bg-transparent border-gray-300 hover:border-emerald-500"}`}
              >
                Single Player (vs AI)
              </button>
              <button
                onClick={() => setMode("multi")}
                className={`py-2 rounded-xl font-semibold border transition-colors ${mode === "multi" ? "bg-[#663298] text-white border-[#663298]" : "bg-transparent border-gray-300 hover:border-[#663298]"}`}
              >
                Multiplayer (Room)
              </button>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label htmlFor="name" className="block text-sm font-medium mb-1">Your name</label>
              <input id="name" className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Enter your name" />
            </div>

            {mode === "single" && (
              <div>
                <label htmlFor="bots" className="block text-sm font-medium mb-1">Number of AI opponents (1–9)</label>
                <input
                  id="bots"
                  type="number"
                  min="1"
                  max="9"
                  className="input"
                  value={botCount}
                  onChange={(e) => setBotCount(Math.max(1, Math.min(9, Number(e.target.value) || 1)))}
                />
              </div>
            )}

            {mode === "multi" && (
              <div>
                <label htmlFor="code" className="block text-sm font-medium mb-1">Room code (to join)</label>
                <input id="code" className="input uppercase tracking-widest" value={joinCode} onChange={(e) => setJoinCode(e.target.value)} placeholder="ABCDE" />
              </div>
            )}

            {error && <div role="alert" className="text-sm text-red-600">{error}</div>}

            {mode === "single" && (
              <button onClick={createRoom} className="w-full bg-emerald-600 text-white py-2.5 rounded-xl font-semibold hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                Start Single Player
              </button>
            )}

            {mode === "multi" && (
              <div className="flex gap-3 pt-2">
                <button onClick={createRoom} className="flex-1 bg-emerald-600 text-white py-2.5 rounded-xl font-semibold hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                  Create Room
                </button>
                <button onClick={joinRoom} className="flex-1 bg-[#663298] text-white py-2.5 rounded-xl font-semibold hover:bg-[#552a80] focus:outline-none focus:ring-2 focus:ring-[#663298]/40">
                  Join Room
                </button>
              </div>
            )}

            {!mode && (
              <p className="text-center text-sm text-gray-400 dark:text-[#a89fc0] pt-2">Choose a mode to continue</p>
            )}
          </div>
        </div>
      </div>
    );
  }

  if (screen === "lobby") {
    return (
      <div className="min-h-screen flex items-center justify-center px-4 py-10 relative">
        <button
          onClick={() => setDark((d) => !d)}
          aria-label="Toggle dark mode"
          className="absolute top-4 right-4 card px-3 py-1.5 text-sm font-medium hover:opacity-80"
        >
          {dark ? "Light" : "Dark"}
        </button>
        <div className="card animate-pop-in w-full max-w-md p-8">
          <div className="mb-6 text-center">
            <h1 className="text-2xl font-bold tracking-tight">Lobby</h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-[#a89fc0]">
              Room code: <span className="font-mono font-bold tracking-widest text-slate-900 dark:text-white">{roomCode}</span>
            </p>
          </div>
          <div className="mb-5">
            <h2 className="font-semibold text-sm text-gray-600 dark:text-[#a89fc0] mb-2">Players ({players.length}/10)</h2>
            <ul className="space-y-2">
              {players.map((p, i) => (
                <li
                  key={p.id}
                  className="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 animate-fade-in"
                  style={{ animationDelay: `${i * 40}ms` }}
                >
                  <span className="font-medium">
                    {p.name} {p.id === playerId && <span className="text-gray-400 dark:text-[#a89fc0]">(you)</span>}
                  </span>
                  {p.isHost && <span className="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-semibold">HOST</span>}
                </li>
              ))}
            </ul>
          </div>
          {error && <div role="alert" className="text-sm text-red-600 mb-3">{error}</div>}
          {isHost ? (
            <button
              onClick={startGame}
              disabled={players.length < 2}
              className="w-full bg-emerald-600 text-white py-2.5 rounded-xl font-semibold hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
            >
              Start Game
            </button>
          ) : (
            <p className="text-center text-gray-500 dark:text-[#a89fc0] text-sm">Waiting for host to start…</p>
          )}
        </div>
      </div>
    );
  }

  const gamePlayers = result ? result.players : players.map((p) => ({ id: p.id, name: p.name, choice: null, score: 0, isEliminated: false }));
  const winnerNames = result ? result.players.filter((p) => result.winnerIds?.includes(p.id)).map((p) => p.name) : [];

  return (
    <div className="min-h-screen px-4 py-6">
      <div className="max-w-5xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-5">
        <div className="md:col-span-2 card p-5 animate-fade-in">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-xl font-bold">Round {round}</h2>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setDark((d) => !d)}
                aria-label="Toggle dark mode"
                className="card px-2.5 py-1 text-xs font-medium hover:opacity-80"
              >
                {dark ? "Light" : "Dark"}
              </button>
              <span className="text-xs font-mono text-gray-400 dark:text-[#a89fc0]">Room {roomCode}</span>
            </div>
          </div>

          {!submittedSelf && !result && (
            <div className="mb-5">
              <label htmlFor="guess" className="block text-sm font-medium mb-1">Your number (0–100)</label>
              <div className="flex gap-2">
                <input
                  id="guess"
                  type="number"
                  min="0"
                  max="100"
                  className="input"
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
                />
                <button onClick={submit} className="bg-emerald-600 text-white px-5 py-2 rounded-xl font-semibold hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                  Submit
                </button>
              </div>
              {error && <div role="alert" className="text-sm text-red-600 mt-2">{error}</div>}
            </div>
          )}

          {submittedSelf && !result && (
            <div className="mb-4 flex items-center gap-2 text-emerald-700 font-medium animate-pop-in">
              <span className="inline-block h-2 w-2 rounded-full bg-emerald-500" />
              Submitted! Waiting for others…
            </div>
          )}

          <div className="mb-4">
            <h3 className="text-sm font-semibold text-gray-600 dark:text-[#a89fc0] mb-2">Submitted ({submitted.length}/{players.length})</h3>
            <div className="flex flex-wrap gap-2">
              {players.map((p) => (
                <span
                  key={p.id}
                  className={`px-3 py-1 rounded-full text-xs font-medium transition-colors duration-200 ${
                    submittedSet.has(p.id) ? "bg-emerald-100 text-emerald-800" : "bg-gray-100 text-gray-500 dark:text-[#a89fc0]"
                  }`}
                >
                  {p.name} {submittedSet.has(p.id) ? "✓" : "…"}
                </span>
              ))}
            </div>
          </div>

          {result && (
            <div className="mt-2 border-t border-gray-100 pt-4 animate-fade-in">
              <h3 className="font-bold mb-2">Round Results</h3>
              <p className="text-sm text-gray-700">
                Average: <b>{typeof result.avg === "number" ? result.avg.toFixed(2) : result.avg}</b>
                <span className="mx-2 text-gray-300">|</span>
                Target (×0.8): <b>{typeof result.target === "number" ? result.target.toFixed(2) : result.target}</b>
              </p>
              {result.logs?.length > 0 && (
                <ul className="text-red-600 text-sm mt-2 space-y-0.5">
                  {result.logs.map((l, i) => <li key={i}>&gt;&gt; {l}</li>)}
                </ul>
              )}
              {winnerNames.length > 0 && (
                <p className="text-emerald-600 font-bold mt-2 animate-pop-in">WINNER: {winnerNames.join(", ")}</p>
              )}
              {(!winnerNames.length) && (result.winnerIds?.length === 0) && (
                <p className="text-blue-600 font-bold mt-2">No Winner!</p>
              )}
              {gameOver && (
                <div className="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-xl animate-pop-in">
                  <p className="font-bold text-amber-900">
                    {matchWinnerId
                      ? `Game Over! ${gamePlayers.find((p) => p.id === matchWinnerId)?.name} wins the match!`
                      : "Game Over! No winner."}
                  </p>
                </div>
              )}
              {!gameOver && isHost && (
                <button onClick={nextRound} className="mt-4 bg-[#663298] text-white px-5 py-2 rounded-xl font-semibold hover:bg-[#552a80] focus:outline-none focus:ring-2 focus:ring-[#663298]/40">
                  Next Round
                </button>
              )}
              {!gameOver && !isHost && (
                <p className="text-gray-500 dark:text-[#a89fc0] text-sm mt-4">Waiting for host to start next round…</p>
              )}
            </div>
          )}
        </div>

        <div className="card p-5 animate-fade-in" style={{ animationDelay: "60ms" }}>
          <h3 className="font-bold mb-3">Scoreboard</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100 text-gray-500 dark:text-[#a89fc0]">
                <th className="text-left py-1.5 font-medium">Name</th>
                <th className="text-center py-1.5 font-medium">Pick</th>
                <th className="text-center py-1.5 font-medium">Score</th>
              </tr>
            </thead>
            <tbody>
              {gamePlayers.map((p) => (
                <tr
                  key={p.id}
                  className={`border-b border-gray-50 last:border-0 transition-colors ${p.isEliminated ? "bg-red-50 text-red-600" : ""}`}
                >
                  <td className="py-2 font-medium">{p.name} {p.id === playerId && <span className="text-gray-400 dark:text-[#a89fc0]">(you)</span>}</td>
                  <td className="text-center py-2">{p.isEliminated ? "—" : (p.choice != null ? Math.round(p.choice) : "—")}</td>
                  <td className="text-center py-2 font-semibold">{p.isEliminated ? "ELIM" : p.score}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}