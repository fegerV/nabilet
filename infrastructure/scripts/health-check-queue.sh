#!/bin/bash

# NABILET Core Queue Worker Health Check Script
#
# Production health check for queue workers that:
# - Verifies worker process is running
# - Checks queue depth and processing latency
# - Detects stuck workers (no jobs processed in X minutes)
# - Integrates with Prometheus/Grafana monitoring
# - Triggers alerts when thresholds exceeded
#
# Usage:
#   ./health-check-queue.sh [--prometheus]
#
# Exit codes:
#   0 - Healthy
#   1 - Warning (degraded performance)
#   2 - Critical (worker down or stuck)

set -euo pipefail

# Configuration
WORKER_SERVICE="nabile-core-queue"
MAX_QUEUE_DEPTH="${MAX_QUEUE_DEPTH:-1000}"
MAX_JOB_AGE_MINUTES="${MAX_JOB_AGE_MINUTES:-5}"
MAX_FAILED_JOBS="${MAX_FAILED_JOBS:-10}"
REDIS_HOST="${REDIS_HOST:-localhost}"
REDIS_PORT="${REDIS_PORT:-6379}"
PROMETHEUS_MODE=false

# Parse arguments
if [[ "${1:-}" == "--prometheus" ]]; then
    PROMETHEUS_MODE=true
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

STATUS=0
WARNINGS=()
CRITICALS=()

log_check() {
    echo -e "${GREEN}[CHECK]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
    WARNINGS+=("$1")
}

log_critical() {
    echo -e "${RED}[CRITICAL]${NC} $1"
    CRITICALS+=("$1")
    STATUS=2
}

# Check 1: Is systemd service running?
check_service_running() {
    log_check "Checking if queue worker service is running..."
    
    if systemctl is-active --quiet "$WORKER_SERVICE" 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Service ${WORKER_SERVICE} is active"
        return 0
    else
        # Try alternative check - process based
        if pgrep -f "artisan queue:work" > /dev/null 2>&1; then
            echo -e "${GREEN}✓${NC} Queue worker process found (not managed by systemd)"
            return 0
        else
            log_critical "Queue worker service '${WORKER_SERVICE}' is not running"
            return 1
        fi
    fi
}

# Check 2: Queue depth
check_queue_depth() {
    log_check "Checking queue depths..."
    
    if ! command -v redis-cli &> /dev/null; then
        log_warning "redis-cli not found, skipping queue depth check"
        return 0
    fi
    
    QUEUES=("high" "default" "low")
    
    for QUEUE in "${QUEUES[@]}"; do
        DEPTH=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LLEN "queues:${QUEUE}" 2>/dev/null || echo "0")
        
        if [[ "$DEPTH" -gt "$MAX_QUEUE_DEPTH" ]]; then
            log_critical "Queue '${QUEUE}' depth (${DEPTH}) exceeds threshold (${MAX_QUEUE_DEPTH})"
        elif [[ "$DEPTH" -gt $((MAX_QUEUE_DEPTH / 2)) ]]; then
            log_warning "Queue '${QUEUE}' depth (${DEPTH}) is above 50% of threshold"
        else
            echo -e "${GREEN}✓${NC} Queue '${QUEUE}': ${DEPTH} jobs"
        fi
    done
}

# Check 3: Failed jobs count
check_failed_jobs() {
    log_check "Checking failed jobs..."
    
    if ! command -v redis-cli &> /dev/null; then
        log_warning "redis-cli not found, skipping failed jobs check"
        return 0
    fi
    
    FAILED_COUNT=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LLEN "queues:failed" 2>/dev/null || echo "0")
    
    if [[ "$FAILED_COUNT" -gt "$MAX_FAILED_JOBS" ]]; then
        log_critical "Failed jobs queue (${FAILED_COUNT}) exceeds threshold (${MAX_FAILED_JOBS})"
    elif [[ "$FAILED_COUNT" -gt $((MAX_FAILED_JOBS / 2)) ]]; then
        log_warning "Failed jobs queue (${FAILED_COUNT}) is above 50% of threshold"
    else
        echo -e "${GREEN}✓${NC} Failed jobs: ${FAILED_COUNT}"
    fi
}

# Check 4: Oldest job age
check_job_age() {
    log_check "Checking oldest job age..."
    
    if ! command -v redis-cli &> /dev/null; then
        log_warning "redis-cli not found, skipping job age check"
        return 0
    fi
    
    # Get timestamp of oldest job in default queue
    OLDEST_JOB=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LINDEX "queues:default" 0 2>/dev/null || echo "")
    
    if [[ -n "$OLDEST_JOB" ]]; then
        # Extract timestamp from job payload (JSON)
        TIMESTAMP=$(echo "$OLDEST_JOB" | grep -o '"timestamp":[0-9]*' | cut -d: -f2 || echo "0")
        
        if [[ "$TIMESTAMP" -gt 0 ]]; then
            NOW=$(date +%s)
            AGE_SECONDS=$((NOW - TIMESTAMP))
            AGE_MINUTES=$((AGE_SECONDS / 60))
            
            if [[ "$AGE_MINUTES" -gt "$MAX_JOB_AGE_MINUTES" ]]; then
                log_critical "Oldest job in queue is ${AGE_MINUTES} minutes old (threshold: ${MAX_JOB_AGE_MINUTES})"
            else
                echo -e "${GREEN}✓${NC} Oldest job: ${AGE_MINUTES} minutes old"
            fi
        fi
    else
        echo -e "${GREEN}✓${NC} Queue is empty (no pending jobs)"
    fi
}

# Check 5: Recent job processing rate
check_processing_rate() {
    log_check "Checking recent job processing rate..."
    
    # This would require metrics from Redis or database
    # For now, just verify the worker logs show recent activity
    if journalctl -u "$WORKER_SERVICE" --since "5 minutes ago" --no-pager -q 2>/dev/null | grep -q "Processing\|Processed"; then
        echo -e "${GREEN}✓${NC} Worker has processed jobs in the last 5 minutes"
    else
        if systemctl is-active --quiet "$WORKER_SERVICE" 2>/dev/null || pgrep -f "artisan queue:work" > /dev/null 2>&1; then
            log_warning "No job processing activity detected in the last 5 minutes"
        fi
    fi
}

# Output Prometheus metrics (optional)
output_prometheus_metrics() {
    if [[ "$PROMETHEUS_MODE" != "true" ]]; then
        return
    fi
    
    echo ""
    echo "# HELP nabile_queue_worker_up Queue worker status (1=up, 0=down)"
    echo "# TYPE nabile_queue_worker_up gauge"
    
    if systemctl is-active --quiet "$WORKER_SERVICE" 2>/dev/null || pgrep -f "artisan queue:work" > /dev/null 2>&1; then
        echo "nabile_queue_worker_up 1"
    else
        echo "nabile_queue_worker_up 0"
    fi
    
    echo ""
    echo "# HELP nabile_queue_depth Current queue depth"
    echo "# TYPE nabile_queue_depth gauge"
    
    for QUEUE in high default low; do
        DEPTH=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LLEN "queues:${QUEUE}" 2>/dev/null || echo "0")
        echo "nabile_queue_depth{queue=\"${QUEUE}\"} ${DEPTH}"
    done
    
    echo ""
    echo "# HELP nabile_failed_jobs_count Number of failed jobs"
    echo "# TYPE nabile_failed_jobs_count gauge"
    
    FAILED_COUNT=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LLEN "queues:failed" 2>/dev/null || echo "0")
    echo "nabile_failed_jobs_count ${FAILED_COUNT}"
}

# Main execution
main() {
    echo "=================================="
    echo "NABILET Core Queue Health Check"
    echo "=================================="
    echo ""
    
    check_service_running
    check_queue_depth
    check_failed_jobs
    check_job_age
    check_processing_rate
    
    echo ""
    echo "=================================="
    echo "Summary"
    echo "=================================="
    
    if [[ ${#CRITICALS[@]} -gt 0 ]]; then
        echo -e "${RED}CRITICAL ISSUES:${NC}"
        for issue in "${CRITICALS[@]}"; do
            echo "  • $issue"
        done
        STATUS=2
    fi
    
    if [[ ${#WARNINGS[@]} -gt 0 ]]; then
        echo -e "${YELLOW}WARNINGS:${NC}"
        for warning in "${WARNINGS[@]}"; do
            echo "  • $warning"
        done
        if [[ $STATUS -eq 0 ]]; then
            STATUS=1
        fi
    fi
    
    if [[ $STATUS -eq 0 ]]; then
        echo -e "${GREEN}All checks passed ✓${NC}"
    fi
    
    output_prometheus_metrics
    
    exit $STATUS
}

main
