export const STARTING_SCORE = 0;
export const LOSS_PENALTY = 1;
export const CRITICAL_PENALTY = 2;
export const ELIMINATION_THRESHOLD = -10;

export class Player {
  constructor(id, name, isBot = false) {
    this.id = id;
    this.name = name;
    this.score = STARTING_SCORE;
    this.isEliminated = false;
    this.lastChoice = null;
    this.isBot = isBot;
  }
}

export class BeautyContestLogic {
  constructor(numPlayers, playerNames = []) {
    this.numPlayers = numPlayers;
    this.players = [];
    for (let i = 0; i < numPlayers; i++) {
      const id = i + 1;
      const name = (playerNames[i] && playerNames[i].trim()) ? playerNames[i] : `Player ${id}`;
      this.players.push(new Player(id, name, false));
    }
    this.currentPlayerIdx = 0;
  }

  activePlayers() {
    return this.players.filter((p) => !p.isEliminated);
  }

  getActiveCount() {
    return this.activePlayers().length;
  }

  isEliminated(id) {
    const p = this.players.find((x) => x.id === id);
    return p ? p.isEliminated : false;
  }

  calculateRoundResults() {
    const active = this.activePlayers();
    const count = active.length;
    if (count === 0) return null;

    const choices = active.map((p) => p.lastChoice);
    const rawAvg = choices.reduce((a, b) => a + b, 0) / count;
    const avg = Math.round(rawAvg * 100) / 100;
    const target = Math.round(avg * 0.8 * 100) / 100;

    const winners = [];
    const losers = [];
    const invalidPlayers = [];
    const specialEventLog = [];
    let currentPenalty = LOSS_PENALTY;

    // 2 players: 0 vs 100
    let rule2p = false;
    if (count === 2) {
      const vals = active.map((p) => p.lastChoice);
      if (vals.includes(0) && vals.includes(100)) {
        rule2p = true;
        specialEventLog.push("DUEL: 0 vs 100! 100 Menang!");
        for (const p of active) {
          if (p.lastChoice === 100) winners.push(p);
          else losers.push(p);
        }
      }
    }

    if (!rule2p) {
      if (count <= 4) {
        const seen = new Set();
        const duplicates = new Set();
        for (const p of active) {
          if (seen.has(p.lastChoice)) duplicates.add(p.lastChoice);
          seen.add(p.lastChoice);
        }
        if (duplicates.size > 0) {
          const dupStr = [...duplicates].map((x) => Math.round(x)).join(", ");
          specialEventLog.push(`DUPLIKASI: Angka ${dupStr} hangus!`);
          for (const p of active) {
            if (duplicates.has(p.lastChoice)) invalidPlayers.push(p);
          }
        }
      }

      const validCandidates = active.filter((p) => !invalidPlayers.includes(p));
      if (validCandidates.length === 0) {
        specialEventLog.push("SEMUA INVALID! Tidak ada pemenang.");
        losers.push(...active);
      } else {
        let minDiff = Infinity;
        for (const p of validCandidates) {
          const diff = Math.abs(target - p.lastChoice);
          if (diff < minDiff) minDiff = diff;
        }
        const roundWinners = validCandidates.filter(
          (p) => Math.abs(Math.abs(target - p.lastChoice) - minDiff) < 0.000001
        );
        winners.push(...roundWinners);
        for (const p of active) {
          if (!winners.includes(p)) losers.push(p);
        }

        if (count <= 3) {
          const roundedTarget = Math.round(target);
          const isExact = winners.some((p) => p.lastChoice === roundedTarget);
          if (isExact) {
            currentPenalty = CRITICAL_PENALTY;
            specialEventLog.push(`CRITICAL HIT! Tepat sasaran ${roundedTarget}!`);
            specialEventLog.push("Penalti yang kalah menjadi -2!");
          }
        }
      }
    }

    for (const p of losers) {
      p.score -= currentPenalty;
      if (p.score <= ELIMINATION_THRESHOLD) p.isEliminated = true;
    }

    return {
      avg,
      target,
      winners,
      losers,
      allPlayers: this.players,
      logs: specialEventLog,
      activeCount: count,
    };
  }

  getMatchWinner() {
    const active = this.activePlayers();
    if (active.length === 1) return active[0];
    if (active.length === 0) return "No One";
    return null;
  }
}