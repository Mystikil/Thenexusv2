#include "utils/DiagnosticsConfig.h"

namespace {

        std::atomic_bool g_traceStartupEnabled{false};
        std::atomic_bool g_sqlTraceEnabled{false};

} // namespace

namespace diagnostics {

        void setTraceStartupEnabled(bool enabled) {
                g_traceStartupEnabled.store(enabled, std::memory_order_relaxed);
        }

        bool isTraceStartupEnabled() {
                return g_traceStartupEnabled.load(std::memory_order_relaxed);
        }

        void setSqlTraceEnabled(bool enabled) {
                g_sqlTraceEnabled.store(enabled, std::memory_order_relaxed);
        }

        bool isSqlTraceEnabled() {
                return g_sqlTraceEnabled.load(std::memory_order_relaxed);
        }

} // namespace diagnostics

