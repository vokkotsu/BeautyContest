# Beauty Contest (Strategic Mode)

A desktop implementation of the Keynesian **Beauty Contest** game, built with Python and Tkinter. Players choose a number between 0 and 100; the winner is the one closest to **2/3 of the average** of all chosen numbers. The last player standing (others eliminated) wins the match.

## Features

- Single-player mode (vs. bots) and local multiplayer (hot-seat)
- 2–10 players
- Strategic rules that activate based on active player count:
  - **Duplication penalty** (≤4 players): duplicate numbers are invalidated
  - **Critical hit** (≤3 players): exact target match doubles the penalty
  - **Duel** (2 players): 0 vs 100 — 100 wins
- Elimination system: players lose points each round and are eliminated at -10
- Animated round results with game log and scoreboard

## Requirements

- Python 3.12+
- Tkinter (bundled with standard Python on most platforms)

## Running

```bash
python main.py
```

## Project Structure

```
beautycontest/
├── main.py            # Entry point
├── ui_components.py   # Tkinter UI, game flow, and animations
├── game_logic.py      # Core game rules and player management
├── config.py          # Game constants and display settings
└── pyproject.toml     # Project metadata
```