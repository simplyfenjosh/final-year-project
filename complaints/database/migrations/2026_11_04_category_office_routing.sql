ALTER TABLE categories
  ADD COLUMN office_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_categories_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL;

UPDATE categories
SET is_active = 0
WHERE LOWER(name) IN (
  'safety',
  'safety and security',
  'safety, security and welfare',
  'general and other',
  'other'
);
