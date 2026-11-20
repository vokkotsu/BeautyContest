-- Beauty Contest multiplayer schema (MySQL, shared-hosting friendly HTTP polling)

CREATE TABLE IF NOT EXISTS rooms (
  code               CHAR(5)      NOT NULL,
  host_player_id     VARCHAR(64)  NOT NULL,
  state              ENUM('lobby','playing','result') NOT NULL DEFAULT 'lobby',
  round_number       INT          NOT NULL DEFAULT 0,
  pick_deadline_at   DATETIME(3)  NULL,
  ready_deadline_at  DATETIME(3)  NULL,
  single_player      TINYINT(1)   NOT NULL DEFAULT 0,
  bot_count          INT          NOT NULL DEFAULT 0,
  created_at         DATETIME(3)  NOT NULL,
  updated_at         DATETIME(3)  NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_participants (
  room_code      CHAR(5)      NOT NULL,
  participant_id VARCHAR(64)  NOT NULL,
  name           VARCHAR(100) NOT NULL,
  is_host        TINYINT(1)   NOT NULL DEFAULT 0,
  is_bot         TINYINT(1)   NOT NULL DEFAULT 0,
  game_player_id INT          NULL,
  score          INT          NOT NULL DEFAULT 0,
  is_eliminated  TINYINT(1)   NOT NULL DEFAULT 0,
  last_choice    DOUBLE       NULL,
  joined_at      DATETIME(3)  NOT NULL,
  PRIMARY KEY (room_code, participant_id),
  KEY idx_room_active (room_code, is_bot, is_eliminated),
  CONSTRAINT fk_part_room FOREIGN KEY (room_code) REFERENCES rooms(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submissions (
  room_code      CHAR(5)     NOT NULL,
  round_number   INT         NOT NULL,
  participant_id VARCHAR(64) NOT NULL,
  value          DOUBLE      NOT NULL,
  submitted_at   DATETIME(3) NOT NULL,
  PRIMARY KEY (room_code, round_number, participant_id),
  CONSTRAINT fk_sub_room FOREIGN KEY (room_code) REFERENCES rooms(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS round_readies (
  room_code      CHAR(5)     NOT NULL,
  round_number   INT         NOT NULL,
  participant_id VARCHAR(64) NOT NULL,
  PRIMARY KEY (room_code, round_number, participant_id),
  CONSTRAINT fk_ready_room FOREIGN KEY (room_code) REFERENCES rooms(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS round_history (
  room_code       CHAR(5)     NOT NULL,
  round_number    INT         NOT NULL,
  avg_value       DOUBLE      NOT NULL,
  target_value    DOUBLE      NOT NULL,
  active_count    INT         NOT NULL DEFAULT 0,
  logs_json       JSON        NOT NULL,
  picks_json      JSON        NOT NULL,
  winner_ids_json JSON        NOT NULL,
  loser_ids_json  JSON        NOT NULL,
  match_winner_id VARCHAR(64) NULL,
  game_over       TINYINT(1)  NOT NULL,
  created_at      DATETIME(3) NOT NULL,
  PRIMARY KEY (room_code, round_number),
  CONSTRAINT fk_hist_room FOREIGN KEY (room_code) REFERENCES rooms(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
