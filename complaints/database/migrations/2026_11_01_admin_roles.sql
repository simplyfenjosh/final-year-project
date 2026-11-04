ALTER TABLE users
  MODIFY role ENUM('student','officer','admin','operational_admin','technical_admin','user_admin')
  NOT NULL DEFAULT 'student';

UPDATE users
SET role = CASE email
  WHEN 'operation@kiu.test' THEN 'operational_admin'
  WHEN 'technical@kiu.test' THEN 'technical_admin'
  WHEN 'user@kiu.test' THEN 'user_admin'
  ELSE role
END
WHERE email IN ('operation@kiu.test', 'technical@kiu.test', 'user@kiu.test');
