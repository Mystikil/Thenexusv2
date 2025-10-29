#include "otpch.h"

#include "common/diagnostics.h"

#include <atomic>

namespace diagnostics {
namespace {
std::atomic<bool> sqlTraceEnabled{false};
std::atomic<bool> traceStartupEnabled{false};
} // namespace

bool isSqlTraceEnabled()
{
    return sqlTraceEnabled.load(std::memory_order_relaxed);
}

void setSqlTraceEnabled(bool enabled)
{
    sqlTraceEnabled.store(enabled, std::memory_order_relaxed);
}

bool isTraceStartupEnabled()
{
    return traceStartupEnabled.load(std::memory_order_relaxed);
}

void setTraceStartupEnabled(bool enabled)
{
    traceStartupEnabled.store(enabled, std::memory_order_relaxed);
}

} // namespace diagnostics
