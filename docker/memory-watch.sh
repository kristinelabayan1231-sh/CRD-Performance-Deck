#!/bin/sh
# Container memory in the host's logs (Render kills the instance at 512 MB, and its graphs miss short peaks).
# One line every 10 minutes; while memory is over MEMORY_WATCH_MB (default 400), a line every 15 seconds
# with the five biggest processes, so a crash in the logs comes with what was running.

limit_mb=${MEMORY_WATCH_MB:-400}
interval=${MEMORY_WATCH_SECONDS:-15}
proc=${MEMORY_WATCH_PROC:-/proc}
cgroup=${MEMORY_WATCH_CGROUP:-/sys/fs/cgroup/memory.current}
[ -r "$cgroup" ] || cgroup=/sys/fs/cgroup/memory/memory.usage_in_bytes
[ -r "$cgroup" ] || exit 0

checks=0
while sleep "$interval"; do
    mb=$(( $(cat "$cgroup") / 1048576 ))
    checks=$((checks + 1))

    if [ "$mb" -ge "$limit_mb" ]; then
        top=$(
            for dir in "$proc"/[0-9]*; do
                rss=$(sed -n 's/^VmRSS:[^0-9]*\([0-9]*\).*/\1/p' "$dir/status" 2>/dev/null)
                [ -n "$rss" ] || continue
                echo "$((rss / 1024)) MB $(tr '\0' ' ' < "$dir/cmdline" 2>/dev/null | cut -c1-90)"
            done | sort -rn | head -5 | tr '\n' ';'
        )
        echo "memory-watch: ${mb} MB (over ${limit_mb}) | ${top}"
    elif [ $((checks % 40)) -eq 0 ]; then
        echo "memory-watch: ${mb} MB"
    fi
done
