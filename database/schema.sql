SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schools (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(8)   NOT NULL,
  name       VARCHAR(120) NOT NULL,
  motto      VARCHAR(120) NOT NULL DEFAULT '',
  address    VARCHAR(200) NOT NULL DEFAULT '',
  contact    VARCHAR(120) NOT NULL DEFAULT '',
  color      CHAR(7)      NOT NULL DEFAULT '#14213d',
  font       VARCHAR(40)  NOT NULL DEFAULT 'Arial',
  card_w     SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  card_h     SMALLINT UNSIGNED NOT NULL DEFAULT 64,
  watermark  VARCHAR(40)  NOT NULL DEFAULT '',
  signer     VARCHAR(60)  NOT NULL DEFAULT 'Headteacher',
  logo       VARCHAR(200) NOT NULL DEFAULT 'assets/img/vcms-logo.png',
  qr_secret  CHAR(64)     NOT NULL,
  verify_url VARCHAR(200) NOT NULL DEFAULT '',
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  setup_done TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id     INT UNSIGNED NOT NULL,
  username      VARCHAR(30)  NOT NULL,
  full_name     VARCHAR(80)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('system_admin','school_admin','data_entry','printing') NOT NULL,
  perms         TEXT NULL,
  must_change   TINYINT(1) NOT NULL DEFAULT 0,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  pw_changed_at DATETIME NULL,
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username),
  CONSTRAINT fk_users_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guardians (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id    INT UNSIGNED NOT NULL,
  first_name   VARCHAR(40)  NOT NULL,
  last_name    VARCHAR(40)  NOT NULL,
  phone        VARCHAR(12)  NOT NULL,
  address      VARCHAR(150) NOT NULL DEFAULT '',
  relationship ENUM('Father','Mother','Guardian','Other') NOT NULL,
  KEY idx_guardian_phone (school_id, phone),
  CONSTRAINT fk_guardians_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id     INT UNSIGNED NOT NULL,
  admission_no  VARCHAR(20)  NOT NULL,
  first_name    VARCHAR(40)  NOT NULL,
  last_name     VARCHAR(40)  NOT NULL,
  class_name    ENUM('S1','S2','S3','S4','S5','S6') NOT NULL,
  stream        VARCHAR(15)  NOT NULL,
  origin        VARCHAR(60)  NOT NULL DEFAULT '',
  photo         VARCHAR(200) NULL,
  guardian_id   INT UNSIGNED NOT NULL,
  registered_on DATE NOT NULL,
  status        ENUM('active','graduated','left') NOT NULL DEFAULT 'active',
  status_on     DATE NULL,
  rev           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admission (school_id, admission_no),
  KEY idx_class_stream (school_id, class_name, stream),
  KEY idx_list (school_id, status, class_name, stream, last_name, first_name),
  KEY idx_name (school_id, last_name, first_name),
  CONSTRAINT fk_students_school   FOREIGN KEY (school_id)   REFERENCES schools(id),
  CONSTRAINT fk_students_guardian FOREIGN KEY (guardian_id) REFERENCES guardians(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitation_cards (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id       INT UNSIGNED NOT NULL,
  student_id      INT UNSIGNED NOT NULL,
  card_no         VARCHAR(30)  NOT NULL,
  status          ENUM('unprinted','printed') NOT NULL DEFAULT 'unprinted',
  print_count     INT UNSIGNED NOT NULL DEFAULT 0,
  last_printed_at DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_card_student (student_id),
  UNIQUE KEY uq_card_no (card_no),
  CONSTRAINT fk_cards_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  card_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     ENUM('print','pdf') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ph_card (card_id),
  CONSTRAINT fk_ph_card FOREIGN KEY (card_id) REFERENCES visitation_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(40)  NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  ip         VARCHAR(45)  NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_streams (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id   INT UNSIGNED NOT NULL,
  class_name  ENUM('S1','S2','S3','S4','S5','S6') NOT NULL,
  stream_name VARCHAR(15) NOT NULL,
  UNIQUE KEY uq_class_stream (school_id, class_name, stream_name),
  CONSTRAINT fk_cs_school FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  ip           VARCHAR(90) NOT NULL PRIMARY KEY,
  fails        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  updated_at   DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verify_fails (
  ip           VARCHAR(45) NOT NULL PRIMARY KEY,
  fails        INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  label      VARCHAR(120) NOT NULL DEFAULT '',
  moved      INT UNSIGNED NOT NULL DEFAULT 0,
  undone     TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_promo_school (school_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promotion_id INT UNSIGNED NOT NULL,
  student_id   INT UNSIGNED NOT NULL,
  from_class   VARCHAR(3)  NOT NULL,
  from_stream  VARCHAR(15) NOT NULL,
  to_class     VARCHAR(3)  NOT NULL,
  to_stream    VARCHAR(15) NOT NULL,
  to_status    VARCHAR(10) NOT NULL DEFAULT 'active',
  KEY idx_pi_promo (promotion_id),
  KEY idx_pi_student (student_id),
  CONSTRAINT fk_pi_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
