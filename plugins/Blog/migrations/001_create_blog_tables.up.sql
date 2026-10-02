-- PostgreSQL; safe to run repeatedly. Use parameterized framework database APIs for all data access.
CREATE TABLE IF NOT EXISTS blog_posts (id VARCHAR(24) PRIMARY KEY, author_id VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, locale VARCHAR(16) NOT NULL DEFAULT 'en', status VARCHAR(16) NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '', scheduled_at TIMESTAMPTZ NULL, published_at TIMESTAMPTZ NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMPTZ NULL, UNIQUE (slug, locale));
CREATE TABLE IF NOT EXISTS blog_categories (id VARCHAR(24) PRIMARY KEY, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, locale VARCHAR(16) NOT NULL DEFAULT 'en');
CREATE TABLE IF NOT EXISTS blog_tags (id VARCHAR(24) PRIMARY KEY, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, locale VARCHAR(16) NOT NULL DEFAULT 'en');
CREATE TABLE IF NOT EXISTS blog_authors (id VARCHAR(255) PRIMARY KEY, display_name VARCHAR(255) NOT NULL, bio TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS blog_comments (id VARCHAR(24) PRIMARY KEY, post_id VARCHAR(24) NOT NULL REFERENCES blog_posts(id) ON DELETE CASCADE, author_id VARCHAR(255) NOT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS blog_media (id VARCHAR(24) PRIMARY KEY, url TEXT NOT NULL, alt TEXT NOT NULL DEFAULT '', locale VARCHAR(16) NOT NULL DEFAULT 'en');
CREATE TABLE IF NOT EXISTS blog_post_revisions (post_id VARCHAR(24) NOT NULL REFERENCES blog_posts(id) ON DELETE CASCADE, version INTEGER NOT NULL, actor_id VARCHAR(255) NOT NULL, reason VARCHAR(32) NOT NULL, snapshot JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id, version));
CREATE TABLE IF NOT EXISTS blog_audit_log (id BIGSERIAL PRIMARY KEY, actor_id VARCHAR(255) NOT NULL, action VARCHAR(32) NOT NULL, resource VARCHAR(32) NOT NULL, resource_id VARCHAR(255) NOT NULL, before_state JSONB NULL, after_state JSONB NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS blog_posts_list_idx ON blog_posts (status, locale, published_at DESC);
CREATE INDEX IF NOT EXISTS blog_posts_author_idx ON blog_posts (author_id, updated_at DESC);
CREATE INDEX IF NOT EXISTS blog_comments_moderation_idx ON blog_comments (status, created_at DESC);
CREATE INDEX IF NOT EXISTS blog_posts_search_idx ON blog_posts USING GIN (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(body, '')));
