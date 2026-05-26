ALTER TABLE documents ADD COLUMN slug TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);
