# ZEF Framework — production-leaning image (cli base, no web server
# needed: bin/zef --serve wraps PHP's built-in server for dev, RoadRunner
# worker is the production runtime).
#
# Hardening notes (see the PR description for the full rationale):
#   * the base image is pinned by DIGEST, not by the mutable `8.4-cli-alpine`
#     tag — a tag can be re-pointed at a different (or compromised) build
#     between two builds of this repository;
#   * the container runs as a NON-ROOT user;
#   * a HEALTHCHECK is declared so an orchestrator can tell a live container
#     from a merely running one;
#   * the self-test is BLOCKING. It previously ended in `|| true`, which
#     turned the only build-time correctness check into a no-op: a broken
#     image would build successfully and fail at runtime.
FROM php:8.4-cli-alpine@sha256:37c8c64177612b72e7f9d2c00481739c166a3708bea27c1cfd27fea1291d82d1

# No extensions are strictly required (openssl/mbstring ship with core
# builds); redis/apcu are optional and only loaded when the corresponding
# adapters are configured.
WORKDIR /app

# Unprivileged runtime account. UID/GID >= 10000 keeps container processes out
# of the host's low-UID range, matching the k8s securityContext in
# deploy/k8s/deployment.yaml.
RUN addgroup -g 10000 -S zef \
    && adduser -u 10000 -S -G zef -h /app zef

COPY . .

# Build-time correctness gate. `php -l` proves the entrypoint parses and the
# self-test proves the framework boots; both are blocking on purpose.
RUN chmod +x bin/zef \
    && php -l bin/zef \
    && php bin/zef --self-test > /dev/null \
    && chown -R zef:zef /app

USER zef

EXPOSE 8080

# Liveness probe against the framework's own health endpoint. Uses PHP (already
# present) instead of curl/wget so no extra package is added to the image.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD php -r "exit((int)(@file_get_contents('http://127.0.0.1:8080/health/live') === false));"

# Development server. For production swap the command to the RoadRunner
# worker: ["php", "bin/worker.php"] (requires spiral/roadrunner-http).
CMD ["php", "bin/zef", "--serve", "0.0.0.0:8080"]
