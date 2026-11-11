import { useState, useRef, useCallback, useEffect } from "react";
import { BeautyContestLogic } from "./gameLogic";

const WS_URL =
  import.meta.env.VITE_WS_URL ||
  (window.location.hostname === "localhost" || window.location.hostname === "127.0.0.1"
    ? "ws://localhost:8000/ws"
    : "");

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
  const [history, setHistory] = useState([]);
  const [gameOver, setGameOver] = useState(false);
  const [matchWinnerId, setMatchWinnerId] = useState(null);
  const [value, setValue] = useState("");
  const [error, setError] = useState("");
  const [submittedSelf, setSubmittedSelf] = useState(false);
  const [readyState, setReadyState] = useState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 });
  const [countdownLeft, setCountdownLeft] = useState(null);
  const [readySent, setReadySent] = useState(false);
  const [session, setSession] = useState(() => {
    try { return JSON.parse(localStorage.getItem("bc-session") || "null"); } catch { return null; }
  });
  const [dark, setDark] = useState(() => {
    try { return localStorage.getItem("bc-dark") === "1"; } catch { return false; }
  });
  const [mode, setMode] = useState(null); // null | 'single' | 'multi'
  const [botCount, setBotCount] = useState(3);
  const [isSingle, setIsSingle] = useState(false);
  const [showHowToPlay, setShowHowToPlay] = useState(false);
  const logicRef = useRef(null);
  const idMapRef = useRef([]);
  useEffect(() => {
    document.documentElement.classList.toggle("dark", dark);
    try { localStorage.setItem("bc-dark", dark ? "1" : "0"); } catch {}
  }, [dark]);

  useEffect(() => {
    try {
      if (session) localStorage.setItem("bc-session", JSON.stringify(session));
      else localStorage.removeItem("bc-session");
    } catch {}
  }, [session]);

  const connect = useCallback((onOpen) => {
    if (!WS_URL) {
      setError("Backend WebSocket URL is not configured. Set VITE_WS_URL for deployed builds.");
      return null;
    }
    const ws = new WebSocket(WS_URL);
    wsRef.current = ws;
    ws.onopen = () => { if (onOpen) onOpen(ws); };
    ws.onerror = () => { setError("Could not connect to the game server. Check that the backend is running and VITE_WS_URL is correct."); };
    ws.onmessage = (ev) => {
      let msg; try { msg = JSON.parse(ev.data); } catch { return; }
      const applyReady = (payload) => {
        const countdown = payload?.countdown ?? 15;
        setReadyState({
          ready: payload?.ready || [],
          readyCount: payload?.readyCount ?? (payload?.ready ? payload.ready.length : 0),
          requiredCount: payload?.requiredCount ?? 0,
          countdown,
        });
        setCountdownLeft(payload?.gameOver ? null : countdown);
        setReadySent(false);
      };

      switch (msg.type) {
        case "joined":
          setPlayerId(msg.playerId); setRoomCode(msg.code); setIsHost(msg.isHost); setPlayers(msg.players || []); setScreen("lobby"); setHistory([]); setResult(null); setSubmitted([]); setSubmittedSelf(false);
          setReadyState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 }); setCountdownLeft(null); setReadySent(false);
          setSession({ code: msg.code, playerId: msg.playerId, name: msg.name || name });
          break;
        case "lobby_update":
          setPlayers(msg.players || []); setHostId(msg.hostId);
          if (msg.hostId && (session?.playerId || playerId)) setIsHost(msg.hostId === (session?.playerId || playerId));
          break;
        case "game_started":
          setScreen("game"); setRound(msg.round || 1); setSubmitted([]); setSubmittedSelf(false); setResult(null); setGameOver(false); setMatchWinnerId(null); setValue(""); setError(""); setHistory(msg.history || []);
          setReadyState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 }); setCountdownLeft(null); setReadySent(false);
          break;
        case "round_start":
          setSubmitted(msg.submitted || []); setSubmittedSelf(false); setResult(null); setGameOver(false); setMatchWinnerId(null); setValue(""); setError(""); if (msg.round) setRound(msg.round); if (msg.history) setHistory(msg.history);
          setReadyState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 }); setCountdownLeft(null); setReadySent(false);
          break;
        case "submission_update":
          setSubmitted(msg.submitted || []); break;
        case "round_result":
          setResult(msg); setPlayers(msg.players || []); setSubmitted(msg.submitted || []); setGameOver(!!msg.gameOver); setMatchWinnerId(msg.matchWinnerId); if (msg.history) setHistory(msg.history);
          if (!msg.gameOver) applyReady(msg); else { setCountdownLeft(null); setReadySent(false); }
          break;
        case "ready_update":
          if (!gameOver) {
            setReadyState((prev) => ({
              ...prev,
              ready: msg.ready || [],
              readyCount: msg.readyCount ?? (msg.ready ? msg.ready.length : 0),
              requiredCount: msg.requiredCount ?? prev.requiredCount,
              countdown: msg.countdown ?? prev.countdown ?? 15,
            }));
          }
          break;
        case "state_sync": {
          const pid = msg.playerId;
          setPlayerId(pid); setRoomCode(msg.code); setIsHost(!!msg.isHost); setHostId(msg.hostId || null);
          setPlayers(msg.players || []); setRound(msg.round || 1); setHistory(msg.history || []);
          setSubmitted(msg.submitted || []); setSession({ code: msg.code, playerId: pid, name: session?.name || name });
          if (msg.state === "lobby") {
            setScreen("lobby"); setResult(null); setGameOver(false); setMatchWinnerId(null); setSubmittedSelf(false);
            setReadyState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 }); setCountdownLeft(null); setReadySent(false);
          } else {
            setScreen("game");
            if (msg.state === "result" && msg.result) {
              setResult(msg.result); setGameOver(!!msg.result.gameOver); setMatchWinnerId(msg.result.matchWinnerId);
              setSubmittedSelf(false);
              if (!msg.result.gameOver) applyReady(msg.result); else { setCountdownLeft(null); setReadySent(false); }
            } else {
              setResult(null); setGameOver(false); setMatchWinnerId(null);
              setReadyState({ ready: [], readyCount: 0, requiredCount: 0, countdown: 15 }); setCountdownLeft(null); setReadySent(false);
              const meP = (msg.players || []).find((p) => p.id === pid);
              setSubmittedSelf(!!(meP && !meP.isEliminated && (msg.submitted || []).includes(pid)));
            }
          }
          break;
        }
        case "session_invalid":
          setSession(null); setError(msg.message || "Session expired. Please join again."); setScreen("landing");
          break;
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
    if (isSingle) {
      const bots = Array.from({ length: botCount }, (_, i) => ({ id: `bot-${i + 1}`, name: `Bot ${i + 1}`, isBot: true }));
      const human = { id: "me", name: name.trim(), isBot: false, isHost: true };
      setIsSingle(true);
      setPlayers([human, ...bots]);
      setPlayerId("me");
      setIsHost(true);
      setRoomCode("LOCAL");
      setScreen("lobby");
      return;
    }
    const ws = connect((ws) => ws.send(JSON.stringify({
      type: "create_room",
      name: name.trim(),
      mode: "multi",
      bots: 0,
    })));
    if (!ws) return;
  };
  const joinRoom = () => {
    if (!name.trim()) { setError("Enter your name"); return; }
    if (!joinCode.trim()) { setError("Enter room code"); return; }
    setError("");
    const ws = connect((ws) => ws.send(JSON.stringify({ type: "join_room", code: joinCode.trim(), name: name.trim() })));
    if (!ws) return;
  };
  const startGame = () => {
    if (isSingle) {
      const names = players.map((p) => p.name);
      const ids = players.map((p) => p.id);
      const logic = new BeautyContestLogic(names.length, names);
      logicRef.current = logic;
      idMapRef.current = ids;
      // auto-submit bots
      const submittedIds = [];
      for (let i = 1; i < ids.length; i++) {
        if (!logic.players[i].isEliminated) {
          const choice = Math.floor(Math.random() * 101);
          logic.players[i].lastChoice = choice;
          submittedIds.push(ids[i]);
        }
      }
      const gamePlayers = logic.players.map((p, i) => ({
        id: ids[i],
        name: p.name,
        score: p.score,
        isEliminated: p.isEliminated,
        choice: p.lastChoice,
        isBot: i > 0,
      }));
      setPlayers(gamePlayers);
      setSubmitted(submittedIds);
      setSubmittedSelf(false);
      setResult(null);
      setGameOver(false);
      setMatchWinnerId(null);
      setHistory([]);
      setRound(1);
      setScreen("game");
      return;
    }
    wsRef.current?.send(JSON.stringify({ type: "start_game" }));
  };
  const submit = (picked) => {
    const raw = picked !== undefined ? picked : value;
    const v = parseFloat(raw);
    if (isNaN(v)) { setError("Select a number 0-100"); return; }
    if (v < 0 || v > 100) { setError("Number must be 0-100"); return; }
    setError("");
    setValue(String(v));
    if (isSingle) {
      if (submittedSelf) return;
      const logic = logicRef.current;
      const ids = idMapRef.current;
      logic.players[0].lastChoice = v;
      const newSubmitted = [...submitted, "me"];
      setSubmitted(newSubmitted);
      setSubmittedSelf(true);
      // check all active submitted
      const activeIds = ids.filter((id, i) => !logic.players[i].isEliminated);
      if (activeIds.every((id) => newSubmitted.includes(id))) {
        const res = logic.calculateRoundResults();
        const updated = logic.players.map((p, i) => ({
          id: ids[i],
          name: p.name,
          score: p.score,
          isEliminated: p.isEliminated,
          choice: p.lastChoice,
          isBot: i > 0,
        }));
        setPlayers(updated);
        const mw = logic.getMatchWinner();
        const matchWinnerId = mw && mw !== "No One" ? ids[mw.id - 1] : null;
        const gameOver = matchWinnerId !== null || mw === "No One";
        const winnerIds = (res.winners || []).map((p) => ids[p.id - 1]);
        const loserIds = (res.losers || []).map((p) => ids[p.id - 1]);
        const historyEntry = {
          round,
          avg: res.avg,
          target: res.target,
          logs: res.logs,
          picks: updated
            .filter((p) => p.choice != null)
            .map((p) => ({ id: p.id, name: p.name, choice: p.choice, isEliminated: p.isEliminated })),
          winnerIds,
          loserIds,
        };
        const nextHistory = [...history, historyEntry];
        setHistory(nextHistory);
        setResult({
          type: "round_result",
          round,
          avg: res.avg,
          target: res.target,
          logs: res.logs,
          activeCount: res.activeCount,
          players: updated,
          winnerIds,
          loserIds,
          matchWinnerId,
          gameOver,
          history: nextHistory,
        });
        setGameOver(gameOver);
        setMatchWinnerId(matchWinnerId);
      }
      return;
    }
    wsRef.current?.send(JSON.stringify({ type: "submit", value: v }));
    setSubmittedSelf(true);
  };
  const nextRound = () => {
    if (isSingle) {
      const logic = logicRef.current;
      const ids = idMapRef.current;
      // reset round (no-op for logic, just clear submissions)
      const submittedIds = [];
      for (let i = 1; i < ids.length; i++) {
        if (!logic.players[i].isEliminated) {
          const choice = Math.floor(Math.random() * 101);
          logic.players[i].lastChoice = choice;
          submittedIds.push(ids[i]);
        }
      }
      setSubmitted(submittedIds);
      setSubmittedSelf(false);
      setResult(null);
      setRound((r) => r + 1);
      return;
    }
    if (readySent) return;
    setReadySent(true);
    wsRef.current?.send(JSON.stringify({ type: "ready_next" }));
  };

  useEffect(() => {
    if (!session || isSingle) return;
    if (screen !== "landing") return;
    // attempt auto-rejoin once on load when we have a saved multiplayer session
    const ws = connect((sock) => {
      sock.send(JSON.stringify({ type: "rejoin_room", code: session.code, playerId: session.playerId }));
    });
    if (!ws) return;
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!result || gameOver || countdownLeft == null) return;
    if (countdownLeft <= 0) return;
    const t = setTimeout(() => setCountdownLeft((s) => (s == null ? s : Math.max(0, s - 1))), 1000);
    return () => clearTimeout(t);
  }, [result, gameOver, countdownLeft]);

  const submittedSet = new Set(submitted);
  const readySet = new Set(readyState.ready || []);

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
            <button
              type="button"
              onClick={() => setShowHowToPlay((v) => !v)}
              aria-expanded={showHowToPlay}
              aria-controls="how-to-play"
              className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-[#663298] hover:text-[#552a80] dark:text-violet-300 dark:hover:text-violet-200 focus:outline-none focus:underline"
            >
              How to Play
              <span aria-hidden="true" className={`transition-transform ${showHowToPlay ? "rotate-180" : ""}`}>▾</span>
            </button>
            {showHowToPlay && (
              <div
                id="how-to-play"
                className="mt-3 text-left text-sm rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2 animate-fade-in dark:border-[#3a2a55] dark:bg-[#1f1533]"
              >
                <p><span className="font-semibold">Goal:</span> Pick a number from 0–100. The target is the average of all picks × 0.8 — get closest to the target to win the round.</p>
                <ul className="list-disc pl-5 space-y-1">
                  <li>Everyone starts at 0 points. Round losers lose 1 point; reach -10 and you're eliminated.</li>
                  <li>Last player standing wins the match.</li>
                  <li><span className="font-medium">4 or fewer players:</span> duplicate picks are invalid for that round.</li>
                  <li><span className="font-medium">3 or fewer players:</span> an exact hit on the rounded target is a Critical Hit (losers lose 2 points).</li>
                  <li><span className="font-medium">2 players:</span> if picks are 0 and 100, the player who picked 100 wins the round.</li>
                </ul>
              </div>
            )}
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
              <button
                onClick={() => { try { navigator.clipboard.writeText(roomCode); } catch {} }}
                className="ml-2 text-xs text-[#663298] hover:underline focus:outline-none"
                aria-label="Copy room code"
              >
                Copy
              </button>
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

  const gamePlayers = result ? result.players : players.map((p) => ({ id: p.id, name: p.name, choice: null, score: p.score ?? 0, isEliminated: !!p.isEliminated }));
  const winnerNames = result ? result.players.filter((p) => result.winnerIds?.includes(p.id)).map((p) => p.name) : [];
  const activePlayers = gamePlayers.filter((p) => !p.isEliminated);
  const activeCount = activePlayers.length;
  const me = gamePlayers.find((p) => p.id === playerId);
  const iAmEliminated = !!me?.isEliminated;
  const effectiveSubmittedSelf = submittedSelf || (!!me && !me.isEliminated && !result && submittedSet.has(playerId));
  const activeRules = [];
  if (activeCount === 2) {
    activeRules.push("2 players: if picks are 0 and 100, the player who picked 100 wins the round.");
  }
  if (activeCount > 0 && activeCount <= 4) {
    activeRules.push("4 or fewer players: duplicate picks are invalid for that round.");
  }
  if (activeCount > 0 && activeCount <= 3) {
    activeRules.push("3 or fewer players: exact match on the rounded target is a Critical Hit (losers lose 2 points).");
  }

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

          <div className="mb-4 p-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-700/40 dark:bg-amber-900/20 dark:text-amber-100">
            <p className="text-sm font-semibold mb-1">Active rules ({activeCount} active player{activeCount === 1 ? "" : "s"})</p>
            <ul className="text-sm space-y-1 list-disc pl-5">
              <li>Target = average × 0.8. Closest valid pick wins; round losers lose 1 point (eliminated at -10).</li>
              {activeRules.map((rule, i) => (
                <li key={i} className="font-medium">{rule}</li>
              ))}
            </ul>
          </div>

          {!effectiveSubmittedSelf && !result && !iAmEliminated && (
            <div className="mb-5">
              <p className="block text-sm font-medium mb-2">Pick your number (0–100)</p>
              <div className="grid grid-cols-10 sm:grid-cols-[repeat(20,minmax(0,1fr))] gap-1.5 max-h-80 overflow-y-auto p-1 rounded-xl border border-gray-100 bg-gray-50 dark:bg-[#1f1533] dark:border-[#3a2a55]">
                {Array.from({ length: 101 }, (_, n) => (
                  <button
                    key={n}
                    type="button"
                    onClick={() => submit(n)}
                    className={`py-1.5 text-xs sm:text-sm rounded-md border transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500/40 ${
                      String(value) === String(n)
                        ? "bg-emerald-600 text-white border-emerald-600"
                        : "bg-white text-slate-800 border-gray-200 hover:bg-emerald-50 hover:border-emerald-400 dark:bg-[#251a3a] dark:text-slate-100 dark:border-[#3a2a55] dark:hover:bg-emerald-900/30"
                    }`}
                  >
                    {n}
                  </button>
                ))}
              </div>
              {error && <div role="alert" className="text-sm text-red-600 mt-2">{error}</div>}
            </div>
          )}

          {!result && iAmEliminated && (
            <div className="mb-4 p-3 rounded-xl border border-red-200 bg-red-50 text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-200">
              You are eliminated. Waiting for the remaining players to finish this session…
            </div>
          )}

          {effectiveSubmittedSelf && !result && !iAmEliminated && (
            <div className="mb-4 flex items-center gap-2 text-emerald-700 font-medium animate-pop-in">
              <span className="inline-block h-2 w-2 rounded-full bg-emerald-500" />
              Submitted! Waiting for others…
            </div>
          )}

          <div className="mb-4">
            <h3 className="text-sm font-semibold text-gray-600 dark:text-[#a89fc0] mb-2">Submitted ({submitted.filter((id) => activePlayers.some((p) => p.id === id)).length}/{activeCount})</h3>
            <div className="flex flex-wrap gap-2">
              {gamePlayers.map((p) => (
                <span
                  key={p.id}
                  className={`px-3 py-1 rounded-full text-xs font-medium transition-colors duration-200 ${
                    p.isEliminated
                      ? "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
                      : submittedSet.has(p.id)
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-gray-100 text-gray-500 dark:text-[#a89fc0]"
                  }`}
                >
                  {p.name} {p.isEliminated ? "(out)" : submittedSet.has(p.id) ? "✓" : "…"}
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
              {!gameOver && (
                <div className="mt-4 p-3 rounded-xl border border-gray-200 bg-gray-50 dark:border-[#3a2a55] dark:bg-[#1f1533]">
                  {isSingle ? (
                    <button onClick={nextRound} className="bg-[#663298] text-white px-5 py-2 rounded-xl font-semibold hover:bg-[#552a80] focus:outline-none focus:ring-2 focus:ring-[#663298]/40">
                      Next Round
                    </button>
                  ) : (
                    <>
                      <div className="flex items-center justify-between gap-3 mb-2">
                        <p className="text-sm font-semibold">
                          Ready for next round: {readyState.readyCount}/{readyState.requiredCount || 0}
                        </p>
                        <p className="text-sm font-mono text-gray-600 dark:text-[#a89fc0]">
                          Auto-start in {countdownLeft ?? readyState.countdown ?? 15}s
                        </p>
                      </div>
                      <div className="flex flex-wrap gap-2 mb-3">
                        {gamePlayers.filter((p) => !p.isEliminated && !p.isBot).map((p) => (
                          <span
                            key={p.id}
                            className={`px-2.5 py-1 rounded-full text-xs font-medium ${
                              readySet.has(p.id)
                                ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300"
                                : "bg-gray-200 text-gray-600 dark:bg-[#2b2140] dark:text-[#cfc6df]"
                            }`}
                          >
                            {p.name} {readySet.has(p.id) ? "✓ Ready" : "…"}
                          </span>
                        ))}
                      </div>
                      {!iAmEliminated ? (
                        <button
                          onClick={nextRound}
                          disabled={readySet.has(playerId) || readySent}
                          className="bg-[#663298] text-white px-5 py-2 rounded-xl font-semibold hover:bg-[#552a80] disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-[#663298]/40"
                        >
                          {readySet.has(playerId) || readySent ? "You're ready" : "I'm Ready"}
                        </button>
                      ) : (
                        <p className="text-sm text-gray-500 dark:text-[#a89fc0]">You are eliminated. Waiting for remaining players…</p>
                      )}
                    </>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        <div className="space-y-5">
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
                {gamePlayers.map((p, i) => (
                  <tr
                    key={p.id}
                    className={`border-b border-gray-50 last:border-0 transition-colors animate-fade-in ${p.isEliminated ? "bg-red-50 text-red-600" : ""}`}
                    style={{ animationDelay: `${i * 50}ms` }}
                  >
                    <td className="py-2 font-medium">{p.name} {p.id === playerId && <span className="text-gray-400 dark:text-[#a89fc0]">(you)</span>}</td>
                    <td className="text-center py-2">{p.isEliminated ? "—" : (p.choice != null ? Math.round(p.choice) : "—")}</td>
                    <td className="text-center py-2 font-semibold">{p.isEliminated ? "ELIM" : p.score}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="card p-5 animate-fade-in" style={{ animationDelay: "90ms" }}>
            <h3 className="font-bold mb-3">Session History</h3>
            {history.length === 0 ? (
              <p className="text-sm text-gray-500 dark:text-[#a89fc0]">No completed rounds yet.</p>
            ) : (
              <div className="space-y-3 max-h-[28rem] overflow-y-auto pr-1">
                {[...history].reverse().map((h) => (
                  <div key={h.round} className="rounded-lg border border-gray-100 bg-gray-50 p-3 text-sm dark:border-[#3a2a55] dark:bg-[#1f1533]">
                    <div className="flex items-center justify-between mb-1.5">
                      <span className="font-semibold">Round {h.round}</span>
                      <span className="text-xs text-gray-500 dark:text-[#a89fc0]">Avg {Number(h.avg).toFixed(2)} · Target {Number(h.target).toFixed(2)}</span>
                    </div>
                    <ul className="space-y-0.5">
                      {(h.picks || []).map((p) => (
                        <li key={p.id} className="flex justify-between gap-2">
                          <span>{p.name} {p.isEliminated ? <span className="text-red-500">(out)</span> : null}</span>
                          <span className="font-mono">{p.choice != null ? Math.round(p.choice) : "—"}</span>
                        </li>
                      ))}
                    </ul>
                    {h.logs?.length > 0 && (
                      <ul className="mt-1.5 text-xs text-red-600 space-y-0.5">
                        {h.logs.map((l, i) => <li key={i}>&gt;&gt; {l}</li>)}
                      </ul>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}