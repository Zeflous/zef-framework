# Blog plugin

`blog` is an explicitly registered ZEF plugin providing a versioned REST blog API. Its service IDs are `blog.service` and `blog.handler`; its routes begin at `/blog/v1`.

## Install and run

The repository bootstrap already registers the plugin. Run migrations through the host database migration runner, configure `BLOG_CACHE_TTL=60` and `BLOG_COMMENT_RATE_LIMIT=10` as appropriate, then start `php -S 0.0.0.0:8080 public/index.php`. Send identity server-side as the trusted request attribute `blog.actor` (`id`, `role`); production authentication middleware must create it, never accept it from a client header/body.

## API

CRUD resources are `posts`, `categories`, `tags`, `authors`, `comments`, and `media`. Lists accept `page`, `limit` (maximum 100), `sort` (`createdAt`, `updatedAt`, `title`, `slug`, with optional `-`), `q`, `status`, `locale`, `authorId`, `categoryId`, and `tag`. Post actions are `/posts/{id}/transition`, `/restore`, and `/revisions`; comment moderation is `/comments/{id}/moderate`. See `openapi/openapi.yaml`.

Read lists are cacheable for 60 seconds and invalidated on every mutation. Mutations publish `blog.created`, `blog.updated`, `blog.deleted`, and `blog.published` through the `blog.events` service; subscribe an outbox-backed webhook delivery adapter for remote consumers. The in-repo adapter is in-memory for safe local use; replace it with a transactional parameterized-SQL repository before production deployment.
