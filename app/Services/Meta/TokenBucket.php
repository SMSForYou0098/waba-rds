<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Redis;

class TokenBucket
{
    public function __construct(
        private readonly int $capacity = 18,
        private readonly int $refillPerSec = 18,
    ) {}

    /**
     * @return int 0 when a token is acquired, otherwise ms to wait.
     */
    public function acquire(string $phoneNumberId): int
    {
        $key = 'meta:tb:'.$phoneNumberId;
        $nowMs = (int) floor(microtime(true) * 1000);

        $lua = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'ts')
local tokens = tonumber(data[1])
local ts = tonumber(data[2])

if tokens == nil then
  tokens = capacity
  ts = now
end

local elapsed_ms = math.max(0, now - ts)
local refilled = (elapsed_ms / 1000.0) * refill
tokens = math.min(capacity, tokens + refilled)
ts = now

local wait_ms = 0
if tokens >= 1 then
  tokens = tokens - 1
else
  local needed = 1 - tokens
  wait_ms = math.ceil((needed / refill) * 1000)
end

redis.call('HMSET', key, 'tokens', tokens, 'ts', ts)
redis.call('EXPIRE', key, 60)
return wait_ms
LUA;

        return (int) Redis::eval($lua, 1, $key, $this->capacity, $this->refillPerSec, $nowMs);
    }
}
