-- token_bucket.lua: KEYS[1] = bucket; ARGV = capacity, refill per second, weight
-- Returns 0 if the weight was taken, else the milliseconds to wait.
local cap, rate, w = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])
local t = redis.call('TIME'); local now = t[1] + t[2] / 1e6
local b = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
local tokens, ts = tonumber(b[1]) or cap, tonumber(b[2]) or now
tokens = math.min(cap, tokens + (now - ts) * rate)
if tokens < w then return math.ceil((w - tokens) / rate * 1000) end
redis.call('HSET', KEYS[1], 'tokens', tokens - w, 'ts', now)
redis.call('EXPIRE', KEYS[1], math.ceil(cap / rate) + 1)
return 0
