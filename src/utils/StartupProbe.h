#pragma once

#include <chrono>

class StartupProbe
{
public:
    static void initialize();
    static void shutdown();
    static void mark(const char* phase);
    static void setWatchdogThreshold(std::chrono::milliseconds ms);
};
