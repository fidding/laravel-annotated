<?php

namespace Illuminate\Cache;

use Illuminate\Support\InteractsWithTime;
use Illuminate\Contracts\Cache\Repository as Cache;

class RateLimiter
{
    use InteractsWithTime;

    /**
     * The cache store implementation.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Create a new rate limiter instance.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @return void
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return bool
     */
    public function tooManyAttempts($key, $maxAttempts)
    {
        if ($this->attempts($key) >= $maxAttempts) { # 取缓存key判断是否大于限制值
            if ($this->cache->has($key.':timer')) { # 检测key缓存计时器是否存在
                return true;
            }

            $this->resetAttempts($key); # 移除key相当于重置
        }

        return false;
    }

    /**
     * Increment the counter for a given key for a given decay time.
     *
     * @param  string  $key
     * @param  int  $decaySeconds
     * @return int
     */
    public function hit($key, $decaySeconds = 60)
    {
        $this->cache->add(
            $key.':timer', $this->availableAt($decaySeconds), $decaySeconds # 添加计时器并设置过期衰变时间
        );

        $added = $this->cache->add($key, 0, $decaySeconds); # 添加key并设置过期衰变时间

        $hits = (int) $this->cache->increment($key); # 计数器+1

        if (! $added && $hits == 1) {
            $this->cache->put($key, 1, $decaySeconds);
        }

        return $hits;
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function attempts($key)
    {
        return $this->cache->get($key, 0); # 从缓存获取key的值
    }

    /**
     * Reset the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function resetAttempts($key)
    {
        return $this->cache->forget($key);
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return int
     */
    public function retriesLeft($key, $maxAttempts)
    {
        $attempts = $this->attempts($key);

        return $maxAttempts - $attempts; # 限制次数-当前次数
    }

    /**
     * Clear the hits and lockout timer for the given key.
     *
     * @param  string  $key
     * @return void
     */
    public function clear($key)
    {
        $this->resetAttempts($key);

        $this->cache->forget($key.':timer');
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     *
     * @param  string  $key
     * @return int
     */
    public function availableIn($key)
    {
        return $this->cache->get($key.':timer') - $this->currentTime(); # 获取计时器-当前时间
    }
}
