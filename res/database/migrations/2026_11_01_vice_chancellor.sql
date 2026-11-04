ALTER TABLE users
  MODIFY role ENUM('student','officer','admin','operational_admin','technical_admin','user_admin','vice_chancellor')
  NOT NULL DEFAULT 'student';

CREATE TABLE IF NOT EXISTS staff_messages (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT UNSIGNED NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  message      TEXT NOT NULL,
  created_at   DATETIME NOT NULL,
  read_at      DATETIME NULL,
  KEY idx_staff_messages_sender (sender_id, recipient_id, id),
  KEY idx_staff_messages_recipient (recipient_id, read_at, id),
  CONSTRAINT fk_staff_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE users
SET role = 'vice_chancellor'
WHERE email = 'vc@kiu.test';
