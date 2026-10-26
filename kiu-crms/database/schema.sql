-- KIU Complaints and Request Management System
-- Database schema for MySQL / MariaDB.
-- Create a database called kiu_crms first, then import this file (phpMyAdmin > Import),
-- or just open install.php in the browser and it does everything for you.

CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL UNIQUE,
  department  VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The escalation ladder: who is level 1, 2, 3, 4 and how long each level has to act.
CREATE TABLE IF NOT EXISTS escalation_levels (
  level                  TINYINT UNSIGNED PRIMARY KEY,
  title                  VARCHAR(120) NOT NULL,
  priority               ENUM('Normal','High','Urgent','Critical') NOT NULL,
  response_hours         DECIMAL(7,2) NOT NULL DEFAULT 24,   -- time allowed to first respond
  urgent_response_hours  DECIMAL(7,2) NOT NULL DEFAULT 4,    -- same, for urgent cases
  resolution_hours       DECIMAL(7,2) NOT NULL DEFAULT 72    -- time allowed to resolve after responding
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(150) NOT NULL UNIQUE,
  reg_no         VARCHAR(40)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('student','officer','admin') NOT NULL DEFAULT 'student',
  officer_level  TINYINT UNSIGNED NULL,      -- 1 to 4 for officers
  category_id    INT UNSIGNED NULL,          -- NULL = handles every category (for example the Registrar)
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL,
  CONSTRAINT fk_users_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cases (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference          VARCHAR(30) NULL UNIQUE,
  tracking_code      VARCHAR(12) NOT NULL,
  type               ENUM('complaint','request') NOT NULL DEFAULT 'complaint',
  title              VARCHAR(160) NOT NULL,
  description        TEXT NOT NULL,
  category_id        INT UNSIGNED NOT NULL,
  student_id         INT UNSIGNED NULL,               -- NULL when the case is anonymous
  is_anonymous       TINYINT(1) NOT NULL DEFAULT 0,
  status             ENUM('Submitted','In Progress','Resolved','Closed','Reopened') NOT NULL DEFAULT 'Submitted',
  base_priority      ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal',   -- set when the case is submitted
  priority           ENUM('Normal','High','Urgent','Critical') NOT NULL DEFAULT 'Normal', -- rises with each escalation
  current_level      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  assigned_to        INT UNSIGNED NULL,
  stage              ENUM('response','resolution') NOT NULL DEFAULT 'response',
  due_at             DATETIME NULL,                    -- the deadline the clock is counting down to
  level_entered_at   DATETIME NOT NULL,
  first_response_at  DATETIME NULL,
  resolved_at        DATETIME NULL,
  closed_at          DATETIME NULL,
  escalation_count   INT UNSIGNED NOT NULL DEFAULT 0,
  alert_count        INT UNSIGNED NOT NULL DEFAULT 0,
  reopen_count       INT UNSIGNED NOT NULL DEFAULT 0,
  ai_confidence      DECIMAL(5,2) NULL,                -- how sure the AI was when it chose the category
  rating             TINYINT UNSIGNED NULL,            -- student satisfaction, 1 to 5
  rating_comment     VARCHAR(255) NULL,
  ip_hash            CHAR(64) NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  KEY idx_cases_student (student_id),
  KEY idx_cases_assigned (assigned_to),
  KEY idx_cases_due (status, due_at),
  CONSTRAINT fk_cases_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_cases_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cases_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The audit trail: every step a case goes through is written here.
CREATE TABLE IF NOT EXISTS case_events (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id      INT UNSIGNED NOT NULL,
  event_type   VARCHAR(30) NOT NULL,    -- SUBMITTED, ROUTED, ACKNOWLEDGED, RESPONDED, NOTE, ESCALATED, CRITICAL_ALERT, ...
  actor_id     INT UNSIGNED NULL,
  actor_label  VARCHAR(80) NOT NULL,
  from_level   TINYINT UNSIGNED NULL,
  to_level     TINYINT UNSIGNED NULL,
  from_user    INT UNSIGNED NULL,
  to_user      INT UNSIGNED NULL,
  message      TEXT NULL,
  is_internal  TINYINT(1) NOT NULL DEFAULT 0,   -- internal notes are hidden from the student
  created_at   DATETIME NOT NULL,
  KEY idx_events_case (case_id, created_at),
  KEY idx_events_type (event_type, created_at),
  CONSTRAINT fk_events_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attachments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id        INT UNSIGNED NOT NULL,
  original_name  VARCHAR(200) NOT NULL,
  stored_name    VARCHAR(80)  NOT NULL,
  mime_type      VARCHAR(80)  NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  uploaded_at    DATETIME NOT NULL,
  CONSTRAINT fk_att_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  case_id     INT UNSIGNED NULL,
  message     VARCHAR(255) NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_outbox (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email    VARCHAR(150) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  body        TEXT NOT NULL,
  status      ENUM('queued','logged','sent','failed') NOT NULL DEFAULT 'logged',
  error       VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example complaints the AI learns from. Officers' category corrections are added here too.
CREATE TABLE IF NOT EXISTS training_samples (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id  INT UNSIGNED NOT NULL,
  text         VARCHAR(500) NOT NULL,
  source       ENUM('seed','officer','admin') NOT NULL DEFAULT 'seed',
  created_at   DATETIME NOT NULL,
  KEY idx_train_cat (category_id),
  CONSTRAINT fk_train_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL,
  ip_hash       CHAR(64) NOT NULL,
  attempted_at  DATETIME NOT NULL,
  KEY idx_login (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_state (
  state_key    VARCHAR(60)  PRIMARY KEY,
  state_value  VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
