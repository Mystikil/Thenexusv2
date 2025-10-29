#pragma once

#include <atomic>

namespace diagnostics {

        void setTraceStartupEnabled(bool enabled);
        bool isTraceStartupEnabled();

        void setSqlTraceEnabled(bool enabled);
        bool isSqlTraceEnabled();

} // namespace diagnostics

