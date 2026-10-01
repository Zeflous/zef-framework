<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: durable JobQueueInterface adapter over Redis Streams.
 */

namespace Zef\Framework\Job;

use Zef\Framework\Validation\Identifier;

/**
 * Redis-Streams-backed {@see JobQueueInterface} — the shared-nothing durable
 * counterpart of {@see PdoJobQueue} for deployments that run the database
 * lean and keep job state (plus the failed-job stream) in Redis.
 *
 * Ordering parity with PdoJobQueue: (priority DESC, available_at ASC,
 * seq ASC) — the stream entry id ('<ms>-<seq>', monotonically increasing
 * server-side) plays the role of the PDO `seq` column, so two jobs that
 * share priority and availability still dequeue in enqueue order.
 *
 * Enqueue is one atomic Lua step: capacity guard (XLEN), duplicate-id
 * backstop (SADD against the live-id set, the moral equivalent of the PDO
 * UNIQUE(job_id) constraint) and XADD. Dequeue is one atomic Lua step:
 * XRANGE scan for the best claimable entry, XDEL + SREM, then the flat
 * field list travels back to PHP. No local clock participates in the
 * critical path — `now` is passed in as an argument (the port contract)
 * so tests stay deterministic.
 *
 * Numeric-width note (the Lua double): Lua numbers are doubles, exact only
 * up to 2^53 — one nanosecond timestamp (~1.7e18) already exceeds that, so
 * two distinct deadlines can collapse to the same double and silently swap
 * order. `available_at` is therefore stored and compared as a 20-character
 * order-preserving string: non-negative deadlines are zero-padded 20-digit
 * strings (lexicographic order equals numeric order, byte-identical to the
 * v2.32-v2.34 storage), negative deadlines are '-' plus the 9's complement
 * of the 19-digit zero-padded magnitude, which keeps lexicographic order
 * equal to numeric order across the entire signed 64-bit domain — the
 * parity contract pinned in docs/JOB-QUEUE-PARITY.md (issue #272, fixed
 * in v2.34.1: the pre-fix unpadded negatives inverted both ordering and
 * availability verdicts against InMemoryJobQueue/PdoJobQueue). Priority
 * is compared numerically (tonumber) — priorities live far inside the
 * exact double domain, and the PDO adapter caps them at a 32-bit INT
 * column anyway.
 *
 * Failed-job storage: a second instance constructed with the name 'failed'
 * (or any dedicated name) is a persistent dead-letter stream — wire it as
 * InProcessJobWorker's deadLetterQueue and inspect it with
 * `bin/zef queue:failed`.
 *
 * Complexity: dequeue scans the whole stream (XRANGE '-'+') to honour the
 * priority ordering — O(N) per claim, N = live entries. The PDO adapter is
 * O(log N) via its ordering index; this adapter trades that for
 * shared-nothing deployment. Keep live queues bounded (maxSize) when
 * priorities are in play.
 *
 * The two retry triggers of PdoJobQueue's claim loop do not exist here:
 * XDEL and XADD run inside one script, so there is no race window between
 * "select the winner" and "delete it" — a claim either wins outright or
 * sees nothing.
 *
 * Payloads travel as JSON documents via {@see JobRowCodec} (mixed
 * round-trip: scalars, lists, string-keyed maps) — object payloads must be
 * serialised by the caller, mirroring the outbox contract. A tampered
 * entry whose payload no longer parses is destroyed by the claim and the
 * hydrate failure surfaces loudly — deliberate liveness-over-preservation
 * divergence from PdoJobQueue, where the row survives the failed hydrate
 * inside the rolled-back transaction and re-poisons every subsequent
 * dequeue attempt (the queue wedges on the same corrupt row forever);
 * here the queue keeps flowing and exactly one claim reports the damage.
 */
final readonly class RedisStreamJobQueue implements JobQueueInterface
{
    /** Stream key prefix; the live-id set lives beside it with ':ids'. */
    private const string PREFIX = 'zef:jobq:';

    /**
     * Fixed XADD field order — the dequeue script and the PHP row mapper
     * index into this order positionally (Lua tables have no field names).
     *
     * job_id, job_type, payload, available_at, priority, attempt,
     * correlation_id, trace_parent, headers
     */
    private const int FIELD_COUNT = 9;

    /**
     * Atomic enqueue: capacity guard → duplicate-id backstop → XADD.
     *
     * KEYS[1] stream, KEYS[2] live-id set
     * ARGV[1] capacity ('0' = unbounded), ARGV[2..10] the nine fields
     * returns 1 claimed-ok / -1 capacity exceeded / -2 duplicate job id
     */
    private const string LUA_ENQUEUE = <<<'LUA'
        if ARGV[1] ~= '0' and redis.call('XLEN', KEYS[1]) >= tonumber(ARGV[1]) then
            return -1
        end
        if redis.call('SADD', KEYS[2], ARGV[2]) == 0 then
            return -2
        end
        redis.call('XADD', KEYS[1], '*',
            'job_id', ARGV[2], 'job_type', ARGV[3], 'payload', ARGV[4],
            'available_at', ARGV[5], 'priority', ARGV[6], 'attempt', ARGV[7],
            'correlation_id', ARGV[8], 'trace_parent', ARGV[9], 'headers', ARGV[10])
        return 1
        LUA;

    /**
     * Atomic claim: pick the best due entry (priority DESC, available_at
     * ASC as order-preserving 20-char strings, entry id ASC), XDEL + SREM
     * it, return its flat field list. Returns nil when no entry is due yet.
     *
     * KEYS[1] stream, KEYS[2] live-id set, ARGV[1] now (order-preserving
     * 20 chars — see {@see JobRowCodec::encodeNano()})
     */
    private const string LUA_DEQUEUE = <<<'LUA'
        local entries = redis.call('XRANGE', KEYS[1], '-', '+')
        local best = false
        local bestId = false
        for i = 1, #entries do
            local fields = entries[i][2]
            local avail = fields[8]
            -- Well-formed shape: 20 digits, or '-' plus 19 digits. Anything
            -- else is corrupt and MUST stay claimable: a short positive value
            -- (e.g. '20') sorts above every 20-char 'now' string, so without
            -- this guard the entry is never selected, never XDEL'd and never
            -- reported, wedging the stream forever (issue #280).
            local wellFormed = false
            if type(avail) == 'string' and string.len(avail) == 20 then
                if string.match(avail, '^%d+$') then
                    wellFormed = true
                elseif string.sub(avail, 1, 1) == '-' and string.match(string.sub(avail, 2), '^%d+$') then
                    wellFormed = true
                end
            end
            if (not wellFormed) or avail <= ARGV[1] then
                if best == false then
                    best = fields
                    bestId = entries[i][1]
                else
                    local prio = tonumber(fields[10])
                    local bestPrio = tonumber(best[10])
                    -- Tie-break note: XRANGE returns entries in ascending
                    -- stream-id order, so the FIRST entry seen with the best
                    -- (priority, available_at) pair already carries the
                    -- smallest id. A lexicographic id comparison here would
                    -- not preserve that order ('...-10' < '...-2' byte-wise)
                    -- and could replace the earliest entry with a later one
                    -- (issue #313) — so only strictly-better pairs replace.
                    if prio > bestPrio
                        or (prio == bestPrio and fields[8] < best[8])
                    then
                        best = fields
                        bestId = entries[i][1]
                    end
                end
            end
        end
        if best == false then
            return false
        end
        redis.call('XDEL', KEYS[1], bestId)
        redis.call('SREM', KEYS[2], best[2])
        return best
        LUA;

    private string $streamKey;

    private string $idsKey;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param \Redis                 $redis   connected phpredis client (any database)
     * @param string                 $name    queue name (also the stream name); grammar-validated,
     *                                        so raw bytes never reach the keyspace — see {@see assertValidName()}
     * @param null|(\Closure(): int) $clock   now source for dequeue() without an explicit argument
     *                                        (default: realtime nanoseconds)
     * @param null|int               $maxSize optional capacity guard (XLEN check per enqueue)
     */
    public function __construct(
        private \Redis $redis,
        string $name = 'default',
        ?\Closure $clock = null,
        private ?int $maxSize = null,
    ) {
        $this->assertValidName($name);
        if ($maxSize !== null && $maxSize < 1) {
            throw new \InvalidArgumentException('Job queue capacity must be positive.');
        }
        $this->streamKey = self::PREFIX . $name;
        $this->idsKey = self::PREFIX . $name . ':ids';
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    #[\Override]
    public function enqueue(JobEnvelope $job): void
    {
        // Storage-boundary re-assertion of the domain's job-id grammar —
        // same defensive posture as PdoJobQueue (Regresi P-4, issue #170):
        // the id also travels into the live-id SET, so a value that ever
        // slips past the envelope's own validation fails loudly here.
        Identifier::assertOpaqueId($job->jobId, 'job ID');
        $result = $this->redis->eval(
            self::LUA_ENQUEUE,
            [
                $this->streamKey,
                $this->idsKey,
                $this->maxSize !== null ? (string) $this->maxSize : '0',
                $job->jobId,
                $job->jobType,
                JobRowCodec::encodePayload($job->payload),
                JobRowCodec::encodeNano($job->availableAtUnixNano),
                (string) $job->priority,
                (string) $job->attempt,
                $job->correlationId ?? '',
                $job->traceParent ?? '',
                JobRowCodec::encodePayload($job->headers),
            ],
            2,
        );
        if ($result === 1) {
            return;
        }
        if ($result === -1) {
            throw new \OverflowException('Job queue capacity exceeded.');
        }
        if ($result === -2) {
            throw new RedisJobQueueException("Job '{$job->jobId}' is already queued.");
        }

        throw new RedisJobQueueException('Redis job queue returned an unexpected enqueue result.');
    }

    #[\Override]
    public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
    {
        $now = $nowUnixNano ?? ($this->clock)();
        $fields = $this->redis->eval(
            self::LUA_DEQUEUE,
            [$this->streamKey, $this->idsKey, JobRowCodec::encodeNano($now)],
            2,
        );
        if ($fields === false || $fields === null) {
            return null;
        }
        if (!is_array($fields) || count($fields) < self::FIELD_COUNT * 2) {
            throw new RedisJobQueueException('Redis job queue returned an unexpected dequeue result.');
        }

        return JobRowCodec::hydrate($this->rowFromFields($fields));
    }

    #[\Override]
    public function size(): int
    {
        // phpredis stub case (xlen) — method dispatch is case-insensitive.
        $size = $this->redis->xlen($this->streamKey);
        if (!is_int($size) || $size < 0) {
            throw new RedisJobQueueException('Redis job queue returned an unexpected size result.');
        }

        return $size;
    }

    /**
     * Read-only inspection of the head of the stream (queue:failed tooling):
     * hydrates up to $max entries WITHOUT claiming them — the entries stay
     * queued and the live-id set untouched.
     *
     * phpredis xRange replies as [entry-id => [field => value]] — an
     * associative field map per entry, the shape XRANGE itself defines.
     *
     * @param int $max entry budget (>= 1)
     *
     * @return list<JobEnvelope> oldest-first; never claims, never deletes
     */
    public function peek(int $max = 100): array
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('Peek budget must be positive.');
        }
        // phpredis xRange: an empty (or missing) stream is an empty array;
        // false is a command failure — both non-array shapes are loud here,
        // mirroring size()'s is_int() gate.
        $entries = $this->redis->xrange($this->streamKey, '-', '+', $max);
        if (!is_array($entries)) {
            throw new RedisJobQueueException('Redis job queue returned an unexpected peek result.');
        }
        $jobs = [];
        foreach ($entries as $fields) {
            if (!is_array($fields) || count($fields) < self::FIELD_COUNT) {
                throw new RedisJobQueueException('Redis job queue returned an unexpected peek entry.');
            }
            $correlation = $fields['correlation_id'] ?? null;
            $traceParent = $fields['trace_parent'] ?? null;
            $jobs[] = JobRowCodec::hydrate([
                'job_id' => $fields['job_id'] ?? null,
                'job_type' => $fields['job_type'] ?? null,
                'payload' => $fields['payload'] ?? null,
                'available_at' => JobRowCodec::decodeNano(JobRowCodec::str($fields['available_at'] ?? null)),
                'priority' => $fields['priority'] ?? null,
                'attempt' => $fields['attempt'] ?? null,
                'correlation_id' => $correlation === '' ? null : $correlation,
                'trace_parent' => $traceParent === '' ? null : $traceParent,
                'headers' => $fields['headers'] ?? null,
            ]);
        }

        return $jobs;
    }

    /**
     * The flat XADD field list the dequeue Lua script returns — alternating
     * name and value. Values sit at the ODD offsets (1, 3, 5, ...): the list
     * begins with the field NAME, so index 0 is 'job_id' the literal string.
     * Values pass through JobRowCodec::str() for the same defensive
     * narrowing the PDO adapter applies to driver rows.
     *
     * @param array<array-key, mixed> $fields flat field list (name, value, name, value, ...)
     *
     * @return array<string, mixed> JobRowCodec row shape
     */
    private function rowFromFields(array $fields): array
    {
        // Empty string is the null sentinel for the two optional columns:
        // the envelope grammar forbids empty correlation/trace values, so ''
        // can never be a real value round-tripped through storage.
        $correlation = JobRowCodec::str($fields[13] ?? null);
        $traceParent = JobRowCodec::str($fields[15] ?? null);

        return [
            'job_id' => JobRowCodec::str($fields[1] ?? null),
            'job_type' => JobRowCodec::str($fields[3] ?? null),
            'payload' => JobRowCodec::str($fields[5] ?? null),
            'available_at' => JobRowCodec::decodeNano(JobRowCodec::str($fields[7] ?? null)),
            'priority' => JobRowCodec::str($fields[9] ?? null),
            'attempt' => JobRowCodec::str($fields[11] ?? null),
            'correlation_id' => $correlation !== '' ? $correlation : null,
            'trace_parent' => $traceParent !== '' ? $traceParent : null,
            'headers' => JobRowCodec::str($fields[17] ?? null),
        ];
    }

    /**
     * Storage-boundary validation of the queue name: the grammar keeps raw
     * bytes (spaces, colons, binary) away from the Redis keyspace, the
     * same posture as PdoJobQueue's table-name assertion. The name also
     * names the failed-job stream in `bin/zef queue:failed` wiring.
     *
     * The alphabet is checked with strspn instead of a regex character
     * range on purpose (Sonar php:S5867): the intent is an exact ASCII
     * allow-list, not a Unicode character class, and the explicit list
     * makes that unambiguous.
     */
    private function assertValidName(string $name): void
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';
        if ($name === '' || strlen($name) > 64 || strspn($name, $alphabet) !== strlen($name)) {
            throw new \InvalidArgumentException('Queue name must match [A-Za-z0-9._-]{1,64}.');
        }
    }
}
