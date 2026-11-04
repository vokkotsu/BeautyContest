import { useState, useRef, useCallback } from "react";

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
    connect((ws) => ws.send(JSON.stringify({ type: "create_room", name: name.trim() })));
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
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
          <h1 className="text-2xl font-bold mb-6 text-center">Beauty Contest</h1>
          <div className="mb-4">
            <label className="block text-sm mb-1">Your Name</label>
            <input className="w-full border rounded px-3 py-2" value={name} onChange={(e) => setName(e.target.value)} placeholder="Enter name" />
          </div>
          <div className="mb-4">
            <label className="block text-sm mb-1">Room Code (for joining)</label>
            <input className="w-full border rounded px-3 py-2" value={joinCode} onChange={(e) => setJoinCode(e.target.value)} placeholder="ABCDE" />
          </div>
          {error && <div className="text-red-500 text-sm mb-3">{error}</div>}
          <div className="flex gap-3">
            <button onClick={createRoom} className="flex-1 bg-green-500 text-white py-2 rounded font-semibold hover:bg-green-600">Create Room</button>
            <button onClick={joinRoom} className="flex-1 bg-blue-500 text-white py-2 rounded font-semibold hover:bg-blue-600">Join Room</button>
          </div>
        </div>
      </div>
    );
  }

  if (screen === "lobby") {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
          <h1 className="text-2xl font-bold mb-2 text-center">Lobby</h1>
          <p className="text-center text-gray-500 mb-4">Room Code: <span className="font-mono font-bold text-black">{roomCode}</span></p>
          <div className="mb-4">
            <h2 className="font-semibold mb-2">Players ({players.length}/10)</h2>
            <ul className="space-y-1">
              {players.map((p) => (
                <li key={p.id} className="flex justify-between border-b py-1">
                  <span>{p.name} {p.id === playerId && "(you)"}</span>
                  {p.isHost && <span className="text-xs bg-yellow-200 px-2 py-0.5 rounded">HOST</span>}
                </li>
              ))}
            </ul>
          </div>
          {error && <div className="text-red-500 text-sm mb-3">{error}</div>}
          {isHost ? (
            <button onClick={startGame} disabled={players.length < 2} className="w-full bg-green-500 text-white py-2 rounded font-semibold hover:bg-green-600 disabled:opacity-50">Start Game</button>
          ) : (
            <p className="text-center text-gray-500">Waiting for host to start...</p>
          )}
        </div>
      </div>
    );
  }

  const gamePlayers = result ? result.players : players.map((p) => ({ id: p.id, name: p.name, choice: null, score: 0, isEliminated: false }));
  const winnerNames = result ? result.players.filter((p) => result.winnerIds?.includes(p.id)).map((p) => p.name) : [];

  return (
    <div className="min-h-screen bg-gray-100 p-4">
      <div className="max-w-5xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="md:col-span-2 bg-white p-4 rounded-xl shadow">
          <div className="flex justify-between items-center mb-3">
            <h2 className="font-bold text-lg">Round {round}</h2>
            <span className="text-sm text-gray-500">Room {roomCode}</span>
          </div>

          {!submittedSelf && !result && (
            <div className="mb-4">
              <label className="block text-sm mb-1">Your number (0-100)</label>
              <div className="flex gap-2">
                <input type="number" min="0" max="100" className="flex-1 border rounded px-3 py-2" value={value} onChange={(e) => setValue(e.target.value)} />
                <button onClick={submit} className="bg-green-500 text-white px-4 py-2 rounded font-semibold hover:bg-green-600">Submit</button>
              </div>
              {error && <div className="text-red-500 text-sm mt-2">{error}</div>}
            </div>
          )}

          {submittedSelf && !result && (
            <p className="text-green-600 font-semibold mb-3">Submitted! Waiting for others...</p>
          )}

          <div className="mb-3">
            <h3 className="font-semibold text-sm mb-1">Submitted ({submitted.length}/{players.length})</h3>
            <div className="flex flex-wrap gap-2">
              {players.map((p) => (
                <span key={p.id} className={`px-2 py-1 rounded text-xs ${submittedSet.has(p.id) ? "bg-green-200" : "bg-gray-200"}`}>
                  {p.name} {submittedSet.has(p.id) ? "✓" : "…"}
                </span>
              ))}
            </div>
          </div>

          {result && (
            <div className="mt-4 border-t pt-3">
              <h3 className="font-bold mb-2">Round Results</h3>
              <p className="text-sm">Average: <b>{typeof result.avg === "number" ? result.avg.toFixed(2) : result.avg}</b> | Target (×0.8): <b>{typeof result.target === "number" ? result.target.toFixed(2) : result.target}</b></p>
              {result.logs?.length > 0 && (
                <ul className="text-red-600 text-sm mt-1">{result.logs.map((l, i) => <li key={i}>&gt;&gt; {l}</li>)}</ul>
              )}
              {winnerNames.length > 0 && <p className="text-green-600 font-bold mt-1">WINNER: {winnerNames.join(", ")}</p>}
              {(!winnerNames.length) && (result.winnerIds?.length === 0) && <p className="text-blue-600 font-bold mt-1">No Winner!</p>}
              {gameOver && (
                <div className="mt-3 p-3 bg-yellow-100 rounded">
                  <p className="font-bold">
                    {matchWinnerId ? `Game Over! ${gamePlayers.find((p) => p.id === matchWinnerId)?.name} wins the match!` : "Game Over! No winner."}
                  </p>
                </div>
              )}
              {!gameOver && isHost && (
                <button onClick={nextRound} className="mt-3 bg-blue-500 text-white px-4 py-2 rounded font-semibold hover:bg-blue-600">Next Round</button>
              )}
              {!gameOver && !isHost && <p className="text-gray-500 text-sm mt-3">Waiting for host to start next round...</p>}
            </div>
          )}
        </div>

        <div className="bg-white p-4 rounded-xl shadow">
          <h3 className="font-bold mb-2">Scoreboard</h3>
          <table className="w-full text-sm">
            <thead><tr className="border-b"><th className="text-left py-1">Name</th><th className="text-center py-1">Choice</th><th className="text-center py-1">Score</th></tr></thead>
            <tbody>
              {gamePlayers.map((p) => (
                <tr key={p.id} className={p.isEliminated ? "bg-red-100 text-red-600" : ""}>
                  <td className="py-1">{p.name} {p.id === playerId && "(you)"}</td>
                  <td className="text-center py-1">{p.choice != null ? Math.round(p.choice) : "-"}</td>
                  <td className="text-center py-1">{p.isEliminated ? "ELIM" : p.score}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}