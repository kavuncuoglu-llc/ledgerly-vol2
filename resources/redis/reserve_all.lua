-- reserve_all.lua: KEYS = budget counters; ARGV[1] = estimate; ARGV[2..] = limit, ttl pairs per key
-- Reserves the estimate on every counter, or on none. Returns 0 on success, else the 1-based index of the full budget.
local est = tonumber(ARGV[1])
for i, key in ipairs(KEYS) do
  local limit = tonumber(ARGV[2 * i])
  if (tonumber(redis.call('GET', key) or '0') + est) > limit then return i end
end
for i, key in ipairs(KEYS) do
  redis.call('INCRBY', key, est)
  redis.call('EXPIRE', key, tonumber(ARGV[2 * i + 1]), 'NX')      -- set the period's expiry once (Redis 7+)
end
return 0
