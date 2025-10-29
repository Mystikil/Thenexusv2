#include "otpch.h"

#include "utils/StartupProbe.h"

#include "utils/Logger.h"

#include <atomic>
#include <chrono>
#include <mutex>
#include <string>
#include <string_view>
#include <thread>

#include <fmt/format.h>

namespace {

std::atomic<bool> g_running{false};
std::atomic<std::chrono::milliseconds> g_threshold{std::chrono::milliseconds{10000}};
std::thread g_thread;
std::mutex g_mutex;
std::string g_lastPhase;
std::chrono::steady_clock::time_point g_lastTick;
bool g_hasTick = false;

constexpr std::chrono::seconds kPollInterval{2};

void watchdogLoop()
{
    while (g_running.load(std::memory_order_relaxed)) {
        std::this_thread::sleep_for(kPollInterval);
        if (!g_running.load(std::memory_order_relaxed)) {
            break;
        }

        std::string phase;
        std::chrono::steady_clock::time_point lastTick;
        bool hasTick = false;
        {
            std::lock_guard<std::mutex> lock(g_mutex);
            phase = g_lastPhase;
            lastTick = g_lastTick;
            hasTick = g_hasTick;
        }

        if (!hasTick) {
            continue;
        }

        const auto now = std::chrono::steady_clock::now();
        const auto elapsed = std::chrono::duration_cast<std::chrono::milliseconds>(now - lastTick);
        const auto threshold = g_threshold.load(std::memory_order_relaxed);
        if (elapsed < threshold) {
            continue;
        }

        Logger::instance().warn(fmt::format("WATCHDOG: stalled at {} ({} ms)", phase, elapsed.count()));
        Logger::instance().flush();
    }
}

} // namespace

void StartupProbe::initialize()
{
    bool expected = false;
    if (!g_running.compare_exchange_strong(expected, true)) {
        return;
    }

    {
        std::lock_guard<std::mutex> lock(g_mutex);
        g_lastTick = std::chrono::steady_clock::now();
        g_lastPhase = "(none)";
        g_hasTick = true;
    }

    g_thread = std::thread(watchdogLoop);
}

void StartupProbe::shutdown()
{
    bool expected = true;
    if (!g_running.compare_exchange_strong(expected, false)) {
        return;
    }

    if (g_thread.joinable()) {
        g_thread.join();
    }
}

void StartupProbe::mark(const char* phase)
{
    const auto now = std::chrono::steady_clock::now();
    std::string_view phaseView = phase != nullptr ? std::string_view{phase} : std::string_view{};
    if (phaseView.empty()) {
        phaseView = "(none)";
    }

    std::string phaseName{phaseView};

    std::chrono::milliseconds delta{0};
    {
        std::lock_guard<std::mutex> lock(g_mutex);
        if (g_hasTick) {
            delta = std::chrono::duration_cast<std::chrono::milliseconds>(now - g_lastTick);
        }
        g_lastTick = now;
        g_lastPhase = phaseName;
        g_hasTick = true;
    }

    Logger::instance().info(fmt::format("PHASE {} (+ {} ms)", phaseName, delta.count()));
    Logger::instance().flush();
}

void StartupProbe::setWatchdogThreshold(std::chrono::milliseconds ms)
{
    if (ms <= std::chrono::milliseconds::zero()) {
        ms = std::chrono::milliseconds{10000};
    }

    g_threshold.store(ms, std::memory_order_relaxed);
}
