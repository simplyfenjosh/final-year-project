CREATE TABLE IF NOT EXISTS departments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offices (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id  INT UNSIGNED NOT NULL,
  name           VARCHAR(120) NOT NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_office_department_name (department_id, name),
  CONSTRAINT fk_offices_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
  ADD COLUMN department_id INT UNSIGNED NULL,
  ADD COLUMN office_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL;
